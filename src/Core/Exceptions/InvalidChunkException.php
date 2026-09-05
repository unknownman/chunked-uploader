<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * Raised when an incoming chunk violates integrity, ordering, or size constraints.
 */
class InvalidChunkException extends ChunkUploaderException
{
}