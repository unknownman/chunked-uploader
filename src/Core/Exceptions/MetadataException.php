<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * Raised when a metadata repository cannot reach or operate on its datastore.
 */
class MetadataException extends \RuntimeException
{
}
