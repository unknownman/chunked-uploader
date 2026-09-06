<?php

declare(strict_types=1);

// File: src/Core/Contracts/MetadataRepositoryInterface.php

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Exceptions\MetadataException;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Persists upload state across stateless HTTP requests.
 *
 * The repository is the source of truth for which chunk indices have been
 * persisted for a given identifier, enabling true resume capability across
 * requests and servers. Implementations live in Redis or a relational database
 * (via PDO) and MUST provide atomic mutations so concurrent chunk uploads
 * converge on the same record instead of corrupting it.
 */
interface MetadataRepositoryInterface
{
    /**
     * Persists the given upload state, creating or fully replacing the record.
     *
     * @param UploadState $state Immutable snapshot to persist
     *
     * @throws MetadataException when the datastore connection or write fails
     */
    public function save(UploadState $state): void;

    /**
     * Retrieves the state of an upload by its identifier.
     *
     * @param string $identifier The upload identifier to look up
     *
     * @return UploadState|null The persisted state, or null when no state exists
     *
     * @throws MetadataException when the datastore connection or read fails
     */
    public function get(string $identifier): ?UploadState;

    /**
     * Removes all persisted state for an upload identifier.
     *
     * MUST be idempotent: deleting state that does not exist MUST NOT raise an
     * error.
     *
     * @param string $identifier The upload identifier to remove
     *
     * @throws MetadataException when the datastore connection or delete fails
     */
    public function delete(string $identifier): void;

    /**
     * Atomically records a chunk index as uploaded and returns the new state.
     *
     * This is the race-condition-critical operation: concurrent requests for
     * the same identifier MUST converge rather than clobber one another.
     * Implementations SHOULD rely on native atomic primitives -- Redis WATCH or
     * Lua scripts, SQL row locks or atomic JSON updates -- or on optimistic
     * locking with conflict retries.
     *
     * @param string $identifier The upload whose progress is advanced
     * @param int    $chunkIndex Zero-based index of the persisted chunk
     *
     * @return UploadState The up-to-date state after the index was recorded
     *
     * @throws MetadataException when the datastore connection or update fails
     */
    public function markChunkAsUploaded(string $identifier, int $chunkIndex): UploadState;

    /**
     * Removes stale metadata records that have not been updated within the TTL
     * window.
     *
     * Used by the garbage collector to purge abandoned uploads whose chunks were
     * never completed. Implementations MUST be idempotent and MUST NOT raise an
     * error when there are no expired records.
     *
     * @param int $ttlSeconds Maximum age, in seconds, of a record before it is
     *                        considered expired and removed
     *
     * @return int Number of expired records that were removed
     *
     * @throws MetadataException when the datastore connection or delete fails
     */
    public function cleanExpired(int $ttlSeconds): int;
}