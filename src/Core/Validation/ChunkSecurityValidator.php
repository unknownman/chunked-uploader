<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation;

use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\ValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\VirusScannerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;

final class ChunkSecurityValidator implements ValidatorInterface, ChunkValidatorInterface
{
    /**
     * @param PathSanitizer $sanitizer Path and identifier security boundary.
     * @param ValidationPipeline $pipeline Validation rules.
     * @param UploadTokenService $tokenService HMAC token verifier.
     * @param VirusScannerInterface|null $scanner Optional malware scanner.
     * @param string $tokenSalt Client binding used when tokens are verified.
     */
    public function __construct(
        private readonly PathSanitizer $sanitizer,
        private readonly ValidationPipeline $pipeline,
        private readonly UploadTokenService $tokenService,
        private readonly ?VirusScannerInterface $scanner = null,
        private readonly string $tokenSalt = '',
    ) {
    }

    /**
     * Validates identifiers, filename, HMAC, configured rules, and malware.
     *
     * @param Chunk $chunk Chunk to validate.
     * @return bool True after all checks pass.
     * @throws InvalidChunkException When chunk metadata is invalid.
     * @throws SecurityViolationException When a security check fails.
     */
    public function validate(Chunk $chunk): bool
    {
        $this->sanitizer->sanitizeIdentifier($chunk->identifier);
        $this->sanitizer->sanitizeFilename($chunk->originalFilename);

        if ($chunk->index < 0 || $chunk->index >= $chunk->totalChunks || $chunk->totalChunks < 1 || $chunk->totalSize < 1) {
            throw new InvalidChunkException('Chunk metadata is out of range.');
        }

        if (!$this->tokenService->verifyToken(
            $chunk->token,
            $chunk->identifier,
            $chunk->totalChunks,
            $chunk->totalSize,
            $this->tokenSalt,
        )) {
            throw new SecurityViolationException('Upload token verification failed.');
        }

        $this->pipeline->validate($chunk);
        $this->scanner?->scan($chunk->tmpFilePath);

        return true;
    }
}
