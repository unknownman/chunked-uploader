<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Events;

use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Dispatched after a chunk has been validated and persisted successfully.
 */
final readonly class ChunkUploadedEvent
{
    public function __construct(
        public UploadState $state,
        public Chunk $chunk,
    ) {
    }
}