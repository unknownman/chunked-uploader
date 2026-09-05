<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Standardizes all low-level IO operations for chunk persistence and final assembly.
 *
 * Implementations are entirely responsible for the physical layout of chunks on
 * their respective medium (local filesystem, S3-compatible object storage, ...).
 * The contract intentionally avoids any framework-specific concerns.
 */
interface StorageInterface
{
    /**
     * Persists a single chunk of the incoming file to the underlying medium.
     *
     * Implementations MUST move or copy the chunk reliably and must be idempotent:
     * storing an already-stored chunk MUST NOT corrupt existing state.
     *
     * @throws \Resumable\ChunkedUploader\Core\Exceptions\StorageWriteException on persistence failure
     */
    public function storeChunk(Chunk $chunk): bool;

    /**
     * Streams the assembled file from the underlying medium into a local temporary
     * file and returns its absolute path.
     *
     * The returned path MUST reference a readable streamable file; callers are
     * responsible for reading, relocating, or deleting it afterwards.
     *
     * @return string Absolute path to the assembled file
     *
     * @throws \Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException on assembly failure
     */
    public function assemble(UploadState $state): string;

    /**
     * Securely removes every persisted chunk belonging to the given upload.
     *
     * MUST be idempotent: deleting chunks of an upload that no longer exists MUST
     * not raise an error. Used during finalization and garbage collection.
     */
    public function deleteChunks(UploadState $state): void;
}