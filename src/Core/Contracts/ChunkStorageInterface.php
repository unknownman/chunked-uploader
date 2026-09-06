<?php

declare(strict_types=1);

// File: src/Core/Contracts/ChunkStorageInterface.php

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Exceptions\StorageException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

/**
 * Abstracts the low-level persistence of temporary chunk artifacts.
 *
 * This contract is deliberately scoped to the chunk lifecycle only: storing an
 * inbound chunk, opening a read stream over a stored chunk, and purging every
 * chunk belonging to an upload. Concrete drivers (local filesystem, S3, ...)
 * own the physical layout and are interchangeable without affecting callers.
 *
 * Implementations MUST:
 *  - persist chunks so that concurrent uploads of distinct chunks do not
 *    interfere with one another;
 *  - be idempotent with respect to {@see ChunkStorageInterface::store()}: a
 *    re-issued store for an already-persisted chunk MUST leave the artifact
 *    intact and MUST NOT raise an error;
 *  - guarantee that {@see ChunkStorageInterface::getChunkStream()} exposes a
 *    readable PHP stream suitable for stream-to-stream assembly.
 */
interface ChunkStorageInterface
{
    /**
     * Persists a single chunk's temporary payload to the underlying medium.
     *
     * The concrete driver decides whether to move or copy the inbound temp
     * file, and where within its namespace the chunk artifact lives. Failures
     * to persist MUST raise StorageException rather than fail silently.
     *
     * @param Chunk $chunk Immutable DTO describing the chunk to persist
     *
     * @throws StorageException when the chunk cannot be written to the medium
     */
    public function store(Chunk $chunk): void;

    /**
     * Returns a readable PHP stream covering the persisted bytes of a chunk.
     *
     * The stream is handed to the assembler for stream-to-stream copying, so it
     * MUST be opened in read/binary mode and SHOULD be seekable so assembly can
     * be retried without re-fetching. Callers MUST close the stream once they
     * are finished with it.
     *
     * @param Chunk $chunk Immutable DTO identifying the chunk to read
     *
     * @return resource A valid PHP stream resource opened for reading
     *
     * @throws ChunkNotFoundException when no persisted artifact exists for the chunk
     * @throws StorageException       when the artifact exists but cannot be read
     */
    public function getChunkStream(Chunk $chunk): mixed;

    /**
     * Removes every persisted chunk artifact belonging to an upload.
     *
     * Used during upload cancellation, garbage collection, and after a failed
     * assembly. MUST be idempotent: purging an upload with no remaining chunks
     * MUST NOT raise an error.
     *
     * @param string $identifier The upload identifier whose chunks are removed
     *
     * @throws StorageException when one or more artifacts cannot be removed
     */
    public function deleteChunks(string $identifier): void;

    /**
     * Removes chunk artifacts that have not been touched within the TTL window.
     *
     * Used by the garbage collector to reclaim orphaned or abandoned upload
     * artifacts. The concrete driver decides how to measure staleness (e.g. the
     * file modified time of an upload's directory). Implementations MUST be
     * length-safe on the returned count and MUST NOT raise an error when there
     * is nothing to clean.
     *
     * @param int $ttlSeconds Maximum age, in seconds, before an artifact is
     *                        considered orphaned and eligible for removal
     *
     * @return int Number of orphaned chunk artifacts that were removed
     *
     * @throws StorageException when one or more artifacts cannot be removed
     */
    public function cleanOrphanedChunks(int $ttlSeconds): int;
}