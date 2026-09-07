<?php

declare(strict_types=1);

// File: tests/Unit/PdoMetadataRepositoryTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Drivers\Metadata\PdoMetadataRepository;
use Resumable\ChunkedUploader\Core\Exceptions\MetadataException;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\Support\FakePdo;
use Resumable\ChunkedUploader\Tests\TestCase;

final class PdoMetadataRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoMetadataRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->repository = new PdoMetadataRepository($this->pdo, 'chunked_upload_states');
        $this->repository->ensureSchema();
    }

    #[Test]
    public function test_it_round_trips_an_upload_state(): void
    {
        $state = $this->state('roundtrip', [0, 1]);
        $this->repository->save($state);

        $loaded = $this->repository->get('roundtrip');

        self::assertNotNull($loaded);
        self::assertSame('roundtrip', $loaded->identifier);
        self::assertSame(4, $loaded->totalChunks);
        self::assertSame(400, $loaded->totalSize);
        self::assertSame([0, 1], $loaded->uploadedChunks);
        self::assertFalse($loaded->isCompleted);
        self::assertNull($loaded->finalPath);
    }

    #[Test]
    public function test_get_returns_null_for_an_unknown_identifier(): void
    {
        self::assertNull($this->repository->get('missing'));
    }

    #[Test]
    public function test_delete_removes_a_record_and_is_idempotent(): void
    {
        $this->repository->save($this->state('delete-me', [0]));
        $this->repository->delete('delete-me');
        $this->repository->delete('delete-me');

        self::assertNull($this->repository->get('delete-me'));
    }

    #[Test]
    public function test_mark_chunk_as_uploaded_converges_atomically(): void
    {
        $this->repository->save($this->state('converge', []));

        $afterOne = $this->repository->markChunkAsUploaded('converge', 1);
        $afterTwo = $this->repository->markChunkAsUploaded('converge', 2);

        self::assertSame([1], $afterOne->uploadedChunks);
        self::assertSame([1, 2], $afterTwo->uploadedChunks);
        self::assertSame([1, 2], $this->repository->get('converge')?->uploadedChunks);
    }

    #[Test]
    public function test_mark_chunk_as_uploaded_is_idempotent_for_a_repeated_index(): void
    {
        $this->repository->save($this->state('dup', []));

        $this->repository->markChunkAsUploaded('dup', 0);
        $again = $this->repository->markChunkAsUploaded('dup', 0);

        self::assertSame([0], $again->uploadedChunks);
    }

    #[Test]
    public function test_upload_completes_only_when_all_declared_chunks_are_present(): void
    {
        $this->repository->save($this->state('complete', []));

        $state = $this->repository->markChunkAsUploaded('complete', 0);
        self::assertFalse($state->isCompleted);

        $state = $this->repository->markChunkAsUploaded('complete', 1);
        self::assertFalse($state->isCompleted);

        $state = $this->repository->markChunkAsUploaded('complete', 2);
        self::assertFalse($state->isCompleted);

        $state = $this->repository->markChunkAsUploaded('complete', 3);
        self::assertTrue($state->isCompleted);
    }

    #[Test]
    public function test_mark_chunk_throws_for_an_unknown_upload(): void
    {
        $this->expectException(MetadataException::class);
        $this->repository->markChunkAsUploaded('never-existed', 0);
    }

    #[Test]
    public function test_clean_expired_removes_only_stale_records(): void
    {
        $this->repository->save($this->state('stale', []));
        $this->stale($this->pdo, 'stale', 7200);

        $this->repository->save($this->state('fresh', []));

        $removed = $this->repository->cleanExpired(3600);

        self::assertSame(1, $removed);
        self::assertNull($this->repository->get('stale'));
        self::assertNotNull($this->repository->get('fresh'));
    }

    #[Test]
    public function test_progress_tracking_contract_is_implemented(): void
    {
        $state = $this->state('progress', [0]);
        self::assertInstanceOf(\Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface::class, $this->repository);
        self::assertSame(25.0, $this->repository->getPercentage($state));
        self::assertFalse($this->repository->isComplete($state));
        self::assertSame([1, 2, 3], $this->repository->getMissingChunkIndices($state));
    }

    #[Test]
    public function test_postgresql_dialect_uses_double_quoted_identifiers_and_excluded(): void
    {
        $pdo = new FakePdo('pgsql');
        $repository = new PdoMetadataRepository($pdo, 'states');
        $repository->ensureSchema();

        self::assertStringContainsString('"states"', $pdo->executed[0]);
        self::assertStringNotContainsString('`', $pdo->executed[0]);

        $repository->save($this->state('pg', [0]));
        $upsert = end($pdo->prepared);

        self::assertStringContainsString('INSERT INTO "states"', $upsert);
        self::assertStringContainsString('ON CONFLICT(identifier) DO UPDATE SET', $upsert);
        self::assertStringContainsString('EXCLUDED.total_chunks', $upsert);
        self::assertStringNotContainsString('`', $upsert);
    }

    #[Test]
    public function test_mysql_dialect_uses_backticks_and_duplicate_key_update(): void
    {
        $pdo = new FakePdo('mysql');
        $repository = new PdoMetadataRepository($pdo, 'states');
        $repository->ensureSchema();

        self::assertStringContainsString('`states`', $pdo->executed[0]);

        $repository->save($this->state('mysql', [0]));
        $upsert = end($pdo->prepared);

        self::assertStringContainsString('INSERT INTO `states`', $upsert);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $upsert);
        self::assertStringContainsString('VALUES(total_chunks)', $upsert);
        self::assertStringNotContainsString('EXCLUDED.', $upsert);
        self::assertStringNotContainsString('"states"', $upsert);
    }

    #[Test]
    public function test_sqlite_opens_write_transactions_with_begin_immediate(): void
    {
        $pdo = new FakePdo('sqlite');
        $repository = new PdoMetadataRepository($pdo, 'states');

        $repository->save($this->state('sqlite', [0]));
        $repository->save($this->state('sqlite-2', [1]));

        $executed = $pdo->executed;

        self::assertSame(2, \count(array_values(array_filter(
            $executed,
            static fn (string $sql): bool => $sql === 'BEGIN IMMEDIATE',
        ))));
        self::assertSame(2, \count(array_values(array_filter(
            $executed,
            static fn (string $sql): bool => $sql === 'COMMIT',
        ))));
    }

    #[Test]
    public function test_non_sqlite_drivers_use_native_transaction_handling(): void
    {
        $pdo = new FakePdo('pgsql');
        $repository = new PdoMetadataRepository($pdo, 'states');

        $repository->save($this->state('pg-txn', [0]));

        self::assertNotContains('BEGIN IMMEDIATE', $pdo->executed);
    }

    private function state(string $identifier, array $uploaded): UploadState
    {
        return new UploadState(
            identifier: $identifier,
            totalChunks: 4,
            totalSize: 400,
            originalFilename: 'seed.bin',
            uploadedChunks: $uploaded,
            isCompleted: false,
            finalPath: null,
        );
    }

    private function stale(PDO $pdo, string $identifier, int $seconds): void
    {
        $stmt = $pdo->prepare('UPDATE chunked_upload_states SET updated_at = :ts WHERE identifier = :id');
        $stmt->execute([':ts' => time() - $seconds, ':id' => $identifier]);
    }
}
