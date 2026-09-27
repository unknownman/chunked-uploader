<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

/**
 * A chunk's bytes did not match the digest that accompanied them.
 *
 * Extends {@see InvalidChunkException} so every existing `catch` for a rejected
 * chunk keeps working, while giving the pipeline something narrower to key on.
 * That narrowness is the point: `InvalidChunkException` also covers out-of-range
 * metadata and unreadable files, and reporting all of those as checksum failures
 * would make the counter useless for diagnosing corrupted transfers.
 *
 * Carries the backend's own error code where one exists, so a label can
 * distinguish a transient in-transit corruption from a client that always sends
 * a malformed digest.
 */
final class ChecksumMismatchException extends InvalidChunkException
{
    public function __construct(
        string $message,
        private readonly ?string $reasonCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Backend-specific reason, e.g. S3's `BadDigest` or `InvalidDigest`.
     *
     * Deliberately not the human-readable message: exception text embeds detail
     * and varies between backends, which makes it useless as a bounded metric
     * label. `local-mismatch` is used when a driver has no code of its own.
     */
    public function reasonCode(): ?string
    {
        return $this->reasonCode;
    }
}
