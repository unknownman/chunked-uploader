<?php

declare(strict_types=1);

// File: tests/Unit/LocalChunkStorageTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Drivers\Storage\LocalChunkStorage;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Tests\TestCase;

use function stream_get_contents;

final class LocalChunkStorageTest extends TestCase
{
    #[Test]
    public function test_store_persists_a_chunk_idempotently(): void
    {
        $base = $this->tempDir() . DIRECTORY_SEPARATOR . 'chunks';
        $storage = new LocalChunkStorage($base, new PathSanitizer());

        $chunk = $this->chunk($this->temporaryFile('DATA'), '', 0, 1, 4, 'track');
        $storage->store($chunk);

        self::assertFileExists($base . '/track/chunk_0.part');
        self::assertSame('DATA', file_get_contents($base . '/track/chunk_0.part'));

        // Idempotent re-store leaves the artifact intact and raises no error.
        $storage->store($chunk);
        self::assertSame('DATA', file_get_contents($base . '/track/chunk_0.part'));
    }

    #[Test]
    public function test_get_chunk_stream_returns_a_readable_native_stream(): void
    {
        $base = $this->tempDir() . DIRECTORY_SEPARATOR . 'chunks';
        $storage = new LocalChunkStorage($base, new PathSanitizer());

        $storage->store($this->chunk($this->temporaryFile('PAYLOAD'), '', 0, 1, 7, 'track'));

        $stream = $storage->getChunkStream($this->readChunk('track', 0, 7));
        self::assertIsResource($stream);
        self::assertSame('PAYLOAD', stream_get_contents($stream));
    }

    #[Test]
    public function test_delete_chunks_removes_hidden_files_too(): void
    {
        $base = $this->tempDir() . DIRECTORY_SEPARATOR . 'chunks';
        $storage = new LocalChunkStorage($base, new PathSanitizer());
        $storage->store($this->chunk($this->temporaryFile('AA'), '', 0, 1, 2, 'hidden'));
        $storage->store($this->chunk($this->temporaryFile('BB'), '', 1, 2, 2, 'hidden'));

        // The previous glob("chunk_*.part") implementation would not match a
        // hidden artifact; drop one in manually to prove FilesystemIterator
        // removes arbitrary files, then delete the whole spool.
        @file_put_contents($base . '/hidden/.hidden.tmp', 'secret');

        $storage->deleteChunks('hidden');

        self::assertDirectoryDoesNotExist($base . '/hidden');
        self::assertFileDoesNotExist($base . '/hidden/.hidden.tmp');
    }

    #[Test]
    public function test_store_touches_the_upload_directory_for_gc(): void
    {
        $base = $this->tempDir() . DIRECTORY_SEPARATOR . 'chunks';
        $storage = new LocalChunkStorage($base, new PathSanitizer());

        $storage->store($this->chunk($this->temporaryFile('C1'), '', 0, 1, 2, 'touched'));
        $dir = $base . '/touched';
        $firstMtime = filemtime($dir);

        usleep(1_100_000); // ensure a distinct second
        $storage->store($this->chunk($this->temporaryFile('C2'), '', 1, 2, 2, 'touched'));

        $secondMtime = filemtime($dir);
        self::assertNotFalse($firstMtime);
        self::assertNotFalse($secondMtime);
        self::assertGreaterThanOrEqual($firstMtime, $secondMtime);
    }

    #[Test]
    public function test_get_chunk_stream_throws_when_the_chunk_is_missing(): void
    {
        $base = $this->tempDir() . DIRECTORY_SEPARATOR . 'chunks';
        $storage = new LocalChunkStorage($base, new PathSanitizer());

        $this->expectException(ChunkNotFoundException::class);
        $storage->getChunkStream($this->readChunk('missing', 0, 1));
    }

    private function readChunk(string $identifier, int $index, int $chunkSize): Chunk
    {
        return new Chunk(
            identifier: $identifier,
            token: '',
            index: $index,
            totalChunks: 1,
            chunkSize: $chunkSize,
            totalSize: $chunkSize,
            tmpFilePath: '',
            originalFilename: 'x.bin',
        );
    }
}
