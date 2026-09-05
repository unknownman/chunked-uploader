<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * Raised when a chunk storage driver cannot write, read, or delete chunk
 * artifacts on its underlying medium.
 */
class StorageException extends \RuntimeException
{
}