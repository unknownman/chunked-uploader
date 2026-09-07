<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Drivers\Storage\FlysystemChunkStorage;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Tests\TestCase;

final class FlysystemChunkStorageTest extends TestCase
{
    #[Test]
    public function test_it_stores_reads_and_deletes_streamed_chunks(): void
    {
        $filesystem = new FakeFlysystemOperator();
        $storage = new FlysystemChunkStorage($filesystem, 'uploads');
        $chunk = new Chunk('track', '', 0, 1, 4, 4, $this->temporaryFile('DATA'), 'x.bin');

        $storage->store($chunk);
        self::assertSame('DATA', stream_get_contents($storage->getChunkStream($chunk)));

        $storage->deleteChunk($chunk);
        self::assertFalse($filesystem->fileExists('uploads/track/chunk_0.part'));
    }

    #[Test]
    public function test_it_cleans_stale_entries_incrementally(): void
    {
        $filesystem = new FakeFlysystemOperator();
        $storage = new FlysystemChunkStorage($filesystem, 'uploads');
        $filesystem->put('uploads/old/chunk_0.part', 'old', time() - 7200);
        $filesystem->put('uploads/fresh/chunk_0.part', 'fresh', time());

        self::assertSame(1, $storage->cleanOrphanedChunks(3600));
        self::assertFalse($filesystem->fileExists('uploads/old/chunk_0.part'));
        self::assertTrue($filesystem->fileExists('uploads/fresh/chunk_0.part'));
    }
}

final class FakeFlysystemOperator
{
    /** @var array<string, array{contents: string, modified: int}> */
    private array $files = [];

    public function fileExists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function writeStream(string $path, mixed $stream): void
    {
        $this->put($path, (string) stream_get_contents($stream), time());
    }

    public function readStream(string $path): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->files[$path]['contents']);
        rewind($stream);
        return $stream;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    /** @return iterable<FakeFlysystemEntry> */
    public function listContents(string $prefix, bool $deep): iterable
    {
        foreach ($this->files as $path => $file) {
            if (str_starts_with($path, $prefix)) {
                yield new FakeFlysystemEntry($path, $file['modified']);
            }
        }
    }

    public function put(string $path, string $contents, int $modified): void
    {
        $this->files[$path] = ['contents' => $contents, 'modified' => $modified];
    }
}

final class FakeFlysystemEntry
{
    public function __construct(private readonly string $entryPath, private readonly int $modified)
    {
    }

    public function isFile(): bool
    {
        return true;
    }

    public function path(): string
    {
        return $this->entryPath;
    }

    public function lastModified(): int
    {
        return $this->modified;
    }
}
