<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Drivers\Metadata;

use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Persists upload state in Redis, giving distributed, cross-server resume
 * capability with atomic field-level updates and built-in expiration.
 *
 * State is stored as a JSON-encoded hash per upload identifier with a TTL
 * derived from the upload lifetime. Requires `ext-redis` or `predis/predis`.
 */
class RedisMetadataRepository implements MetadataRepositoryInterface
{
    /**
     * @param \Predis\ClientInterface|\Redis $redis Client implementation
     * @param string                         $keyPrefix Optional namespace prefix for all keys
     */
    public function __construct(
        private readonly \Predis\ClientInterface|\Redis $redis,
        private readonly string $keyPrefix = 'chunked-uploader:',
    ) {
    }

    public function get(string $identifier): ?UploadState
    {
        // Stub: retrieval is implemented in a later step.
        return null;
    }

    public function save(UploadState $state): void
    {
        // Stub: persistence is implemented in a later step.
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