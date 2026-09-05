<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Security;

use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidMimeTypeException;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

/**
 * Validates a chunk's authenticity server-side using finfo magic bytes.
 *
 * Client-supplied MIME headers are never trusted. The first bytes of the
 * temporary file are inspected to determine the true MIME type, which is then
 * checked against the configured whitelist while the final assembly inherits
 * the verified type.
 */
final class MagicByteValidator implements ChunkValidatorInterface
{
    public function __construct(
        private readonly ?UploaderConfig $config = null,
        private readonly ?PathSanitizer $sanitizer = null,
    ) {
    }

    /**
     * Detects the MIME type from no more than the first 4096 bytes of a file.
     *
     * @param string $filePath File to inspect.
     * @return string Detected MIME type.
     * @throws InvalidChunkException When the file cannot be read or finfo fails.
     */
    public function detectMimeType(string $filePath): string
    {
        $stream = @fopen($filePath, 'rb');
        if ($stream === false) {
            throw new InvalidChunkException('Unable to open file for MIME inspection.');
        }

        try {
            $header = fread($stream, 4096);
            if ($header === false) {
                throw new InvalidChunkException('Unable to read file header for MIME inspection.');
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($header);
            if ($mime === false || $mime === '') {
                throw new InvalidChunkException('Unable to detect file MIME type.');
            }

            return $mime;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Checks a file's detected MIME type against an allow-list.
     *
     * @param string $filePath File to inspect.
     * @param array<string> $allowedMimes Allowed MIME types.
     * @return bool True when the detected type is allowed.
     * @throws InvalidMimeTypeException When the detected type is not allowed.
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
     * Validates a chunk: MIME type from magic bytes must match the whitelist,
     * and the identifier/filename must survive path sanitization.
     *
     * @throws SecurityViolationException when the chunk fails security boundaries
     * @throws InvalidChunkException      when the chunk fails integrity checks
     */
    public function validate(string|Chunk $value, array $allowedMimes = []): bool
    {
        if (is_string($value)) {
            return $this->validateFile($value, $allowedMimes);
        }

        $sanitizer = $this->sanitizer ?? new PathSanitizer();
        $config = $this->config ?? new UploaderConfig();
        $sanitizer->sanitizeIdentifier($value->identifier);
        if ($config->allowedMimeTypes !== []) {
            $this->validateFile($value->tmpFilePath, $config->allowedMimeTypes);
        }

        return true;
    }
}