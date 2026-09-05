<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Events;

use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Dispatched once an upload reaches completeness and is assembled successfully.
 */
final readonly class FileAssembledEvent
{
    public function __construct(
        public UploadState $state,
        public string $finalPath,
    ) {
    }
}