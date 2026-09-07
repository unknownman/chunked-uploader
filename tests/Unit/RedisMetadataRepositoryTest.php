<?php

declare(strict_types=1);

// File: tests/Unit/RedisMetadataRepositoryTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Drivers\Metadata\RedisMetadataRepository;
use Resumable\ChunkedUploader\Core\Exceptions\MetadataException;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\TestCase;
use Redis;

final class RedisMetadataRepositoryTest extends TestCase
{
    private FakeRedis $redis;
    private RedisMetadataRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new FakeRedis();
        $this->repository = new RedisMetadataRepository($this->redis, 'repo:', null);
    }

    #[Test]
    public function test_get_returns_null_for_a_never_seen_identifier(): void
    {
        self::assertNull($this->repository->get('missing'));
    }

    #[Test]
    public function test_it_round_trips_a_state_through_json(): void
    {
        $state = new UploadState('rt', 3, 300, 'seed.bin', [0], false, null);
        $this->repository->save($state);

        $loaded = $this->repository->get('rt');

        self::assertNotNull($loaded);
        self::assertSame('rt', $loaded->identifier);
        self::assertSame([0], $loaded->uploadedChunks);
        self::assertSame(3, $loaded->totalChunks);
    }

    #[Test]
    public function test_delete_removes_the_key_and_is_idempotent(): void
    {
        $this->repository->save(new UploadState('gone', 1, 100, 'a.txt', []));
        $this->repository->delete('gone');
        $this->repository->delete('gone');

        self::assertNull($this->repository->get('gone'));
    }

    #[Test]
    public function test_mark_chunk_as_uploaded_is_atomic_and_converges_under_reentrant_requests(): void
    {
        $this->repository->save(new UploadState('conv', 3, 300, 'big.bin', [], false));

        $first = $this->repository->markChunkAsUploaded('conv', 2);
        $second = $this->repository->markChunkAsUploaded('conv', 0);
        $third = $this->repository->markChunkAsUploaded('conv', 2); // duplicate

        self::assertSame([2], $first->uploadedChunks);
        self::assertSame([0, 2], $second->uploadedChunks);
        self::assertSame([0, 2], $third->uploadedChunks);
        self::assertSame([0, 2], $this->repository->get('conv')?->uploadedChunks);
    }

    #[Test]
    public function test_upload_completes_atomically_when_the_last_index_lands(): void
    {
        $this->repository->save(new UploadState('done', 2, 200, 'two.bin', [], false));

        $partial = $this->repository->markChunkAsUploaded('done', 0);
        self::assertFalse($partial->isCompleted);

        $complete = $this->repository->markChunkAsUploaded('done', 1);
        self::assertTrue($complete->isCompleted);
    }

    #[Test]
    public function test_mark_chunk_raises_metadata_error_for_an_unknown_upload(): void
    {
        $this->expectException(MetadataException::class);
        $this->repository->markChunkAsUploaded('never', 0);
    }

    #[Test]
    public function test_clean_expired_is_a_safe_no_op_without_scan_support(): void
    {
        // Redis keys carry their own TTL, so the repository does not implement a
        // destructive sweep; the API exists for interface completeness and must
        // not throw.
        self::assertSame(0, $this->repository->cleanExpired(3600));
    }

    #[Test]
    public function test_it_applies_a_per_key_expiry_when_a_ttl_is_configured(): void
    {
        $ttlRepo = new RedisMetadataRepository($this->redis, 'ttl:', 120);
        $ttlRepo->save(new UploadState('expiring', 1, 10, 'a.txt', []));

        $key = 'ttl:' . hash('sha256', 'expiring');
        self::assertSame(120, $this->redis->expirations[$key]);
    }

    #[Test]
    public function test_progress_tracking_methods_are_supported(): void
    {
        $state = new UploadState('progress', 4, 400, 'p.bin', [0, 1], false);
        self::assertSame(50.0, $this->repository->getPercentage($state));
        self::assertFalse($this->repository->isComplete($state));
        self::assertSame([2, 3], $this->repository->getMissingChunkIndices($state));
    }
}

/**
 * Minimal in-memory Redis double implementing the exact surface the repository
 * relies on: get/set/del/expire and a Lua-free eval that mimics the atomically
 * appended-chunk script.
 *
 * @extends \Redis
 */
final class FakeRedis extends Redis
{
    /** @var array<string, string> */
    public array $data = [];

    /** @var array<string, int> */
    public array $expirations = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? false;
    }

    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        $this->data[$key] = (string) $value;
        return true;
    }

    public function del(array|string $keys, string ...$other_keys): int
    {
        $all = array_merge((array) $keys, $other_keys);
        $count = 0;
        foreach ($all as $key) {
            if (isset($this->data[$key])) {
                unset($this->data[$key], $this->expirations[$key]);
                $count++;
            }
        }
        return $count;
    }

    public function expire(string $key, int $timeout, ?string $mode = null): bool
    {
        $this->expirations[$key] = $timeout;
        return true;
    }

    public function script(string $command, mixed ...$args): mixed
    {
        return sha1((string) ($args[0] ?? ''));
    }

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $key = $args[0] ?? null;
        $chunkIndex = (int) ($args[1] ?? -1);

        if (!is_string($key) || !isset($this->data[$key])) {
            return false;
        }

        $state = json_decode($this->data[$key], true);
        if (!is_array($state)) {
            return false;
        }

        $uploaded = array_map('intval', $state['uploaded'] ?? []);
        if (!in_array($chunkIndex, $uploaded, true)) {
            $uploaded[] = $chunkIndex;
            sort($uploaded, SORT_NUMERIC);
        }

        $state['uploaded'] = $uploaded;
        $state['isCompleted'] = count($uploaded) === (int) $state['totalChunks'];
        $state['updatedAt'] = time();

        $encoded = json_encode($state);
        $this->data[$key] = (string) $encoded;

        return $encoded;
    }
}
