<?php

declare(strict_types=1);

// File: src/Core/Security/MagicByteValidator.php

namespace Resumable\ChunkedUploader\Core\Security;

use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidMimeTypeException;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

/**
 * Validates a chunk's authenticity server-side using finfo magic bytes.
 *
 * Client-supplied MIME headers are never trusted. The first bytes of the
 * temporary file are inspected to determine the true MIME type, which is then
 * checked against the configured whitelist. Only the first 4096 bytes are ever
 * read, guaranteeing constant memory usage regardless of chunk size.
 */
final class MagicByteValidator
{
    private const HEADER_BYTES = 4096;

    /**
     * @param UploaderConfig|null $config Configuration supplying the MIME whitelist
     * @param \finfo|null         $finfo  Optional finfo instance (injectable for tests)
     */
    public function __construct(
        private readonly ?UploaderConfig $config = null,
        private readonly ?\finfo $finfo = null,
    ) {
    }

    /**
     * Detects the MIME type from no more than the first 4096 bytes of a file.
     *
     * @param string $filePath File to inspect
     * @return string Detected MIME type
     * @throws InvalidChunkException When the file cannot be read or finfo fails
     */
    public function detectMimeType(string $filePath): string
    {
        $stream = @fopen($filePath, 'rb');
        if ($stream === false) {
            throw new InvalidChunkException('Unable to open file for MIME inspection.');
        }

        try {
            $header = fread($stream, self::HEADER_BYTES);
            if ($header === false) {
                throw new InvalidChunkException('Unable to read file header for MIME inspection.');
            }

            if ($header === '') {
                throw new InvalidChunkException('File is empty; cannot determine a MIME type.');
            }

            $finfo = $this->finfo ?? new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($header);
            if ($mime === false || $mime === '') {
                throw new InvalidChunkException('Unable to detect file MIME type.');
            }

            // finfo may append a charset parameter (e.g. "text/plain; charset=utf-8"),
            // which would otherwise cause false-positive allow-list rejections.
            // Keep only the trimmed base MIME type.
            $base = trim(explode(';', $mime)[0]);

            return $base;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Checks a file's detected MIME type against an allow-list by reading only
     * its header bytes.
     *
     * @param string   $filePath File to inspect
     * @param string[] $allowedMimes Allowed MIME types
     * @return bool True when the detected type is allowed
     * @throws InvalidMimeTypeException When the detected type is not allowed
     */
    public function validateFile(string $filePath, array $allowedMimes): bool
    {
        $mime = $this->detectMimeType($filePath);
        if (!in_array($mime, $allowedMimes, true)) {
            throw new InvalidMimeTypeException('MIME type is not allowed: ' . $mime);
        }

        return true;
    }

    /**
     * Validates a chunk's magic bytes against a MIME allow-list.
     *
     * Accepts either a Chunk (using its temp file and the configured whitelist)
     * or a raw file path plus an explicit allow-list, keeping the helper easy to
     * use both inside validation rules and in isolation.
     *
     * @param string|Chunk $value File path or Chunk to inspect
     * @param string[]     $allowedMimes Allow-list; only used when $value is a string
     * @return bool True when the detected MIME is allowed
     * @throws InvalidMimeTypeException When the detected type is not allowed
     */
    public function validate(string|Chunk $value, array $allowedMimes = []): bool
    {
        if (is_string($value)) {
            return $this->validateFile($value, $allowedMimes);
        }

        $config = $this->config ?? new UploaderConfig();
        if ($config->allowedMimeTypes === []) {
            return true;
        }

        return $this->validateFile($value->tmpFilePath, $config->allowedMimeTypes);
    }
}
