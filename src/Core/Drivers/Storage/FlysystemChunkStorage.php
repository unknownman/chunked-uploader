<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Drivers\Storage;

use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Exceptions\StorageException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Throwable;

/**
 * Stores chunks through a Flysystem v3 filesystem operator.
 *
 * The dependency is intentionally typed as object so Flysystem remains an
 * optional integration. The required operator methods are checked at runtime,
 * allowing offline applications and tests to provide a compatible double.
 */
final class FlysystemChunkStorage implements ChunkStorageInterface
{
    public function __construct(
        private readonly object $filesystem,
        private readonly string $basePrefix = 'chunks',
        private readonly ?PathSanitizer $sanitizer = null,
    ) {
        foreach (['fileExists', 'writeStream', 'readStream', 'delete', 'listContents'] as $method) {
            if (!method_exists($this->filesystem, $method)) {
                throw new StorageException('The Flysystem operator is missing: ' . $method);
            }
        }
    }

    public function store(Chunk $chunk): void
    {
        $key = $this->objectKey($chunk->identifier, $chunk->index);
        try {
            if ($this->filesystem->fileExists($key)) {
                return;
            }

            $stream = fopen($chunk->tmpFilePath, 'rb');
            if ($stream === false) {
                throw new StorageException('Unable to open chunk source for Flysystem upload.');
            }

            try {
                $this->filesystem->writeStream($key, $stream);
            } finally {
                fclose($stream);
            }
        } catch (StorageException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new StorageException('Failed to store chunk through Flysystem.', 0, $e);
        }
    }

    public function getChunkStream(Chunk $chunk): mixed
    {
        $key = $this->objectKey($chunk->identifier, $chunk->index);
        try {
            if (!$this->filesystem->fileExists($key)) {
                throw new ChunkNotFoundException('Chunk not found: ' . $key);
            }

            $stream = $this->filesystem->readStream($key);
            if (!is_resource($stream)) {
                throw new StorageException('Flysystem did not return a readable stream.');
            }

            return $stream;
        } catch (ChunkNotFoundException | StorageException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new StorageException('Unable to read chunk through Flysystem.', 0, $e);
        }
    }

    public function deleteChunks(string $identifier): void
    {
        $prefix = $this->directoryKey($identifier);
        try {
            foreach ($this->filesystem->listContents($prefix, true) as $entry) {
                if (method_exists($entry, 'isFile') && $entry->isFile()) {
                    $this->filesystem->delete($this->entryPath($entry));
                }
            }
        } catch (Throwable $e) {
            throw new StorageException('Failed to delete chunks through Flysystem.', 0, $e);
        }
    }

    public function deleteChunk(Chunk $chunk): void
    {
        $key = $this->objectKey($chunk->identifier, $chunk->index);
        try {
            if ($this->filesystem->fileExists($key)) {
                $this->filesystem->delete($key);
            }
        } catch (Throwable $e) {
            throw new StorageException('Failed to delete chunk through Flysystem.', 0, $e);
        }
    }

    public function cleanOrphanedChunks(int $ttlSeconds): int
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('TTL must be a positive number of seconds.');
        }

        $cutoff = time() - $ttlSeconds;
        $removed = 0;
        try {
            foreach ($this->filesystem->listContents($this->prefix(), true) as $entry) {
                if (!method_exists($entry, 'isFile') || !$entry->isFile() || !method_exists($entry, 'lastModified')) {
                    continue;
                }

                if ($entry->lastModified() <= $cutoff) {
                    $this->filesystem->delete($this->entryPath($entry));
                    $removed++;
                }
            }
        } catch (Throwable $e) {
            throw new StorageException('Failed to clean orphaned Flysystem chunks.', 0, $e);
        }

        return $removed;
    }

    private function objectKey(string $identifier, int $index): string
    {
        return $this->directoryKey($identifier) . 'chunk_' . $index . '.part';
    }

    private function directoryKey(string $identifier): string
    {
        return $this->prefix() . $this->sanitize($identifier) . '/';
    }

    private function prefix(): string
    {
        return trim($this->basePrefix, '/') === '' ? '' : trim($this->basePrefix, '/') . '/';
    }

    private function sanitize(string $identifier): string
    {
        return ($this->sanitizer ?? new PathSanitizer())->sanitizeIdentifier($identifier);
    }

    private function entryPath(mixed $entry): string
    {
        if (!is_object($entry) || !method_exists($entry, 'path')) {
            throw new StorageException('Flysystem returned an invalid directory entry.');
        }

        $path = call_user_func([$entry, 'path']);
        if (!is_string($path) || $path === '') {
            throw new StorageException('Flysystem returned an invalid entry path.');
        }

        return $path;
    }
}
