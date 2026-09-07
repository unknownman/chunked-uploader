<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

/**
 * Canonical coordinator contract for the chunked upload workflow.
 *
 * UploadManagerInterface remains a source-compatible alias for older clients.
 */
interface ChunkUploaderInterface extends UploadManagerInterface
{
}
