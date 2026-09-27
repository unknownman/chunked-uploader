<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/DependencyInjection/AssemblerDriverFactory.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection;

use Aws\S3\S3Client;
use Resumable\ChunkedUploader\Core\Assembler\S3MultipartAssembler;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;

/**
 * Builds the {@see FileAssemblerInterface} implementation that matches the
 * configured storage driver.
 *
 * The two storage drivers need different assemblers and are not
 * interchangeable. Local chunk storage writes real, readable files, so
 * {@see StreamAssembler} copies them together. The S3 driver keeps its parts
 * inside an in-progress multipart upload, where individual parts are not
 * addressable objects at all, so it needs {@see S3MultipartAssembler} to commit
 * the part manifest instead.
 *
 * Wiring the wrong one is not a soft failure: pairing the S3 driver with the
 * streaming assembler throws on the first chunk read, and pairing the local
 * driver with the multipart assembler cannot find an UploadId. Selecting both
 * from the single `chunk_uploader.storage` parameter keeps the pair consistent.
 */
final class AssemblerDriverFactory
{
    /**
     * @param array<string, mixed> $s3Config
     */
    public function __construct(
        private readonly string $driver,
        private readonly string $finalBaseDir,
        private readonly string $s3Bucket,
        private readonly string $s3Prefix,
        private readonly array $s3Config,
        private readonly PathSanitizer $sanitizer,
        private readonly string $s3FinalPrefix = 'uploads/',
    ) {
    }

    public function create(): FileAssemblerInterface
    {
        if ($this->driver === 's3') {
            return new S3MultipartAssembler(
                client: new S3Client($this->s3Config),
                bucket: $this->s3Bucket,
                basePrefix: $this->s3Prefix,
                finalPrefix: $this->s3FinalPrefix,
                sanitizer: $this->sanitizer,
            );
        }

        return new StreamAssembler($this->finalBaseDir);
    }
}
