<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core;

use Psr\Log\LoggerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\UploadManagerInterface;
use Resumable\ChunkedUploader\Core\Drivers\Security\UploadTokenManager;
use Resumable\ChunkedUploader\Core\Exceptions\TokenMismatchException;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;

final class UploadManager implements UploadManagerInterface
{
    public function __construct(
        private readonly ChunkStorageInterface $storage,
        private readonly MetadataRepositoryInterface $metadata,
        private readonly ProgressTrackerInterface $progress,
        private readonly FileAssemblerInterface $assembler,
        private readonly UploadTokenManager $tokenManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function processChunk(Chunk $chunk): UploadState
    {
        // Verify token
        if (!$this->tokenManager->verifyToken($chunk->identifier, $chunk->token)) {
            throw new TokenMismatchException('Upload token mismatch');
        }

        // Ensure initial metadata exists
        $state = $this->metadata->get($chunk->identifier);
        if ($state === null) {
            $state = new UploadState(
                identifier: $chunk->identifier,
                totalChunks: $chunk->totalChunks,
                totalSize: $chunk->totalSize,
                originalFilename: $chunk->originalFilename,
                uploadedChunks: [],
                isCompleted: false,
                finalPath: null,
            );

            $this->metadata->save($state);
        }

        // Idempotency check
        if ($state->hasChunk($chunk->index)) {
            return $state;
        }

        // Persist chunk
        try {
            $this->storage->store($chunk);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to store chunk', ['error' => $e->getMessage()]);
            throw new UploadFailedException('Failed to store chunk: ' . $e->getMessage(), 0, $e);
        }

        // Atomically mark uploaded and obtain updated state
        try {
            $state = $this->metadata->markChunkAsUploaded($chunk->identifier, $chunk->index);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to record chunk upload', ['error' => $e->getMessage()]);
            throw new UploadFailedException('Failed to record uploaded chunk: ' . $e->getMessage(), 0, $e);
        }

        // If complete, assemble and finalize
        if ($this->progress->isComplete($state)) {
            try {
                $finalPath = $this->assembler->assemble($state, $this->storage);

                // cleanup chunks
                $this->storage->deleteChunks($chunk->identifier);

                $state = $state->withFinalPath($finalPath);
                $this->metadata->save($state);
                return $state;
            } catch (\Throwable $e) {
                $this->logger?->error('Failed to assemble upload', ['error' => $e->getMessage()]);
                throw new UploadFailedException('Assembly failed: ' . $e->getMessage(), 0, $e);
            }
        }

        return $state;
    }

    public function cancelUpload(string $identifier): void
    {
        try {
            $this->storage->deleteChunks($identifier);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to delete chunks on cancel', ['error' => $e->getMessage()]);
            throw new UploadFailedException('Failed to delete chunks: ' . $e->getMessage(), 0, $e);
        }

        try {
            $this->metadata->delete($identifier);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to delete metadata on cancel', ['error' => $e->getMessage()]);
            throw new UploadFailedException('Failed to delete metadata: ' . $e->getMessage(), 0, $e);
        }
    }
}
