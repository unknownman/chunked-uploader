<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core;

use Resumable\ChunkedUploader\Core\Contracts\ChunkUploaderInterface;

/**
 * Canonical coordinator for the resumable chunked-upload workflow.
 *
 * The inherited implementation is kept in UploadManager for source
 * compatibility with version 1.x consumers. New integrations should depend on
 * ChunkUploaderInterface and this class.
 */
final class ChunkUploader extends UploadManager implements ChunkUploaderInterface
{
}
