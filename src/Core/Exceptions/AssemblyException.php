<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * Raised when the final assembly of an upload fails, e.g. a stream write error
 * or a medium reporting full.
 */
class AssemblyException extends ChunkUploaderException
{
}
