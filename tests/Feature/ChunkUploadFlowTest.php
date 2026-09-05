<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Feature;

use Resumable\ChunkedUploader\Core\Drivers\Security\UploadTokenManager;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\TestCase;

final class ChunkUploadFlowTest extends TestCase
{
    public function test_it_uploads_all_chunks_assembles_the_file_and_deletes_temporary_chunks(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $manager = $this->createManager($storage, $metadata);
        $token = (new UploadTokenManager('test-secret'))->generateToken('upload_test');
        $parts = ['hello ', 'world'];

        foreach ($parts as $index => $part) {
            $path = $this->temporaryFile($part);
            $state = $manager->processChunk(new Chunk('upload_test', $token, $index, 2, strlen($part), 11, $path, 'payload.txt'));
        }

        self::assertTrue($state->isCompleted);
        self::assertNotNull($state->finalPath);
        self::assertSame('hello world', file_get_contents($state->finalPath));
        self::assertSame(hash('sha256', 'hello world'), hash_file('sha256', $state->finalPath));
        self::assertFalse($storage->hasChunks('upload_test'));
    }

    public function test_it_assembles_once_and_deletes_chunks_immediately_after_assembly(): void
    {
        $metadata = new InMemoryMetadataRepository();
        $storage = $this->createMock(ChunkStorageInterface::class);
        $assembler = $this->createMock(FileAssemblerInterface::class);
        $assembled = false;
        $storage->expects(self::once())->method('store');
        $storage->expects(self::once())->method('deleteChunks')->willReturnCallback(
            function () use (&$assembled): void {
                self::assertTrue($assembled);
            },
        );
        $assembler->expects(self::once())->method('assemble')->willReturnCallback(
            function () use (&$assembled): string {
                $assembled = true;
                return '/tmp/final-upload.txt';
            },
        );

        $manager = new \Resumable\ChunkedUploader\Core\UploadManager(
            storage: $storage,
            metadata: $metadata,
            progress: $metadata,
            assembler: $assembler,
            tokenManager: new UploadTokenManager('test-secret'),
        );
        $token = (new UploadTokenManager('test-secret'))->generateToken('upload_spy');
        $state = $manager->processChunk(new Chunk('upload_spy', $token, 0, 1, 4, 4, $this->temporaryFile('data'), 'payload.txt'));

        self::assertTrue($state->isCompleted);
        self::assertTrue($assembled);
    }
}
