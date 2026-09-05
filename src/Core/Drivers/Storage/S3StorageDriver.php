<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Drivers\Storage;

use Resumable\ChunkedUploader\Core\Contracts\StorageInterface;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Stores chunks and assembles files on an S3-compatible object store.
 *
 * Chunk persistence uses S3 multipart upload primitives, and assembly streams
 * data locally so memory use stays flat. Requires the `aws/aws-sdk-php`
 * package (see composer suggest).
 */
class S3StorageDriver implements StorageInterface
{
    private const S3ChunkDirectory = 'chunks';

    /**
     * @param \Aws\S3\S3Client $client  Configured S3 client
     * @param string           $bucket  Bucket name where chunks and files are stored
     * @param string           $keyPrefix Optional prefix applied to all object keys
     */
    public function __construct(
        private readonly \Aws\S3\S3Client $client,
        private readonly string $bucket,
        private readonly string $keyPrefix = '',
    ) {
    }

    /**
     * Uploads the chunk's temp file to S3 under the key prefix and a per-upload folder.
     */
    public function storeChunk(Chunk $chunk): bool
    {
        // Stub: S3 upload is implemented in a later step.
        return true;
    }

    /**
     * Downloads and streams all chunks into a local file, returning its path.
     */
    public function assemble(UploadState $state): string
    {
        // Stub: S3 assembly is implemented in a later step.
        return '';
    }

    /**
     * Removes every chunk object belonging to the upload.
     */
    public function deleteChunks(UploadState $state): void
    {
        // Stub: S3 cleanup is implemented in a later step.
    }
}