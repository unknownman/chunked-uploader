<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/DependencyInjection/StorageDriverFactory.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection;

use Aws\S3\S3Client;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Drivers\Storage\LocalChunkStorage;
use Resumable\ChunkedUploader\Core\Drivers\Storage\S3ChunkStorage;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;

/**
 * Builds the configured {@see ChunkStorageInterface} concrete driver from the
 * bundle parameters, hiding the driver-selection switch from the container.
 */
final class StorageDriverFactory
{
    /**
     * @param array<string, mixed> $s3Config
     */
    public function __construct(
        private readonly string $driver,
        private readonly string $localBaseDirectory,
        private readonly string $s3Bucket,
        private readonly string $s3Prefix,
        private readonly array $s3Config,
        private readonly PathSanitizer $sanitizer,
    ) {
    }

    public function create(): ChunkStorageInterface
    {
        if ($this->driver === 's3') {
            return new S3ChunkStorage(
                client: new S3Client($this->s3Config),
                bucket: $this->s3Bucket,
                basePrefix: $this->s3Prefix,
                sanitizer: $this->sanitizer,
            );
        }

        return new LocalChunkStorage(
            baseDir: $this->localBaseDirectory,
            sanitizer: $this->sanitizer,
        );
    }
}
