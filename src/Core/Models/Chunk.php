<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Models;

/**
 * Immutable data transfer object representing a single chunk upload.
 */
final readonly class Chunk
{
    public function __construct(
        public string $identifier,
        public string $token,
        public int $index,
        public int $totalChunks,
        public int $chunkSize,
        public int $totalSize,
        public string $tmpFilePath,
        public string $originalFilename,
        public ?string $checksum = null,
    ) {
    }
}