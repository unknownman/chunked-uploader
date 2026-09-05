<?php

declare(strict_types=1);

// File: src/Core/Contracts/ValidatorInterface.php

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

/**
 * Enforces security and integrity rules on an inbound chunk *before* any
 * persistence, storage write, or assembly occurs.
 *
 * Implementations perform server-side checks only and never trust
 * client-supplied values: MIME type is derived from file magic bytes, per-chunk
 * size and total upload size are measured against configured ceilings, the
 * SHA-256 checksum is recomputed over the actual bytes, and identifiers and
 * filenames are screened for directory traversal payloads.
 */
interface ValidatorInterface
{
    /**
     * Validates a single chunk and halts processing on any violation.
     *
    * A failed validation MUST throw immediately so the request short-circuits
    * before a single byte is written to disk. On success the method returns
    * true, signaling that the chunk is fit to be persisted and processed.
     *
     * @param Chunk $chunk Immutable DTO describing the chunk to inspect
     *
     * @throws InvalidChunkException      for size-limit or checksum mismatches
     * @throws SecurityViolationException for MIME mismatches or directory
     *                                    traversal attempts in identifiers
     */
    public function validate(Chunk $chunk): bool;
}