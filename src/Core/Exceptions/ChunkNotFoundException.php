<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * Raised when a requested chunk artifact does not exist in storage.
 */
class ChunkNotFoundException extends ChunkUploaderException
{
}