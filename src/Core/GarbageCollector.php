<?php

declare(strict_types=1);

// File: src/Core/GarbageCollector.php

namespace Resumable\ChunkedUploader\Core;

use Psr\Log\LoggerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;

/**
 * Coordinates cleanup of orphaned chunks and expired metadata.
 *
 * Safely reclaims resources for abandoned uploads: chunk artifacts that have
 * not been touched within the TTL are purged from storage, and stale metadata
 * records older than the TTL are removed from the repository. Storage and
 * repository are intentionally cleaned independently so a failure in one does
 * not silently wipe the other. Every operation tolerates individual failures by
 * logging them and continuing, never aborting the whole sweep.
 */
final class GarbageCollector
{
    public function __construct(
        private readonly ChunkStorageInterface $storage,
        private readonly MetadataRepositoryInterface $metadata,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Runs a full sweep: purges orphaned chunks and expired metadata.
     *
     * @param int $ttlSeconds Staleness threshold applied to both storage and metadata
     * @return array{chunks: int, metadata: int} Counts per category
     */
    public function collect(int $ttlSeconds): array
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('TTL must be a positive number of seconds.');
        }

        $removedChunks = 0;
        $removedMetadata = 0;

        try {
            $removedChunks = $this->storage->cleanOrphanedChunks($ttlSeconds);
        } catch (\Throwable $e) {
            $this->logger?->error('Garbage collection failed for chunks', ['error' => $e->getMessage()]);
        }

        try {
            $removedMetadata = $this->metadata->cleanExpired($ttlSeconds);
        } catch (\Throwable $e) {
            $this->logger?->error('Garbage collection failed for metadata', ['error' => $e->getMessage()]);
        }

        return ['chunks' => $removedChunks, 'metadata' => $removedMetadata];
    }

    /**
     * Runs a sweep limited to chunk artifacts only.
     *
     * @param int $ttlSeconds Staleness threshold
     * @return int Number of purged chunk artifacts
     */
    public function collectChunks(int $ttlSeconds): int
    {
        return $this->storage->cleanOrphanedChunks($ttlSeconds);
    }

    /**
     * Runs a sweep limited to expired metadata records only.
     *
     * @param int $ttlSeconds Staleness threshold
     * @return int Number of purged metadata records
     */
    public function collectMetadata(int $ttlSeconds): int
    {
        return $this->metadata->cleanExpired($ttlSeconds);
    }
}
