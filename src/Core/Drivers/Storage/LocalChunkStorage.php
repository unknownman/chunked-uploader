<?php

declare(strict_types=1);

// File: src/Core/Drivers/Storage/LocalChunkStorage.php

namespace Resumable\ChunkedUploader\Core\Drivers\Storage;

use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Exceptions\StorageException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Throwable;

/**
 * Stores chunk artifacts on the local filesystem.
 *
 * Each upload identifier owns a dedicated subdirectory whose name is derived
 * from the sanitized identifier, and each chunk is stored as an individual
 * `.part` file inside it. Storing is atomic thanks to a rename/copy fallback
 * and idempotent so retried chunks never duplicate physical bytes. Malicious
 * identifiers are rejected via {@see PathSanitizer} before any filesystem
 * interaction occurs.
 */
final class LocalChunkStorage implements ChunkStorageInterface
{
    public function __construct(
        private readonly string $baseDir,
        private readonly ?PathSanitizer $sanitizer = null,
    ) {
        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0775, true) && !is_dir($this->baseDir)) {
            throw new StorageException('Unable to create base directory');
        }
    }

    public function store(Chunk $chunk): void
    {
        $id = $this->sanitize($chunk->identifier);

        $targetDir = $this->baseDir . DIRECTORY_SEPARATOR . $id;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new StorageException('Unable to create upload directory');
        }

        $dest = $targetDir . DIRECTORY_SEPARATOR . 'chunk_' . $chunk->index . '.part';

        if (is_file($dest)) {
            return;
        }

        $source = $chunk->tmpFilePath;
        $moved = false;

        if (is_uploaded_file($source)) {
            $moved = @move_uploaded_file($source, $dest);
        }

        if (!$moved) {
            $moved = @rename($source, $dest);
        }

        if (!$moved) {
            $copied = @copy($source, $dest);
            if ($copied) {
                @unlink($source);
                $moved = true;
            }
        }

        if (!$moved || !is_file($dest)) {
            throw new StorageException('Failed to persist chunk to local storage');
        }
    }

    public function getChunkStream(Chunk $chunk): mixed
    {
        $id = $this->sanitize($chunk->identifier);
        $path = $this->baseDir . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'chunk_' . $chunk->index . '.part';

        if (!is_file($path)) {
            throw new ChunkNotFoundException('Chunk not found: ' . $path);
        }

        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            throw new StorageException('Unable to open chunk stream for reading');
        }

        return $stream;
    }

    public function deleteChunks(string $identifier): void
    {
        $id = $this->sanitize($identifier);

        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $id;
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . 'chunk_*.part');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    public function cleanOrphanedChunks(int $ttlSeconds): int
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('TTL must be a positive number of seconds.');
        }

        $cutoff = time() - $ttlSeconds;
        $removed = 0;

        $entries = @scandir($this->baseDir);
        if ($entries === false) {
            throw new StorageException('Unable to scan chunk storage base directory');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dir = $this->baseDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($dir)) {
                continue;
            }

            try {
                $this->sanitize($entry);
            } catch (Throwable) {
                // An entry that is not a valid upload identifier is not ours to
                // manage and is left untouched.
                continue;
            }

            /** @var int|false $mtime */
            $mtime = @filemtime($dir);
            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }

            try {
                $this->deleteChunks($entry);
                $removed++;
            } catch (Throwable) {
                // Skip an artifact that cannot be removed this pass.
            }
        }

        return $removed;
    }

    private function sanitize(string $identifier): string
    {
        return ($this->sanitizer ?? new PathSanitizer())->sanitizeIdentifier($identifier);
    }
}
