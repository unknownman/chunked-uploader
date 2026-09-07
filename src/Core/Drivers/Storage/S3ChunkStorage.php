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

        $stream = @fopen($source, 'rb');
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

        return $body;
    }

    public function deleteChunks(string $identifier): void
    {
        $id = $this->sanitize($identifier);
        $prefix = $this->basePrefix . $id . '/';

        try {
            $objects = [];
            $paginator = $this->client->getPaginator('ListObjectsV2', [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ]);

            foreach ($paginator as $page) {
                foreach (($page['Contents'] ?? []) as $object) {
                    $objects[] = ['Key' => $object['Key']];
                }
            }

            if ($objects !== []) {
                $this->client->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => ['Objects' => $objects],
                ]);
            }
        } catch (AwsException $e) {
            throw new StorageException('Failed to delete chunks from S3: ' . $e->getAwsErrorMessage(), 0, $e);
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

            $toDelete = [];
            foreach ($paginator as $page) {
                foreach (($page['Contents'] ?? []) as $object) {
                    $lastModified = $object['LastModified'] ?? null;
                    if ($lastModified === null) {
                        continue;
                    }

                    $timestamp = $lastModified instanceof \DateTimeInterface ? $lastModified->getTimestamp() : strtotime((string) $lastModified);
                    if ($timestamp !== false && $timestamp <= $cutoff) {
                        $toDelete[] = ['Key' => $object['Key']];
                    }
                }
            }

            if ($toDelete !== []) {
                $this->client->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => ['Objects' => $toDelete],
                ]);
                $removed = count($toDelete);
            }
        } catch (AwsException $e) {
            throw new StorageException('Failed to clean orphaned chunks from S3: ' . $e->getAwsErrorMessage(), 0, $e);
        }

        return $removed;
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
