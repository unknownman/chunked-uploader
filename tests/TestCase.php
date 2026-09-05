<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;

abstract class TestCase extends PHPUnitTestCase
{
    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = tempnam(sys_get_temp_dir(), 'chunked-uploader-test-');
        if ($directory === false || !unlink($directory) || !mkdir($directory, 0775, true)) {
            self::fail('Unable to create temporary test directory.');
        }
        $this->temporaryDirectory = $directory;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    protected function temporaryFile(string $contents): string
    {
        $path = tempnam($this->temporaryDirectory, 'chunk-');
        self::assertNotFalse($path);
        self::assertNotFalse(file_put_contents($path, $contents));
        return $path;
    }

    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }

    protected function createManager(InMemoryChunkStorage $storage, InMemoryMetadataRepository $metadata, string $secret = 'test-secret'): \Resumable\ChunkedUploader\Core\UploadManager
    {
        $assembler = new \Resumable\ChunkedUploader\Core\Assembler\StreamAssembler($this->temporaryDirectory . '/final');
        return new \Resumable\ChunkedUploader\Core\UploadManager(
            storage: $storage,
            metadata: $metadata,
            progress: $metadata,
            assembler: $assembler,
            tokenManager: new \Resumable\ChunkedUploader\Core\Drivers\Security\UploadTokenManager($secret),
        );
    }

    protected function chunk(string $path, string $token, int $index, int $totalChunks, int $totalSize, string $identifier = 'upload_test'): Chunk
    {
        $size = filesize($path);
        self::assertIsInt($size);
        return new Chunk(
            identifier: $identifier,
            token: $token,
            index: $index,
            totalChunks: $totalChunks,
            chunkSize: $size,
            totalSize: $totalSize,
            tmpFilePath: $path,
            originalFilename: 'payload.txt',
        );
    }
}

class InMemoryChunkStorage implements ChunkStorageInterface
{
    /** @var array<string, array<int, string>> */
    private array $files = [];

    public function __destruct()
    {
        foreach ($this->files as $identifier => $_) {
            $this->deleteChunks($identifier);
        }
    }

    public function store(Chunk $chunk): void
    {
        if (isset($this->files[$chunk->identifier][$chunk->index])) {
            return;
        }
        $path = tempnam(sys_get_temp_dir(), 'stored-chunk-');
        if ($path === false || !copy($chunk->tmpFilePath, $path)) {
            throw new \RuntimeException('Unable to store test chunk.');
        }
        $this->files[$chunk->identifier][$chunk->index] = $path;
    }

    public function getChunkStream(Chunk $chunk): mixed
    {
        $path = $this->files[$chunk->identifier][$chunk->index] ?? null;
        if ($path === null) {
            throw new \Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException('Missing test chunk.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open test chunk.');
        }
        return $stream;
    }

    public function deleteChunks(string $identifier): void
    {
        foreach ($this->files[$identifier] ?? [] as $path) {
            @unlink($path);
        }
        unset($this->files[$identifier]);
    }

    public function hasChunks(string $identifier): bool
    {
        return isset($this->files[$identifier]) && $this->files[$identifier] !== [];
    }
}

final class InMemoryMetadataRepository implements MetadataRepositoryInterface, ProgressTrackerInterface
{
    /** @var array<string, UploadState> */
    private array $states = [];

    public function save(UploadState $state): void
    {
        $this->states[$state->identifier] = $state;
    }

    public function get(string $identifier): ?UploadState
    {
        return $this->states[$identifier] ?? null;
    }

    public function delete(string $identifier): void
    {
        unset($this->states[$identifier]);
    }

    public function markChunkAsUploaded(string $identifier, int $chunkIndex): UploadState
    {
        $state = $this->states[$identifier] ?? throw new \RuntimeException('Missing test state.');
        $state = $state->withUploadedChunk($chunkIndex);
        $this->states[$identifier] = $state;
        return $state;
    }

    public function getPercentage(UploadState $state): float
    {
        return $state->totalChunks === 0 ? 0.0 : count($state->uploadedChunks) / $state->totalChunks * 100.0;
    }

    public function isComplete(UploadState $state): bool
    {
        return $state->isComplete();
    }

    public function getMissingChunkIndices(UploadState $state): array
    {
        return array_values(array_diff(range(0, $state->totalChunks - 1), $state->uploadedChunks));
    }
}
