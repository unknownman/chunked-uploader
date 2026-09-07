<?php

declare(strict_types=1);

// File: tests/Unit/UploadManagerOrphanRollbackTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\UploadManager;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\NullEventDispatcher;
use Resumable\ChunkedUploader\Tests\TestCase;

final class UploadManagerOrphanRollbackTest extends TestCase
{
    #[Test]
    public function test_a_stored_chunk_is_deleted_when_progress_cannot_be_recorded(): void
    {
        $storage = new RecordingChunkStorage();
        $metadata = $this->createMock(MetadataRepositoryInterface::class);
        $metadata->method('get')->willReturn(null);
        $metadata->method('markChunkAsUploaded')->willThrowException(new \RuntimeException('database down'));

        $manager = $this->manager($storage, $metadata);

        try {
            $manager->processChunk($this->chunk(
                path: $this->temporaryFile('DATA'),
                token: 'token',
                index: 0,
                totalChunks: 1,
                totalSize: 4,
                identifier: 'rollback',
            ));
            $this->fail('Expected the metadata failure to surface.');
        } catch (UploadFailedException $e) {
            self::assertStringContainsString('record upload', $e->getMessage());
        }

        self::assertSame(1, $storage->deleteChunkCalls);
        self::assertFalse($storage->hasChunks('rollback'));
    }

    #[Test]
    public function test_a_failed_rollback_never_masks_the_original_error(): void
    {
        $storage = new FailingRollbackChunkStorage();
        $metadata = $this->createMock(MetadataRepositoryInterface::class);
        $metadata->method('get')->willReturn(null);
        $metadata->method('markChunkAsUploaded')->willThrowException(new \RuntimeException('database down'));

        $manager = $this->manager($storage, $metadata);

        $this->expectException(UploadFailedException::class);
        $manager->processChunk($this->chunk(
            path: $this->temporaryFile('DATA'),
            token: 'token',
            index: 0,
            totalChunks: 1,
            totalSize: 4,
            identifier: 'rollback',
        ));
    }

    private function manager(ChunkStorageInterface $storage, MetadataRepositoryInterface $metadata): UploadManager
    {
        $validator = $this->createMock(ChunkValidatorInterface::class);
        $validator->method('validate')->willReturn(true);
        $progress = $this->createMock(ProgressTrackerInterface::class);

        return new UploadManager(
            storage: $storage,
            metadata: $metadata,
            progress: $progress,
            assembler: new StreamAssembler($this->tempDir() . '/final'),
            validator: $validator,
            dispatcher: new NullEventDispatcher(),
        );
    }
}

final class RecordingChunkStorage extends InMemoryChunkStorage
{
    public int $deleteChunkCalls = 0;

    public function deleteChunk(Chunk $chunk): void
    {
        $this->deleteChunkCalls++;
        parent::deleteChunk($chunk);
    }
}

final class FailingRollbackChunkStorage extends InMemoryChunkStorage
{
    public function deleteChunk(Chunk $chunk): void
    {
        throw new \RuntimeException('network unreachable');
    }
}
