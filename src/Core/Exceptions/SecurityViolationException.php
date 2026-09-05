<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * Raised when an incoming payload violates security boundaries.
 */
class SecurityViolationException extends ChunkUploaderException
{
}