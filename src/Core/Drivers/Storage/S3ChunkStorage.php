<?php

declare(strict_types=1);

// File: src/Core/Drivers/Storage/S3ChunkStorage.php

namespace Resumable\ChunkedUploader\Core\Drivers\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\StorageException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;

/**
 * Stores chunk artifacts in an S3-compatible object store as a native
 * multipart upload, so the object store does the concatenation.
 *
 * The previous implementation stored one object per chunk and reassembled the
 * file in PHP. That moved every byte through the application twice and made the
 * server hold an open handle to the finished object. S3's own multipart API
 * removes both problems:
 *
 *   1. first chunk seen  -> CreateMultipartUpload  (records the UploadId)
 *   2. every chunk      -> UploadPart            (returns an ETag)
 *   3. all chunks in    -> CompleteMultipartUpload (with the ordered ETag list)
 *
 * Two pieces of state must therefore survive between requests: the UploadId and
 * the part-number-to-ETag map. Both live in the metadata repository, so a client
 * that resumes an hour later, or lands on a different web node, continues the
 * *same* multipart upload rather than starting a new one.
 *
 * Ordering is deliberate: the ETag is persisted inside store() *before* the
 * caller marks the chunk as uploaded. The reverse order can leave a chunk
 * recorded as complete whose ETag was lost to a crash, and the upload could then
 * never be completed.
 *
 * CreateMultipartUpload is issued lazily, on the first chunk, rather than when
 * the upload token is minted. Minting is a pure HMAC operation that may be
 * repeated or abandoned, and initiating eagerly would leak a billable multipart
 * upload for every token that was never used.
 */
final class S3ChunkStorage implements ChunkStorageInterface
{
    /**
     * AWS `DeleteObjects` accepts at most 1000 keys per request.
     */
    private const MAX_DELETE_BATCH = 1000;

    /**
     * S3 rejects any part below 5 MiB except the last one, and allows at most
     * 10 000 parts per upload. The limit is checked up-front so a misconfigured
     * client fails with an actionable message instead of an opaque
     * `EntityTooSmall` at completion time.
     */
    private const MIN_PART_BYTES = 5 * 1024 * 1024;

    private const MAX_PARTS = 10000;

    private S3ObjectKeyResolver $keys;

    /**
     * The prefix/sanitizer arguments are consumed only to build {@see $keys},
     * so they are plain parameters rather than promoted properties: keeping them
     * as fields would leave three write-only properties whose values can drift
     * out of agreement with the resolver that actually derives the keys.
     *
     * @param S3Client                         $client      Configured S3 client
     * @param string                           $bucket      Destination bucket
     * @param string                           $basePrefix  Object-key namespace (no leading slash)
     * @param PathSanitizer|null               $sanitizer   Identifier boundary used for keys
     * @param MetadataRepositoryInterface|null $metadata    Persists the UploadId and part ETags;
     *                                                      required for the multipart flow
     * @param string                           $finalPrefix Key prefix for the assembled object
     */
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        string $basePrefix = 'chunks/',
        ?PathSanitizer $sanitizer = null,
        private readonly ?MetadataRepositoryInterface $metadata = null,
        string $finalPrefix = 'uploads/',
    ) {
        $this->keys = new S3ObjectKeyResolver($bucket, $basePrefix, $finalPrefix, $sanitizer);
    }

    /**
     * Uploads one chunk as a part of the upload's multipart upload.
     *
     * When the client sent a digest for this chunk, it is forwarded so S3
     * verifies the bytes it actually received. That moves integrity checking
     * off the PHP server's disk and onto the wire, which is the only place a
     * corrupted transfer can be observed; see {@see S3ChecksumCodec}.
     *
     * @throws StorageException    on an unrecoverable SDK error
     * @throws InvalidChunkException when the digest is unusable or S3 rejects it
     */
    public function store(Chunk $chunk): void
    {
        $state = $this->requireState($chunk->identifier);
        $uploadId = $this->resolveUploadId($state, $chunk);

        $stream = fopen($chunk->tmpFilePath, 'rb');
        if ($stream === false) {
            throw new StorageException('Unable to open chunk source for S3 upload');
        }

        try {
            /**
             * `uploadPart()` takes a sealed array shape, and a spread collapses the
             * key/value pairing into one union, leaving the analyser unable to show
             * that e.g. ContentLength is an int rather than the string the Body
             * union also permits. Stating the three concrete shapes keeps the
             * correlation provable; if the SDK adds or retires a key this
             * annotation is what fails first.
             *
             * Built inside the try so that a digest rejected by ChunkChecksum
             * still unwinds through the finally below and closes $stream.
             *
             * @var array{Bucket: string, Key: string, UploadId: string, PartNumber: int, Body: resource, ContentLength: int}
             *      |array{Bucket: string, Key: string, UploadId: string, PartNumber: int, Body: resource, ContentLength: int, ChecksumSHA256: string, ChecksumAlgorithm: 'SHA256'}
             *      |array{Bucket: string, Key: string, UploadId: string, PartNumber: int, Body: resource, ContentLength: int, ContentMD5: string}
             */
            $args = [
                'Bucket' => $this->bucket,
                'Key' => $this->keys->finalKey($state),
                'UploadId' => $uploadId,
                // S3 part numbers are 1-based; chunk indices are 0-based. Off-by-one
                // here silently shifts every part boundary, so the mapping is
                // deliberately explicit rather than arithmetic elsewhere.
                'PartNumber' => $chunk->index + 1,
                'Body' => $stream,
                'ContentLength' => $chunk->chunkSize,
                // Empty when the client sent no digest, which leaves the part
                // unverified exactly as before.
                ...S3ChecksumCodec::requestParameters($chunk),
            ];

            $result = $this->client->uploadPart($args);
        } catch (AwsException $e) {
            // A digest failure is a client/data problem, not an infrastructure
            // outage, so it becomes a typed domain error before the generic
            // storage wrapper can flatten it into an opaque message.
            $errorCode = $e->getAwsErrorCode();

            if (S3ChecksumCodec::isDigestFailure($errorCode)) {
                S3ChecksumCodec::throwForAwsError((string) $errorCode, $e->getAwsErrorMessage());
            }

            throw new StorageException('Failed to upload chunk part to S3: ' . $e->getAwsErrorMessage(), 0, $e);
        } finally {
            fclose($stream);
        }

        $etag = $result['ETag'] ?? null;
        if (!is_string($etag) || $etag === '') {
            throw new StorageException('S3 did not return an ETag for part ' . ($chunk->index + 1));
        }

        // Persist before the caller marks the chunk uploaded: a recorded chunk
        // with a missing ETag is unrecoverable, whereas an orphaned ETag is harmless.
        $this->metadata?->recordPartEtag($chunk->identifier, $chunk->index + 1, $etag);
    }

    /**
     * Not supported: parts of a multipart upload are not addressable objects.
     *
     * S3 exposes no read API for an individual part, so the streaming assembler
     * cannot be used with this driver at all -- reassembly happens server-side in
     * CompleteMultipartUpload. Failing loudly here keeps a misconfigured driver
     * pairing from assembling a truncated file.
     */
    public function getChunkStream(Chunk $chunk): mixed
    {
        throw new StorageException(
            'S3 multipart parts cannot be read individually; assemble with S3MultipartAssembler '
            . '(CompleteMultipartUpload) instead of the streaming assembler.',
        );
    }

    /**
     * Aborts the multipart upload and removes any legacy chunk objects.
     *
     * Abandoning a multipart upload without aborting it leaves billable parts
     * behind, invisible to a normal object listing, until a lifecycle rule or
     * {@see cleanOrphanedChunks()} collects them.
     */
    public function deleteChunks(string $identifier): void
    {
        $state = $this->metadata?->get($identifier);

        if ($state?->hasMultipartUpload()) {
            $this->abortMultipartUpload($state, $state->multipartUploadId ?? '');
        }

        $this->purgeLegacyObjects($identifier);
    }

    /**
     * Parts are addressed by number within a multipart upload, not as objects,
     * so there is nothing to delete individually. Abandoning the upload is what
     * discards them; see {@see deleteChunks()}.
     */
    public function deleteChunk(Chunk $chunk): void
    {
    }

    /**
     * Reaps abandoned uploads in two passes, because a multipart upload and its
     * parts are invisible to ListObjectsV2.
     *
     * A listing-only sweep silently fails to stop the storage cost of a client
     * that died mid-upload -- the parts exist, they are just not returned by the
     * object listing. Hence the explicit ListMultipartUploads pass.
     *
     * @return int Number of artifacts removed
     */
    public function cleanOrphanedChunks(int $ttlSeconds): int
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('TTL must be a positive number of seconds.');
        }

        $cutoff = time() - $ttlSeconds;
        $removed = $this->abortStaleMultipartUploads($cutoff);
        $removed += $this->purgeStaleObjects($cutoff);

        return $removed;
    }

    /**
     * Aborts multipart uploads initiated before the cutoff.
     *
     * @return int Number of uploads aborted
     */
    private function abortStaleMultipartUploads(int $cutoff): int
    {
        $aborted = 0;

        try {
            $paginator = $this->client->getPaginator('ListMultipartUploads', [
                'Bucket' => $this->bucket,
                'Prefix' => $this->keys->rootPrefix(),
            ]);

            foreach ($paginator as $page) {
                foreach ($this->rows($page, 'Uploads') as $upload) {
                    $initiated = $this->scalar($upload, 'Initiated');
                    $uploadId = $this->scalar($upload, 'UploadId');
                    if ($initiated === null || $uploadId === null) {
                        continue;
                    }

                    $timestamp = $this->toTimestamp($initiated);
                    if ($timestamp === null || $timestamp > $cutoff) {
                        continue;
                    }

                    $this->abortUploadByKey((string) ($this->scalar($upload, 'Key') ?? ''), $uploadId);
                    $aborted++;
                }
            }
        } catch (AwsException $e) {
            throw new StorageException('Failed to list multipart uploads in S3: ' . $e->getAwsErrorMessage(), 0, $e);
        }

        return $aborted;
    }

    /**
     * Deletes legacy single-object chunks last modified before the cutoff.
     *
     * @return int Number of objects deleted
     */
    private function purgeStaleObjects(int $cutoff): int
    {
        $removed = 0;

        try {
            $paginator = $this->client->getPaginator('ListObjectsV2', [
                'Bucket' => $this->bucket,
                'Prefix' => $this->keys->rootPrefix(),
            ]);

            foreach ($paginator as $page) {
                $stale = [];
                foreach ($this->rows($page, 'Contents') as $object) {
                    $lastModified = $this->scalar($object, 'LastModified');
                    if ($lastModified === null) {
                        continue;
                    }

                    $timestamp = $this->toTimestamp($lastModified);
                    $key = $this->scalar($object, 'Key');
                    if ($timestamp !== null && $timestamp <= $cutoff && $key !== null) {
                        $stale[] = ['Key' => $key];
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
     * Aborts a single multipart upload, tolerating one that is already gone.
     */
    private function abortMultipartUpload(UploadState $state, string $uploadId): void
    {
        $this->abortUploadByKey($this->keys->finalKey($state), $uploadId);
    }

    /**
     * Aborts the multipart upload identified by a literal key.
     *
     * The orphan sweeper already has the key from ListMultipartUploads and has no
     * metadata state to resolve it from, hence the key-based entry point.
     */
    private function abortUploadByKey(string $key, string $uploadId): void
    {
        try {
            $this->client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
        } catch (AwsException $e) {
            // NoSuchUpload simply means the upload was already completed or
            // aborted, which is the desired end state either way.
            if ($e->getAwsErrorCode() === 'NoSuchUpload') {
                return;
            }

            throw new StorageException('Failed to abort S3 multipart upload: ' . $e->getAwsErrorMessage(), 0, $e);
        }
    }

    /**
     * Removes pre-multipart chunk objects for one upload.
     *
     * Only needed to clean up after an in-flight upgrade; no new code writes them.
     */
    private function purgeLegacyObjects(string $identifier): void
    {
        $prefix = $this->keys->uploadPrefix($identifier);

        try {
            $paginator = $this->client->getPaginator('ListObjectsV2', [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ]);

            foreach ($paginator as $page) {
                $this->purgeKeys($this->keyRows($page));
            }
        } catch (AwsException $e) {
            throw new StorageException('Failed to delete chunks from S3: ' . $e->getAwsErrorMessage(), 0, $e);
        }
    }

    /**
     * Returns the upload's multipart handle, initiating one on first use.
     */
    private function resolveUploadId(UploadState $state, Chunk $chunk): string
    {
        if ($this->metadata === null) {
            throw new StorageException(
                'S3ChunkStorage requires a MetadataRepositoryInterface to track multipart uploads.',
            );
        }

        $this->assertWithinS3Limits($chunk);

        if ($state->hasMultipartUpload()) {
            return $state->multipartUploadId ?? '';
        }

        $created = $this->createMultipartUpload($state);
        $this->metadata->save($state->withMultipartUploadId($created));

        return $created;
    }

    private function requireState(string $identifier): UploadState
    {
        $state = $this->metadata?->get($identifier);

        if ($state === null) {
            throw new StorageException('No upload state found for identifier: ' . $identifier);
        }

        return $state;
    }

    /**
     * Rejects configurations S3 cannot satisfy, naming the fix.
     */
    private function assertWithinS3Limits(Chunk $chunk): void
    {
        if ($chunk->index >= self::MAX_PARTS) {
            throw new StorageException(
                'Upload exceeds the S3 multipart limit of ' . self::MAX_PARTS . ' parts; increase the client chunk size.',
            );
        }

        // Only non-final parts carry the 5 MiB minimum, and a single-chunk upload
        // is entirely exempt.
        if ($chunk->totalChunks > 1 && $chunk->index < $chunk->totalChunks - 1 && $chunk->chunkSize < self::MIN_PART_BYTES) {
            throw new StorageException(
                'S3 requires parts of at least ' . (self::MIN_PART_BYTES / 1048576) . ' MiB, but chunk ' . $chunk->index
                . ' is ' . $chunk->chunkSize . ' bytes; increase the client chunk size.',
            );
        }
    }

    private function createMultipartUpload(UploadState $state): string
    {
        try {
            $result = $this->client->createMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $this->keys->finalKey($state),
            ]);
        } catch (AwsException $e) {
            throw new StorageException('Failed to start S3 multipart upload: ' . $e->getAwsErrorMessage(), 0, $e);
        }

        $uploadId = $result['UploadId'] ?? null;
        if (!is_string($uploadId) || $uploadId === '') {
            throw new StorageException('S3 did not return an UploadId.');
        }

        return $uploadId;
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

    /**
     * Extracts deletable `{Key: string}` rows from a raw paginator page.
     *
     * Rows without a usable string key are dropped rather than forwarded to
     * DeleteObjects, which would fail the whole batch on a single bad entry.
     *
     * @return list<array{Key: string}>
     */
    private function keyRows(mixed $page): array
    {
        $keys = [];
        foreach ($this->rows($page, 'Contents') as $row) {
            $key = $this->scalar($row, 'Key');
            if ($key !== null) {
                $keys[] = ['Key' => $key];
            }
        }

        return $keys;
    }

    /**
     * Extracts one list of object rows from a raw paginator page.
     *
     * The SDK types listing pages as `mixed`, so iterating a page directly
     * forces a cast at every access and lets a malformed page surface as a
     * runtime TypeError deep inside a cleanup job. Normalising here keeps the
     * sweeper total: a page that is not a list of arrays is treated as empty.
     *
     * @param mixed $page Raw paginator page
     * @param string $field Key holding the row list
     *
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $page, string $field): array
    {
        if (!is_array($page)) {
            return [];
        }

        $rows = $page[$field] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    /**
     * Reads a single string-ish field from a listing row, rejecting anything else.
     *
     * @param array<string, mixed> $row
     */
    private function scalar(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return null;
    }

    /**
     * Normalizes an S3 timestamp to a unix epoch.
     *
     * Accepts only a DateTimeInterface or a non-empty string. The listing
     * payloads are typed as mixed by the SDK, and casting an arbitrary value
     * (an array, a bool) to string here would fabricate a timestamp and make the
     * sweeper delete objects on a bogus comparison.
     */
    private function toTimestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }
}
