<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * Raised when a storage driver cannot persist or read a chunk, or cannot
 * reliably delete persisted artifacts of an upload.
 */
class StorageWriteException extends \RuntimeException
{
}