<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Drivers\Metadata;

use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\MetadataException;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Redis-backed metadata repository and progress tracker.
 */
final class RedisProgressTracker implements MetadataRepositoryInterface, ProgressTrackerInterface
{
    private readonly int $ttl;

    /**
     * @param mixed $redis Either an instance of \Redis or Predis\ClientInterface
     */
    public function __construct(private $redis, int $ttl = 86400)
    {
        $this->ttl = $ttl;
    }

    public function save(UploadState $state): void
    {
        try {
            $metaKey = $this->metaKey($state->identifier);
            $data = [
                'identifier' => $state->identifier,
                'originalFilename' => $state->originalFilename,
                'totalChunks' => (string) $state->totalChunks,
                'totalSize' => (string) $state->totalSize,
                'finalPath' => $state->finalPath ?? '',
            ];

            $this->call('hMSet', [$metaKey, $data]);
            $this->call('expire', [$metaKey, $this->ttl]);
        } catch (\Throwable $e) {
            throw new MetadataException('Failed to save upload metadata: ' . $e->getMessage(), 0, $e);
        }
    }

    public function get(string $identifier): ?UploadState
    {
        try {
            $metaKey = $this->metaKey($identifier);
            $chunksKey = $this->chunksKey($identifier);

            $meta = $this->call('hGetAll', [$metaKey]);
            if (empty($meta)) {
                return null;
            }

            $members = $this->call('sMembers', [$chunksKey]) ?: [];
            $uploaded = array_map('intval', $members);
            sort($uploaded, SORT_NUMERIC);

            return new UploadState(
                identifier: $identifier,
                totalChunks: (int) ($meta['totalChunks'] ?? 0),
                totalSize: (int) ($meta['totalSize'] ?? 0),
                originalFilename: (string) ($meta['originalFilename'] ?? ''),
                uploadedChunks: $uploaded,
                isCompleted: count($uploaded) === (int) ($meta['totalChunks'] ?? 0),
                finalPath: ($meta['finalPath'] ?? null) ?: null,
            );
        } catch (\Throwable $e) {
            throw new MetadataException('Failed to read upload metadata: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $identifier): void
    {
        try {
            $this->call('del', [$this->metaKey($identifier), $this->chunksKey($identifier)]);
        } catch (\Throwable $e) {
            throw new MetadataException('Failed to delete upload metadata: ' . $e->getMessage(), 0, $e);
        }
    }

    public function markChunkAsUploaded(string $identifier, int $chunkIndex): UploadState
    {
        $metaKey = $this->metaKey($identifier);
        $chunksKey = $this->chunksKey($identifier);

        $attempts = 0;
        do {
            try {
                $attempts++;

                // WATCH/MULTI/EXEC for optimistic atomic update
                if ($this->isPhpRedis()) {
                    $this->redis->watch($chunksKey);
                    $this->redis->multi();
                    $this->redis->sAdd($chunksKey, $chunkIndex);
                    $this->redis->expire($chunksKey, $this->ttl);
                    $this->redis->expire($metaKey, $this->ttl);
                    $exec = $this->redis->exec();
                    if ($exec === false) {
                        // concurrent modification, retry
                        continue;
                    }
                } else {
                    // Predis: use transaction with watch
                    $this->redis->watch($chunksKey);
                    $tx = $this->redis->multi();
                    $tx->sadd($chunksKey, $chunkIndex);
                    $tx->expire($chunksKey, $this->ttl);
                    $tx->expire($metaKey, $this->ttl);
                    $exec = $tx->exec();
                    if ($exec === null) {
                        continue;
                    }
                }

                // Read back state
                $meta = $this->call('hGetAll', [$metaKey]);
                $members = $this->call('sMembers', [$chunksKey]) ?: [];
                $uploaded = array_map('intval', $members);
                sort($uploaded, SORT_NUMERIC);

                $state = new UploadState(
                    identifier: $identifier,
                    totalChunks: (int) ($meta['totalChunks'] ?? 0),
                    totalSize: (int) ($meta['totalSize'] ?? 0),
                    originalFilename: (string) ($meta['originalFilename'] ?? ''),
                    uploadedChunks: $uploaded,
                    isCompleted: count($uploaded) === (int) ($meta['totalChunks'] ?? 0),
                    finalPath: ($meta['finalPath'] ?? null) ?: null,
                );

                return $state;
            } catch (\Throwable $e) {
                if ($attempts > 5) {
                    throw new MetadataException('Failed to mark chunk uploaded: ' . $e->getMessage(), 0, $e);
                }
                // small backoff
                usleep(10000);
            }
        } while ($attempts < 6);

        throw new MetadataException('Failed to mark chunk as uploaded due to repeated contention');
    }

    public function getPercentage(UploadState $state): float
    {
        if ($state->totalChunks === 0) {
            return 0.0;
        }

        return (count($state->uploadedChunks) / $state->totalChunks) * 100.0;
    }

    public function isComplete(UploadState $state): bool
    {
        return $state->isComplete();
    }

    public function getMissingChunkIndices(UploadState $state): array
    {
        $all = range(0, max(0, $state->totalChunks - 1));
        $missing = array_values(array_diff($all, $state->uploadedChunks));
        sort($missing, SORT_NUMERIC);
        return $missing;
    }

    private function metaKey(string $identifier): string
    {
        return 'upload:' . $identifier . ':meta';
    }

    private function chunksKey(string $identifier): string
    {
        return 'upload:' . $identifier . ':chunks';
    }

    private function isPhpRedis(): bool
    {
        return $this->redis instanceof \Redis;
    }

    /**
     * Helper to normalize calling commands on either phpredis or predis clients.
     *
     * @param string $command
     * @param array $args
     * @return mixed
     */
    private function call(string $command, array $args = []): mixed
    {
        if ($this->isPhpRedis()) {
            // phpredis: many methods are named the same but argument order differs
            return $this->redis->{$command}(...$args);
        }

        // Predis
        return $this->redis->{$command}(...$args);
    }
}
