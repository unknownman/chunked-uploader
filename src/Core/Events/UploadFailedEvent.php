<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Events;

use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Dispatched when an upload fails at any stage (validation error, integrity
 * failure, storage error, ...) so listeners can react and clean up.
 */
final readonly class UploadFailedEvent
{
    public function __construct(
        public UploadState $state,
        public \Throwable $exception,
    ) {
    }
}