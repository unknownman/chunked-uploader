<?php

declare(strict_types=1);

// File: src/Core/Drivers/Storage/S3ChunkStorage.php

namespace Resumable\ChunkedUploader\Core\Drivers\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Psr\Http\Message\StreamInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Exceptions\StorageException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;

/**
 * Stores chunk artifacts in an S3-compatible object store via the AWS SDK.
 *
 * Each chunk is uploaded as a distinct object using a streaming UploadBody backed
 * by a PHP stream resource, guaranteeing O(1) memory. Reading a chunk returns the
 * underlying Psr7 StreamInterface, which the assembler consumes through
 * `stream_copy_to_stream()` / `Psr7\StreamWrapper`, so no chunk is ever buffered
 * entirely into PHP memory.
 */
final class S3ChunkStorage implements ChunkStorageInterface
{
    /**
     * AWS `DeleteObjects` accepts at most 1000 keys per request.
     */
    private const MAX_DELETE_BATCH = 1000;

    /**
     * @param S3Client            $client    Configured S3 client
     * @param string              $bucket    Destination bucket
     * @param string              $basePrefix Object-key namespace (no leading slash)
     * @param PathSanitizer|null  $sanitizer Identifier boundary used for keys
     */
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly string $basePrefix = 'chunks/',
        private readonly ?PathSanitizer $sanitizer = null,
    ) {
    }

    public function store(Chunk $chunk): void
    {
        $key = $this->objectKey($chunk->identifier, $chunk->index);
        $source = $chunk->tmpFilePath;

        $stream = fopen($source, 'rb');
        if ($stream === false) {
            throw new StorageException('Unable to open chunk source for S3 upload');
        }

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $stream,
                'ContentLength' => $chunk->chunkSize,
            ]);
        } catch (AwsException $e) {
            throw new StorageException('Failed to upload chunk to S3: ' . $e->getAwsErrorMessage(), 0, $e);
        } finally {
            fclose($stream);
        }
    }

    public function getChunkStream(Chunk $chunk): mixed
    {
        $key = $this->objectKey($chunk->identifier, $chunk->index);

        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                // Stream the response body lazily instead of letting the SDK
                // buffer it, keeping assembly O(1) in memory for any chunk size.
                '@http' => ['stream' => true],
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NoSuchKey' || $e->getStatusCode() === 404) {
                throw new ChunkNotFoundException('Chunk not found in S3: ' . $key);
            }

            throw new StorageException('Unable to fetch chunk from S3: ' . $e->getAwsErrorMessage(), 0, $e);
        }

        $body = $result['Body'] ?? null;
        if (!$body instanceof StreamInterface) {
            throw new StorageException('S3 did not return a readable chunk body.');
        }

        if (!$body->isReadable()) {
            throw new StorageException('S3 returned a non-readable chunk body for key: ' . $key);
        }

        if ($body->isSeekable() && $body->tell() > 0) {
            $body->rewind();
        }

        return $body;
    }

    public function deleteChunks(string $identifier): void
    {
        $prefix = $this->basePrefix . $this->sanitize($identifier) . '/';

        try {
            $paginator = $this->client->getPaginator('ListObjectsV2', [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ]);

            // Keys are deleted in bounded batches as they are surfaced by the
            // paginator, so an upload with millions of chunk objects never
            // materializes its full key list into memory at once.
            foreach ($paginator as $page) {
                $this->purgeKeys($page['Contents'] ?? []);
            }
        } catch (AwsException $e) {
            throw new StorageException('Failed to delete chunks from S3: ' . $e->getAwsErrorMessage(), 0, $e);
        }
    }

    public function deleteChunk(Chunk $chunk): void
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($chunk->identifier, $chunk->index),
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NoSuchKey' || $e->getStatusCode() === 404) {
                return;
            }

            throw new StorageException('Failed to delete chunk from S3: ' . $e->getAwsErrorMessage(), 0, $e);
        }
    }

    public function cleanOrphanedChunks(int $ttlSeconds): int
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('TTL must be a positive number of seconds.');
        }

        $cutoff = time() - $ttlSeconds;
        $removed = 0;

        try {
            $paginator = $this->client->getPaginator('ListObjectsV2', [
                'Bucket' => $this->bucket,
                'Prefix' => $this->basePrefix,
            ]);

            foreach ($paginator as $page) {
                $stale = [];
                foreach (($page['Contents'] ?? []) as $object) {
                    $lastModified = $object['LastModified'] ?? null;
                    if ($lastModified === null) {
                        continue;
                    }

                    $timestamp = $lastModified instanceof \DateTimeInterface ? $lastModified->getTimestamp() : strtotime((string) $lastModified);
                    if ($timestamp !== false && $timestamp <= $cutoff) {
                        $stale[] = ['Key' => $object['Key']];
                    }
                }

                // Each page yields at most a bounded batch of stale keys, which
                // are deleted immediately so scanning millions of objects never
                // accumulates the whole key list in memory.
                if ($stale !== []) {
                    $this->purgeKeys($stale);
                    $removed += count($stale);
                }
            }
        } catch (AwsException $e) {
            throw new StorageException('Failed to clean orphaned chunks from S3: ' . $e->getAwsErrorMessage(), 0, $e);
        }

        return $removed;
    }

    /**
     * Issues DeleteObjects calls over an iterable of keys, flushing each
     * full 1000-key batch immediately and the remainder once exhausted.
     *
     * @param iterable<array{Key: string}> $objects Keys to remove
     */
    private function purgeKeys(iterable $objects): void
    {
        $batch = [];
        foreach ($objects as $object) {
            $batch[] = $object;
            if (count($batch) >= self::MAX_DELETE_BATCH) {
                $this->deleteBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->deleteBatch($batch);
        }
    }

    /**
     * @param non-empty-list<array{Key: string}> $objects
     */
    private function deleteBatch(array $objects): void
    {
        $this->client->deleteObjects([
            'Bucket' => $this->bucket,
            'Delete' => ['Objects' => $objects],
        ]);
    }

    private function objectKey(string $identifier, int $index): string
    {
        $id = $this->sanitize($identifier);

        return $this->basePrefix . $id . '/' . 'chunk_' . $index . '.part';
    }

    private function sanitize(string $identifier): string
    {
        return ($this->sanitizer ?? new PathSanitizer())->sanitizeIdentifier($identifier);
    }
}
