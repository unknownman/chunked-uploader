<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Drivers\Storage;

use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;
use Resumable\ChunkedUploader\Core\Exceptions\StorageException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

final class LocalChunkStorage implements ChunkStorageInterface
{
    public function __construct(private readonly string $baseDir)
    {
        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0775, true) && !is_dir($this->baseDir)) {
            throw new StorageException('Unable to create base directory');
        }
    }

    public function store(Chunk $chunk): void
    {
        $id = $this->sanitizeIdentifier($chunk->identifier);

        $targetDir = $this->baseDir . DIRECTORY_SEPARATOR . $id;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new StorageException('Unable to create upload directory');
        }

        $dest = $targetDir . DIRECTORY_SEPARATOR . 'chunk_' . $chunk->index . '.part';

        if (is_file($dest)) {
            // Idempotent: already stored
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
        $id = $this->sanitizeIdentifier($chunk->identifier);
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
        $id = $this->sanitizeIdentifier($identifier);

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

    private function sanitizeIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9]+$/', $identifier)) {
            throw new SecurityViolationException('Invalid upload identifier');
        }

        return $identifier;
    }
}
