<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * Raised when an upload fails during assembly or finalization.
 */
class UploadFailedException extends ChunkUploaderException
{
}
