<?php

declare(strict_types=1);

// File: src/Core/Drivers/Storage/LocalChunkStorage.php

namespace Resumable\ChunkedUploader\Core\Drivers\Storage;

use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Exceptions\StorageException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use FilesystemIterator;
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
        $this->ensureDirectory($this->baseDir);
    }

    public function store(Chunk $chunk): void
    {
        $id = $this->sanitize($chunk->identifier);

        $targetDir = $this->baseDir . DIRECTORY_SEPARATOR . $id;
        $this->ensureDirectory($targetDir);

        // Guarantee the directory's mtime advances on every accepted chunk so
        // the Garbage Collector's TTL check stays accurate for chunk spools.
        // Best-effort by design: an environment that forbids mtime updates must
        // not reject otherwise-valid chunks.
        $this->refreshDirectoryMtime($targetDir);

        $dest = $targetDir . DIRECTORY_SEPARATOR . 'chunk_' . $chunk->index . '.part';

        if (is_file($dest)) {
            return;
        }

        $source = $chunk->tmpFilePath;
        if (!is_file($source)) {
            throw new StorageException('Chunk source file does not exist: ' . $source);
        }

        if (!is_readable($source)) {
            throw new StorageException('Chunk source file is not readable: ' . $source);
        }

        $moved = false;
        if (is_uploaded_file($source)) {
            $moved = move_uploaded_file($source, $dest);
        }

        if (!$moved && rename($source, $dest)) {
            $moved = true;
        }

        if (!$moved) {
            // A cross-device move must be copied beside the destination first;
            // renaming the completed temporary copy keeps readers from seeing
            // a partially copied chunk.
            $temporary = $dest . '.tmp-' . bin2hex(random_bytes(8));
            $cleanupFailed = false;
            try {
                if (!copy($source, $temporary)) {
                    throw new StorageException('Failed to copy chunk into local storage');
                }

                if (!rename($temporary, $dest)) {
                    throw new StorageException('Failed to atomically publish chunk in local storage');
                }

                $moved = true;
            } finally {
                if (is_file($temporary) && !unlink($temporary)) {
                    $cleanupFailed = true;
                }
            }

            if ($cleanupFailed) {
                throw new StorageException('Failed to remove temporary chunk file: ' . $temporary);
            }
        }

        if (!is_file($dest) || !is_readable($dest)) {
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

        $stream = fopen($path, 'rb');

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

        $this->removeDirectoryRecursively($dir);
    }

    public function deleteChunk(Chunk $chunk): void
    {
        $id = $this->sanitize($chunk->identifier);
        $path = $this->baseDir . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'chunk_' . $chunk->index . '.part';

        if (!is_file($path) && !is_link($path)) {
            return;
        }

        if (!unlink($path)) {
            throw new StorageException('Unable to remove chunk artifact: ' . $path);
        }
    }

    public function cleanOrphanedChunks(int $ttlSeconds): int
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('TTL must be a positive number of seconds.');
        }

        $cutoff = time() - $ttlSeconds;
        $removed = 0;

        // FilesystemIterator walks the base directory lazily so a spool with
        // millions of upload directories is never loaded into memory at once.
        if (!is_dir($this->baseDir)) {
            return 0;
        }

        $iterator = new FilesystemIterator($this->baseDir, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if (!$entry->isDir()) {
                continue;
            }

            $dir = $entry->getPathname();

            try {
                $this->sanitize($entry->getFilename());
            } catch (Throwable) {
                // An entry that is not a valid upload identifier is not ours to
                // manage and is left untouched.
                continue;
            }

            $mtime = $this->directoryMtime($dir);
            if ($mtime === null || $mtime > $cutoff) {
                continue;
            }

            try {
                $this->removeDirectoryRecursively($dir);
                $removed++;
            } catch (Throwable) {
                // Skip an artifact that cannot be removed this pass.
            }
        }

        return $removed;
    }

    /**
     * Removes an upload's chunk directory including any nested entries.
     *
     * @throws StorageException when an entry cannot be removed
     */
    private function removeDirectoryRecursively(string $dir): void
    {
        $iterator = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectoryRecursively($path);
            } elseif (!unlink($path)) {
                throw new StorageException('Unable to remove chunk artifact: ' . $path);
            }
        }

        if (!rmdir($dir)) {
            throw new StorageException('Unable to remove chunk directory: ' . $dir);
        }
    }

    /**
     * Creates a directory recursively or verifies it already exists.
     *
     * @throws StorageException when the directory cannot be created
     */
    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new StorageException('Unable to create directory: ' . $dir);
        }
    }

    /**
     * Bumps a directory's modification time for GC staleness tracking.
     *
     * @return bool True when the mtime was advanced, false when the
     *              filesystem refused (non-fatal)
     */
    private function refreshDirectoryMtime(string $dir): bool
    {
        try {
            return touch($dir);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Returns a directory's modification timestamp, or null when unreadable.
     */
    private function directoryMtime(string $dir): ?int
    {
        try {
            $mtime = filemtime($dir);
        } catch (Throwable) {
            return null;
        }

        return $mtime === false ? null : $mtime;
    }

    private function sanitize(string $identifier): string
    {
        return ($this->sanitizer ?? new PathSanitizer())->sanitizeIdentifier($identifier);
    }
}
