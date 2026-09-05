<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * Thrown when an upload token fails verification.
 */
class TokenMismatchException extends ChunkUploaderException
{
}
