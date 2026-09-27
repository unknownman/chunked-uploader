<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Models;

/**
 * Lightweight, immutable upload state used across the core components.
 *
 * Besides the portable progress fields (which chunk indices have landed), the
 * state carries the two extra facts a cloud-native multipart upload needs in
 * order to be resumable across stateless HTTP requests:
 *
 *  - {@see $multipartUploadId}: the opaque handle the object store issued when
 *    the multipart upload was initiated. Without it a resumed upload has no way
 *    to keep appending to the *same* multipart upload.
 *  - {@see $partEtags}: the per-part entity tags the store returns after each
 *    successful part upload. Completion requires the full ordered set, so they
 *    must survive a process restart just like the progress counters do.
 *
 * Both are driver-agnostic on purpose: S3, MinIO, GCS and Azure all implement
 * the same multipart contract, and keeping the names free of a vendor prefix
 * means the core never has to special-case one provider.
 */
final readonly class UploadState
{
    /**
     * @param string             $identifier
     * @param int                $totalChunks
     * @param int                $totalSize
     * @param string             $originalFilename
     * @param array<int, int>    $uploadedChunks
     * @param bool               $isCompleted
     * @param string|null        $finalPath
     * @param string|null        $multipartUploadId Handle returned by the object
     *                                               store's create-multipart call
     * @param array<int, string> $partEtags        1-based part number => ETag,
     *                                               exactly as the store reported it
     */
    public function __construct(
        public string $identifier,
        public int $totalChunks,
        public int $totalSize,
        public string $originalFilename,
        public array $uploadedChunks = [],
        public bool $isCompleted = false,
        public ?string $finalPath = null,
        public ?string $multipartUploadId = null,
        public array $partEtags = [],
    ) {
    }

    public function hasChunk(int $index): bool
    {
        return in_array($index, $this->uploadedChunks, true);
    }

    public function isComplete(): bool
    {
        return $this->isCompleted || count($this->uploadedChunks) === $this->totalChunks;
    }

    /**
     * Whether a multipart upload has been initiated for this upload.
     */
    public function hasMultipartUpload(): bool
    {
        return $this->multipartUploadId !== null && $this->multipartUploadId !== '';
    }

    public function withUploadedChunk(int $index): self
    {
        if ($this->hasChunk($index)) {
            return $this;
        }

        $chunks = $this->uploadedChunks;
        $chunks[] = $index;
        sort($chunks, SORT_NUMERIC);

        $isComplete = count($chunks) === $this->totalChunks;

        return new self(
            identifier: $this->identifier,
            totalChunks: $this->totalChunks,
            totalSize: $this->totalSize,
            originalFilename: $this->originalFilename,
            uploadedChunks: $chunks,
            isCompleted: $isComplete,
            finalPath: $this->finalPath,
            multipartUploadId: $this->multipartUploadId,
            partEtags: $this->partEtags,
        );
    }

    public function withFinalPath(string $path): self
    {
        return new self(
            identifier: $this->identifier,
            totalChunks: $this->totalChunks,
            totalSize: $this->totalSize,
            originalFilename: $this->originalFilename,
            uploadedChunks: $this->uploadedChunks,
            isCompleted: true,
            finalPath: $path,
            multipartUploadId: $this->multipartUploadId,
            partEtags: $this->partEtags,
        );
    }

    /**
     * Attaches the object store's multipart handle, replacing any previous one.
     *
     * Re-initialisation is legitimate: a reaped or aborted upload is started
     * over from a fresh create-multipart call, and the stale handle must not
     * survive.
     */
    public function withMultipartUploadId(string $multipartUploadId): self
    {
        return new self(
            identifier: $this->identifier,
            totalChunks: $this->totalChunks,
            totalSize: $this->totalSize,
            originalFilename: $this->originalFilename,
            uploadedChunks: $this->uploadedChunks,
            isCompleted: $this->isCompleted,
            finalPath: $this->finalPath,
            multipartUploadId: $multipartUploadId,
            partEtags: $this->partEtags,
        );
    }

    /**
     * Records (or replaces) the ETag a part was stored under.
     *
     * Replacing is intentional and is what makes retries safe: re-uploading a
     * part to the same part number must overwrite the previous ETag, otherwise
     * completion would hand the store a stale tag and produce a corrupt object.
     *
     * @param int $partNumber 1-based part number, as used by the object store
     */
    public function withPartEtag(int $partNumber, string $etag): self
    {
        if ($partNumber < 1) {
            throw new \InvalidArgumentException('Part numbers are 1-based and must be positive.');
        }

        $etags = $this->partEtags;
        $etags[$partNumber] = $etag;
        ksort($etags, SORT_NUMERIC);

        return new self(
            identifier: $this->identifier,
            totalChunks: $this->totalChunks,
            totalSize: $this->totalSize,
            originalFilename: $this->originalFilename,
            uploadedChunks: $this->uploadedChunks,
            isCompleted: $this->isCompleted,
            finalPath: $this->finalPath,
            multipartUploadId: $this->multipartUploadId,
            partEtags: $etags,
        );
    }

    /**
     * Compiles the part map into the ordered structure a completion call needs.
     *
     * CompleteMultipartUpload requires every part listed in strictly ascending
     * PartNumber order; omitting a part silently truncates the final object, and
     * an out-of-order or duplicate entry is rejected outright. Enforcing the
     * invariant here — next to the data, rather than at each call site — is what
     * makes it impossible to build a malformed request.
     *
     * @return list<array{PartNumber: int, ETag: string}>
     */
    public function sortedParts(): array
    {
        $parts = [];

        foreach ($this->partEtags as $partNumber => $etag) {
            $parts[] = ['PartNumber' => (int) $partNumber, 'ETag' => $etag];
        }

        usort($parts, static fn (array $a, array $b): int => $a['PartNumber'] <=> $b['PartNumber']);

        return $parts;
    }
}
