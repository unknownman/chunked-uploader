<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Drivers\Storage;

use Resumable\ChunkedUploader\Core\Contracts\StorageInterface;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;

/**
 * Stores chunks and assembled files on the local filesystem.
 *
 * Chunks are laid out under a per-upload spool subdirectory; the final
 * assembly is streamed into the same tree. All user-controlled path segments
 * are routed through PathSanitizer to neutralize traversal payloads.
 * The base directory can point at a vfsStream root during unit tests.
 */
class LocalStorageDriver implements StorageInterface
{
    public function __construct(
        private readonly string $baseDirectory,
        private readonly PathSanitizer $sanitizer = new PathSanitizer(),
    ) {
    }

    /**
     * Reliably moves the chunk's temp file into the per-upload spool directory.
     */
    public function storeChunk(Chunk $chunk): bool
    {
        // Stub: persistence is implemented in a later step.
        return true;
    }

    /**
     * Streams the uploaded chunks into a local assembly and returns its path.
     */
    public function assemble(UploadState $state): string
    {
        // Stub: assembly is implemented in a later step.
        return '';
    }

    /**
     * Recursively removes the upload's spool directory and all chunks.
     */
    public function deleteChunks(UploadState $state): void
    {
        // Stub: cleanup is implemented in a later step.
    }
}