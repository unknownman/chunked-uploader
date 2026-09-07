<?php

declare(strict_types=1);

// File: tests/Unit/RedisMetadataRepositoryTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Drivers\Metadata\RedisMetadataRepository;
use Resumable\ChunkedUploader\Core\Exceptions\MetadataException;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\Support\FakeRedis;
use Resumable\ChunkedUploader\Tests\TestCase;

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
    public function test_clean_expired_purges_stale_abandoned_records_via_scan(): void
    {
        $this->repository->save(new UploadState('stale', 3, 300, 'a.bin', [0], false));
        $this->backdate('repo:', 'stale', 7200);
        $this->repository->save(new UploadState('fresh', 3, 300, 'b.bin', [0], false));

        $removed = $this->repository->cleanExpired(3600);

        self::assertSame(1, $removed);
        self::assertNull($this->repository->get('stale'));
        self::assertNotNull($this->repository->get('fresh'));
    }

    #[Test]
    public function test_clean_expired_never_purges_completed_uploads(): void
    {
        $this->repository->save(new UploadState('finished', 2, 200, 'done.bin', [0, 1], true));
        $this->backdate('repo:', 'finished', 7200);

        self::assertSame(0, $this->repository->cleanExpired(3600));
        self::assertNotNull($this->repository->get('finished'));
    }

    #[Test]
    public function test_clean_expired_returns_zero_when_nothing_is_stale(): void
    {
        $this->repository->save(new UploadState('active', 3, 300, 'a.bin', [0], false));

        self::assertSame(0, $this->repository->cleanExpired(3600));
        self::assertNotNull($this->repository->get('active'));
    }

    #[Test]
    public function test_clean_expired_ignores_keys_it_cannot_parse_or_own(): void
    {
        $this->repository->save(new UploadState('stale', 2, 200, 'a.bin', [0], false));
        $this->backdate('repo:', 'stale', 7200);
        $foreign = 'repo:' . hash('sha256', 'foreign');
        $this->redis->data[$foreign] = 'not-json';

        self::assertSame(1, $this->repository->cleanExpired(3600));
        self::assertArrayHasKey($foreign, $this->redis->data, 'A key that is not JSON must be left untouched.');
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

    private function backdate(string $prefix, string $identifier, int $seconds): void
    {
        $key = $prefix . hash('sha256', $identifier);
        $state = json_decode($this->redis->data[$key], true);
        $state['updatedAt'] = time() - $seconds;
        $this->redis->data[$key] = (string) json_encode($state);
    }
}
