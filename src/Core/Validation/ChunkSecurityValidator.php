<?php

declare(strict_types=1);

// File: src/Core/Validation/ChunkSecurityValidator.php

namespace Resumable\ChunkedUploader\Core\Validation;

use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\VirusScannerInterface;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;

/**
 * Composite security validator coordinating identifier hygiene, upload-token
 * verification, an ordered pipeline of validation rules, and optional malware
 * scanning (via a {@see VirusScannerInterface}).
 *
 * This is the single validator injected into {@see UploadManager}. Identifiers
 * and filenames are sanitized, the HMAC upload token is verified in constant
 * time, the pipeline runs (size ceilings, magic bytes, extension/MIME match,
 * checksum), and finally the scanner inspects the payload. Any violation
 * short-circuits before a single byte is persisted.
 */
final class ChunkSecurityValidator implements ChunkValidatorInterface
{
    /**
     * @param PathSanitizer            $sanitizer   Path and identifier security boundary
     * @param ValidationPipeline       $pipeline    Ordered validation rules
     * @param UploadTokenService       $tokenService HMAC token verifier
     * @param VirusScannerInterface|null $scanner    Optional malware scanner
     * @param string                   $tokenSalt   Client binding used when tokens are verified
     */
    public function __construct(
        private readonly PathSanitizer $sanitizer,
        private readonly ValidationPipeline $pipeline,
        private readonly UploadTokenService $tokenService,
        private readonly ?VirusScannerInterface $scanner = null,
        private readonly string $tokenSalt = '',
        private readonly UploaderConfig $config = new UploaderConfig(),
    ) {
    }

    /**
     * Validates identifiers, filename, HMAC, configured rules, and malware.
     *
     * @param Chunk $chunk Chunk to validate
     * @return bool True after all checks pass
     * @throws InvalidChunkException      When chunk metadata is invalid
     * @throws SecurityViolationException When a security check fails
     */
    public function validate(Chunk $chunk): bool
    {
        return $this->validateWithConfig($chunk, $this->config);
    }

    public function validateWithConfig(Chunk $chunk, UploaderConfig $config): bool
    {
        $this->sanitizer->sanitizeIdentifier($chunk->identifier);
        $this->sanitizer->sanitizeFilename($chunk->originalFilename);

        if ($chunk->index < 0 || $chunk->index >= $chunk->totalChunks || $chunk->totalSize < 1) {
            throw new InvalidChunkException('Chunk metadata is out of range.');
        }

        if (
            !$this->tokenService->verifyToken(
                $chunk->token,
                $chunk->identifier,
                $chunk->totalChunks,
                $chunk->totalSize,
                $config->tokenSalt !== '' ? $config->tokenSalt : $this->tokenSalt,
            )
        ) {
            throw new SecurityViolationException('Upload token verification failed.');
        }

        $this->pipeline->validate($chunk, $config);
        $this->scanner?->scan($chunk->tmpFilePath);

        return true;
    }
}
