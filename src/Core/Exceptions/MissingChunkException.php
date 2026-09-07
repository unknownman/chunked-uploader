<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * Raised when a required chunk index is absent during assembly iteration.
 */
class MissingChunkException extends \RuntimeException
{
}
