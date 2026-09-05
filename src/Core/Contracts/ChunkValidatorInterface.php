<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Models\Chunk;

/**
 * Performs security and integrity checks against a single incoming chunk.
 *
 * Implementations MUST validate the chunk independently of any client-supplied
 * metadata: MIME type is determined from magic bytes, checksums are recomputed
 * server-side, and identifiers are screened for traversal payloads.
 */
interface ChunkValidatorInterface
{
    /**
     * Validates a single incoming chunk before any persistence occurs.
     *
     * @throws \Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException
     *         when the chunk fails integrity checks
     * @throws \Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException
     *         when the chunk violates security constraints
     */
    public function validate(Chunk $chunk): bool;
}