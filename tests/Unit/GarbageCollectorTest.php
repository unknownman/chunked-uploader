<?php

declare(strict_types=1);

// File: tests/Unit/GarbageCollectorTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\GarbageCollector;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\TestCase;

final class GarbageCollectorTest extends TestCase
{
    #[Test]
    public function test_it_purges_orphaned_chunks_and_expired_metadata(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();

        // Two stale chunk sets and one stale state.
        $orphaned = $this->temporaryFile('A');
        $metadata->save(new \Resumable\ChunkedUploader\Core\Models\UploadState('stale', 2, 2, 'a.txt', [0], false));
        $metadata->save(new \Resumable\ChunkedUploader\Core\Models\UploadState('fresh', 2, 2, 'b.txt', [0], false));

        $this->makeStorage($storage, 'stale-upload', $orphaned);
        $this->insertChunk($storage, 'stale-upload');
        $this->insertChunk($storage, 'fresh-upload');
        $storage->ageChunks('stale-upload', 7200);
        $metadata->ageState('stale', 7200);

        $collector = new GarbageCollector($storage, $metadata);
        $result = $collector->collect(3600);

        self::assertGreaterThan(0, $result['chunks']);
        self::assertSame(1, $result['metadata']);
        self::assertFalse($storage->hasChunks('stale-upload'));
        self::assertTrue($storage->hasChunks('fresh-upload'));
        self::assertNull($metadata->get('stale'));
        self::assertNotNull($metadata->get('fresh'));
    }

    #[Test]
    public function test_collect_is_length_safe_against_a_failure_in_either_side(): void
    {
        $storage = $this->createMock(ChunkStorageInterface::class);
        $metadata = $this->createMock(MetadataRepositoryInterface::class);

        $storage->expects(self::once())->method('cleanOrphanedChunks')
            ->with(60)->willThrowException(new \RuntimeException('disk error'));
        $metadata->expects(self::once())->method('cleanExpired')->with(60)->willReturn(3);

        $collector = new GarbageCollector($storage, $metadata);
        $result = $collector->collect(60);

        self::assertSame(0, $result['chunks']);
        self::assertSame(3, $result['metadata']);
    }

    #[Test]
    public function test_it_rejects_a_non_positive_ttl(): void
    {
        $collector = new GarbageCollector(
            new InMemoryChunkStorage(),
            new InMemoryMetadataRepository(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $collector->collect(0);
    }

    #[Test]
    public function test_it_fan_out_to_collect_chunks_only(): void
    {
        $storage = $this->createMock(ChunkStorageInterface::class);
        $metadata = $this->createMock(MetadataRepositoryInterface::class);
        $storage->method('cleanOrphanedChunks')->willReturn(5);

        $collector = new GarbageCollector($storage, $metadata);

        self::assertSame(5, $collector->collectChunks(120));
    }

    #[Test]
    public function test_it_fan_out_to_collect_metadata_only(): void
    {
        $storage = $this->createMock(ChunkStorageInterface::class);
        $metadata = $this->createMock(MetadataRepositoryInterface::class);
        $metadata->method('cleanExpired')->willReturn(4);

        $collector = new GarbageCollector($storage, $metadata);

        self::assertSame(4, $collector->collectMetadata(120));
    }

    private function makeStorage(InMemoryChunkStorage $storage, string $identifier, string $path): void
    {
        $storage->store(new \Resumable\ChunkedUploader\Core\Models\Chunk(
            identifier: $identifier,
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: 1,
            totalSize: 1,
            tmpFilePath: $path,
            originalFilename: 'x.txt',
        ));
    }

    private function insertChunk(InMemoryChunkStorage $storage, string $identifier): void
    {
        $this->makeStorage($storage, $identifier, $this->temporaryFile('X'));
    }
}
