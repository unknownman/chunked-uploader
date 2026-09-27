<?php

declare(strict_types=1);

// File: src/Core/Assembler/S3MultipartAssembler.php

namespace Resumable\ChunkedUploader\Core\Assembler;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Drivers\Storage\S3ObjectKeyResolver;
use Resumable\ChunkedUploader\Core\Exceptions\AssemblyException;
use Resumable\ChunkedUploader\Core\Exceptions\MissingChunkException;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;

/**
 * Finishes an S3 upload by handing the part ETag list back to the object store.
 *
 * S3 concatenates the parts itself, so unlike {@see StreamAssembler} this class
 * moves no file bytes at all: it submits the ordered manifest and the store
 * writes the final object. That removes a full read-and-write of the entire
 * upload from the request path, which on a large file is the difference between
 * a completion request that streams for minutes and one that returns in
 * milliseconds.
 *
 * The storage argument is accepted to satisfy {@see FileAssemblerInterface} but
 * intentionally unused -- parts are not readable objects, and reaching back into
 * storage here would be the only way to reintroduce client-side assembly.
 */
final class S3MultipartAssembler implements FileAssemblerInterface
{
    /*
     * Deliberately NOT HeartbeatAwareAssemblerInterface.
     *
     * The natural reading of "assembly lock expires during S3 assembly" is a
     * multi-gigabyte transfer that outruns the TTL. That cannot happen here.
     * S3 multipart splits a file across UploadPart calls, which happen when the
     * *chunks* arrive, outside the assembly lock. By the time this assembler
     * runs, every byte is already in S3; CompleteMultipartUpload only merges a
     * part manifest, and its duration scales with the number of parts (capped at
     * 10,000) rather than with file size. The critical section is a metadata
     * commit measured in milliseconds.
     *
     * Implementing the capability here would mean ticking once before or after
     * the request, which renews nothing: a tick cannot run while the SDK is
     * blocked. It would only make the lease look managed on paper while a slow
     * request could still outlive the TTL. So the class declines the capability,
     * and ChunkUploader's post-assembly ownership check covers the remaining
     * risk. Size assemblyLockTtl above your worst-case completion latency.
     */
    private S3ObjectKeyResolver $keys;

    /**
     * The prefix/sanitizer arguments exist only to build the key resolver, so
     * they are plain parameters rather than promoted properties; keeping them as
     * fields would leave write-only properties that can drift out of agreement
     * with the resolver actually deriving the destination key.
     */
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        string $basePrefix = 'chunks/',
        string $finalPrefix = 'uploads/',
        ?PathSanitizer $sanitizer = null,
    ) {
        $this->keys = new S3ObjectKeyResolver($bucket, $basePrefix, $finalPrefix, $sanitizer);
    }

    /**
     * @param UploadState           $state   The upload whose parts are being committed
     * @param ChunkStorageInterface $storage Unused; S3 assembles the parts itself
     *
     * @return string `s3://bucket/key` location of the completed object
     *
     * @throws MissingChunkException when any chunk is missing or has no recorded ETag
     * @throws AssemblyException     when S3 rejects the completion request
     */
    public function assemble(UploadState $state, ChunkStorageInterface $storage): string
    {
        // Bound to a local before the check: the SDK's command shape declares
        // `UploadId` as a non-nullable string, and narrowing a public property
        // through hasMultipartUpload() is not something the analyser can follow.
        $uploadId = $state->multipartUploadId;

        if ($uploadId === null || $uploadId === '') {
            throw new AssemblyException(
                'No S3 multipart upload is in progress for identifier: ' . $state->identifier,
            );
        }

        $parts = $state->sortedParts();

        // S3 silently truncates the object if a part is omitted from the manifest,
        // so a short manifest must fail loudly here rather than produce a
        // plausible-looking but incomplete file.
        if (count($parts) !== $state->totalChunks) {
            $missing = $this->missingPartNumbers($state);

            throw new MissingChunkException(
                sprintf(
                    'Cannot complete upload "%s": %d of %d parts have recorded ETags (missing parts: %s).',
                    $state->identifier,
                    count($parts),
                    $state->totalChunks,
                    $missing === [] ? 'none' : implode(', ', $missing),
                ),
            );
        }

        $key = $this->keys->finalKey($state);

        try {
            $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'Parts' => $parts,
            ]);
        } catch (AwsException $e) {
            throw new AssemblyException(
                'Failed to complete S3 multipart upload: ' . $e->getAwsErrorMessage(),
                0,
                $e,
            );
        }

        return $this->keys->finalUri($state);
    }

    /**
     * Part numbers expected for this upload that have no ETag recorded.
     *
     * @return list<int>
     */
    private function missingPartNumbers(UploadState $state): array
    {
        $missing = [];

        for ($index = 0; $index < $state->totalChunks; $index++) {
            $partNumber = $index + 1;
            if (!isset($state->partEtags[$partNumber])) {
                $missing[] = $partNumber;
            }
        }

        return $missing;
    }
}
