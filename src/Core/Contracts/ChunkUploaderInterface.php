<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;

interface ChunkUploaderInterface
{
    /** @throws InvalidChunkException|SecurityViolationException|UploadFailedException */
    public function processChunk(Chunk $chunk, ?UploaderConfig $config = null): UploadState;

    /** @throws UploadFailedException */
    public function cancelUpload(string $identifier): void;

    public function getStatus(string $identifier): ?UploadState;
}
