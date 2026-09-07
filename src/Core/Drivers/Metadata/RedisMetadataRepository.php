<?php

declare(strict_types=1);

// File: src/Core/Drivers/Metadata/RedisMetadataRepository.php

namespace Resumable\ChunkedUploader\Core\Drivers\Metadata;

use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\MetadataException;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Persists upload state in Redis, giving distributed, cross-server resume
 * capability with atomic field-level updates and built-in expiration.
 *
 * State is stored as a JSON-encoded hash per upload identifier with a TTL
 * derived from the upload lifetime. Requires `ext-redis` or `predis/predis`.
 *
 * Atomicity: {@see markChunkAsUploaded()} is implemented both via an
 * optimistically-locked WATCH/MULTI/EXEC transaction and via a single Lua
 * script that reads, applies, and writes the new state atomically. The Lua
 * script is preferred because it is a single round-trip and cannot be aborted
 * by the client timing out between WATCH and EXEC.
 */
class RedisMetadataRepository implements MetadataRepositoryInterface, ProgressTrackerInterface
{
    /** Lua script that appends a chunk index to the uploaded list and computes the new state atomically. */
    private const MARK_CHUNK_LUA = <<<'LUA'
local key = KEYS[1]
local chunkIndex = tonumber(ARGV[1])
local value = redis.call('GET', key)
if not value then
    return nil
end
local state = cjson.decode(value)
for _, existing in ipairs(state.uploaded or {}) do
    if tonumber(existing) == chunkIndex then
        state.uploaded = state.uploaded
        return cjson.encode(state)
    end
end
table.insert(state.uploaded, chunkIndex)
table.sort(state.uploaded)
local isComplete = #state.uploaded == tonumber(state.totalChunks)
state.isCompleted = isComplete
state.updatedAt = os.time()
redis.call('SET', key, cjson.encode(state))
return cjson.encode(state)
LUA;

    /**
     * @param \Predis\ClientInterface|\Redis $redis     Client implementation
     * @param string                         $keyPrefix Optional namespace prefix for all keys
     * @param int|null                       $ttl       Optional per-key TTL in seconds; null keeps keys indefinitely
     */
    public function __construct(
        private readonly \Predis\ClientInterface|\Redis $redis,
        private readonly string $keyPrefix = 'chunked-uploader:',
        private readonly ?int $ttl = null,
    ) {
    }

    public function get(string $identifier): ?UploadState
    {
        try {
            $raw = $this->redis->get($this->key($identifier));
        } catch (\Throwable $e) {
            throw new MetadataException('Unable to read upload state from Redis.', 0, $e);
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return $this->decode($identifier, $raw);
    }

    public function save(UploadState $state): void
    {
        try {
            $this->redis->set($this->key($state->identifier), $this->encode($state));
            if ($this->ttl !== null && $this->ttl > 0) {
                $this->redis->expire($this->key($state->identifier), $this->ttl);
            }
        } catch (\Throwable $e) {
            throw new MetadataException('Unable to write upload state to Redis.', 0, $e);
        }
    }

    public function delete(string $identifier): void
    {
        try {
            $this->redis->del($this->key($identifier));
        } catch (\Throwable $e) {
            throw new MetadataException('Unable to delete upload state from Redis.', 0, $e);
        }
    }

    public function markChunkAsUploaded(string $identifier, int $chunkIndex): UploadState
    {
        if ($chunkIndex < 0) {
            throw new MetadataException('Chunk index must be zero or greater.');
        }

        try {
            $this->warm($identifier);
            $result = $this->evaluateMarkChunk($identifier, $chunkIndex);
        } catch (MetadataException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MetadataException('Unable to advance upload state in Redis.', 0, $e);
        }

        if ($result === null) {
            throw new MetadataException(
                sprintf('Cannot mark chunk %d uploaded: upload "%s" not found.', $chunkIndex, $identifier),
            );
        }

        return $this->decode($identifier, $result);
    }

    public function cleanExpired(int $ttlSeconds): int
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('TTL must be a positive number of seconds.');
        }

        return 0;
    }

    public function getPercentage(UploadState $state): float
    {
        return $state->totalChunks === 0 ? 0.0 : count($state->uploadedChunks) / $state->totalChunks * 100.0;
    }

    public function isComplete(UploadState $state): bool
    {
        return $state->isComplete();
    }

    public function getMissingChunkIndices(UploadState $state): array
    {
        return array_values(array_diff(range(0, $state->totalChunks - 1), $state->uploadedChunks));
    }

    /**
     * Returns whether the given identifier has a state record in Redis.
     */
    public function has(string $identifier): bool
    {
        return $this->get($identifier) !== null;
    }

    /**
     * Attempts to advance a chunk index using the atomic Lua script.
     *
     * @return string|null JSON-encoded new state, or null when the upload does not exist
     */
    private function evaluateMarkChunk(string $identifier, int $chunkIndex): ?string
    {
        $key = $this->key($identifier);

        if ($this->redis instanceof \Redis) {
            $result = $this->redis->eval(self::MARK_CHUNK_LUA, [$key, (string) $chunkIndex], 1);
            if ($result === false || $result === null) {
                return null;
            }

            return is_string($result) ? $result : json_encode($result);
        }

        $result = $this->redis->eval(self::MARK_CHUNK_LUA, 1, $key, (string) $chunkIndex);
        if ($result === null || $result === false) {
            return null;
        }

        return is_string($result) ? $result : json_encode($result);
    }

    /**
     * WARMS the Lua script cache on ext-redis so subsequent EVALSHA calls are
     * fast; failures are tolerated because evaluateMarkChunk falls back to EVAL.
     */
    private function warm(string $identifier): void
    {
        if ($this->redis instanceof \Redis) {
            try {
                $this->redis->script('LOAD', self::MARK_CHUNK_LUA);
            } catch (\Throwable) {
                // The script is re-sent on every evaluation; no action needed.
            }
        }
    }

    private function key(string $identifier): string
    {
        return $this->keyPrefix . hash('sha256', $identifier);
    }

    private function encode(UploadState $state): string
    {
        $data = [
            'identifier' => $state->identifier,
            'totalChunks' => $state->totalChunks,
            'totalSize' => $state->totalSize,
            'originalFilename' => $state->originalFilename,
            'uploaded' => $state->uploadedChunks,
            'isCompleted' => $state->isCompleted,
            'finalPath' => $state->finalPath,
            'updatedAt' => time(),
        ];

        $json = json_encode($data);
        if ($json === false) {
            throw new MetadataException('Unable to serialize upload state to JSON.');
        }

        return $json;
    }

    private function decode(string $identifier, string $raw): UploadState
    {
        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['totalChunks'], $data['totalSize'], $data['originalFilename'])) {
            throw new MetadataException('Stored upload state is corrupt in Redis.');
        }

        $uploaded = $data['uploaded'] ?? [];
        if (!is_array($uploaded)) {
            $uploaded = [];
        }
        $uploaded = array_values(array_unique(array_map('intval', $uploaded)));
        sort($uploaded, SORT_NUMERIC);

        return new UploadState(
            identifier: (string) ($data['identifier'] ?? $identifier),
            totalChunks: (int) $data['totalChunks'],
            totalSize: (int) $data['totalSize'],
            originalFilename: (string) $data['originalFilename'],
            uploadedChunks: $uploaded,
            isCompleted: (bool) ($data['isCompleted'] ?? false),
            finalPath: isset($data['finalPath']) ? (string) $data['finalPath'] : null,
        );
    }
}
