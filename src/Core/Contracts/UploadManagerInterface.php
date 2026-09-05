<?php

declare(strict_types=1);

// File: src/Core/Contracts/UploadManagerInterface.php

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Facade/Coordinator contract for the entire chunked upload workflow.
 *
 * Depending on this abstraction instead of on concrete drivers keeps callers
 * agnostic to whether chunks are stored on a local filesystem or in object
 * storage, and whether state lives in Redis or a relational database. The
 * implementing manager validates, persists, tracks progress, and triggers
 * assembly internally, exposing a single narrow entry point.
 */
interface UploadManagerInterface
{
    /**
     * Processes a single inbound chunk end-to-end.
     *
     * The flow is: validate the chunk (size limits, MIME magic bytes, SHA-256
     * checksum, identifier hygiene) -> persist the chunk -> advance and persist
     * the upload state -> emit the chunk-accepted lifecycle event -> trigger
     * assembly the moment the last expected chunk is persisted.
     *
     * The method MUST be idempotent: re-processing an already-accepted chunk
     * index returns the current state without persisting a duplicate and
     * without corrupting progress. It MUST also be safe under concurrent
     * requests racing on the same identifier; state mutations are resolved
     * atomically by the metadata repository.
     *
     * @param Chunk $chunk Immutable DTO describing the incoming chunk
     *
     * @return UploadState The current state after the chunk was (or already had
     *                     been) applied; complete once assembly has finished
     *
     * @throws InvalidChunkException      when the chunk fails size or checksum checks
     * @throws SecurityViolationException when malicious payloads are detected
     * @throws UploadFailedException      when an unexpected workflow failure occurs
     */
    public function processChunk(Chunk $chunk): UploadState;

    /**
     * Aborts an upload and releases every resource associated with it.
     *
     * Removes all persisted chunk artifacts and the corresponding metadata
     * state, allowing orphaned bytes to be reclaimed immediately instead of
     * waiting for garbage collection. MUST be idempotent: cancelling an unknown
     * upload is a no-op.
     *
     * @param string $identifier The upload identifier to cancel and clean up
     *
     * @throws UploadFailedException when cleanup of chunks or state fails
     */
    public function cancelUpload(string $identifier): void;
}