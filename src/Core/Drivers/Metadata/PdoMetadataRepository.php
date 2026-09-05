<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Drivers\Metadata;

use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Persists upload state in a relational database via PDO.
 *
 * A dedicated table stores one row per upload; the list of uploaded chunk
 * indices is kept in a JSON column. Upserts are used so concurrent chunk
 * uploads for the same identifier converge instead of clobbering one another.
 */
class PdoMetadataRepository implements MetadataRepositoryInterface
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tableName = 'chunked_upload_states',
    ) {
    }

    public function get(string $identifier): ?UploadState
    {
        // Stub: retrieval is implemented in a later step.
        return null;
    }

    public function save(UploadState $state): void
    {
        // Stub: upsert is implemented in a later step.
    }

    public function delete(string $identifier): void
    {
        // Stub: deletion is implemented in a later step.
    }

    public function markChunkAsUploaded(string $identifier, int $chunkIndex): UploadState
    {
        // Stub: atomic advance is implemented in a later step.
        $state = $this->get($identifier);

        if ($state === null) {
            throw new \Resumable\ChunkedUploader\Core\Exceptions\MetadataException(
                sprintf('Cannot mark chunk %d uploaded: upload "%s" not found.', $chunkIndex, $identifier),
            );
        }

        return $state->withUploadedChunk($chunkIndex);
    }
}