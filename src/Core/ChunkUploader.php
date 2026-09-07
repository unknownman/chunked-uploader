<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core;

use Psr\Log\LoggerInterface;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkUploaderInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\RateLimiterInterface;
use Resumable\ChunkedUploader\Core\Events\ChunkUploadedEvent;
use Resumable\ChunkedUploader\Core\Events\FileAssembledEvent;
use Resumable\ChunkedUploader\Core\Events\UploadFailedEvent;
use Resumable\ChunkedUploader\Core\Exceptions\RateLimitExceededException;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Throwable;

/** Coordinates validation, persistence, progress, assembly, and cleanup. */
final class ChunkUploader implements ChunkUploaderInterface
{
    public function __construct(
        private readonly ChunkStorageInterface $storage,
        private readonly MetadataRepositoryInterface $metadata,
        private readonly ProgressTrackerInterface $progress,
        private readonly FileAssemblerInterface $assembler,
        private readonly ChunkValidatorInterface $validator,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RateLimiterInterface $rateLimiter = null,
        private readonly ?int $maxChunkAttempts = null,
        private readonly int $rateLimitWindow = 60,
        private readonly string $rateLimitKey = 'chunked-uploader:chunks',
        private readonly UploaderConfig $config = new UploaderConfig(),
    ) {
    }

    public function processChunk(Chunk $chunk, ?UploaderConfig $config = null): UploadState
    {
        $config ??= $this->config;
        $this->enforceRateLimit();

        try {
            if (method_exists($this->validator, 'validateWithConfig')) {
                $this->validator->validateWithConfig($chunk, $config);
            } else {
                $this->validator->validate($chunk);
            }
        } catch (Throwable $e) {
            $this->logger?->warning('Chunk validation failed', ['identifier' => $chunk->identifier, 'index' => $chunk->index, 'error' => $e->getMessage()]);
            $this->dispatchFailure($this->currentState($chunk->identifier), $e);
            throw $e;
        }

        $state = $this->metadata->get($chunk->identifier);
        if ($state === null) {
            $state = new UploadState($chunk->identifier, $chunk->totalChunks, $chunk->totalSize, $chunk->originalFilename, [], false, null);
            try {
                $this->metadata->save($state);
            } catch (Throwable $e) {
                $this->dispatchFailure($state, $e);
                throw new UploadFailedException('Failed to initialize upload state: ' . $e->getMessage(), 0, $e);
            }
        }

        if ($state->hasChunk($chunk->index)) {
            return $this->progress->isComplete($state) && $state->finalPath === null
                ? $this->tryAssembleAndFinalize($state, $chunk)
                : $state;
        }

        try {
            $this->storage->store($chunk);
        } catch (Throwable $e) {
            $this->dispatchFailure($state, $e);
            throw new UploadFailedException('Failed to store chunk: ' . $e->getMessage(), 0, $e);
        }

        try {
            $state = $this->metadata->markChunkAsUploaded($chunk->identifier, $chunk->index);
        } catch (Throwable $e) {
            try {
                $this->storage->deleteChunk($chunk);
            } catch (Throwable $cleanupError) {
                $this->logger?->warning('Failed to roll back orphaned chunk', ['error' => $cleanupError->getMessage()]);
            }
            $this->dispatchFailure($state, $e);
            throw new UploadFailedException('Failed to record uploaded chunk: ' . $e->getMessage(), 0, $e);
        }

        $this->dispatcher->dispatch(new ChunkUploadedEvent($state, $chunk));
        return $this->progress->isComplete($state)
            ? $this->tryAssembleAndFinalize($state, $chunk)
            : $state;
    }

    public function cancelUpload(string $identifier): void
    {
        try {
            $this->storage->deleteChunks($identifier);
            $this->metadata->delete($identifier);
        } catch (Throwable $e) {
            $this->dispatchFailure($this->currentState($identifier), $e);
            throw new UploadFailedException('Failed to cancel upload: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getStatus(string $identifier): ?UploadState
    {
        return $this->metadata->get($identifier);
    }

    private function tryAssembleAndFinalize(UploadState $state, Chunk $chunk): UploadState
    {
        try {
            $current = $this->metadata->get($chunk->identifier);
            if ($current !== null) {
                if ($current->finalPath !== null || !$this->progress->isComplete($current)) {
                    return $current;
                }
                $state = $current;
            }

            $finalPath = $this->assembler->assemble($state, $this->storage);
            $this->storage->deleteChunks($chunk->identifier);
            $finalized = $state->withFinalPath($finalPath);
            $this->metadata->save($finalized);
            $this->dispatcher->dispatch(new FileAssembledEvent($finalized, $finalPath));
            return $finalized;
        } catch (Throwable $e) {
            $this->dispatchFailure($state, $e);
            throw new UploadFailedException('Assembly failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function enforceRateLimit(): void
    {
        if ($this->rateLimiter === null || $this->maxChunkAttempts === null) {
            return;
        }
        $this->rateLimiter->hit($this->rateLimitKey, $this->rateLimitWindow);
        if ($this->rateLimiter->tooManyAttempts($this->rateLimitKey, $this->maxChunkAttempts)) {
            throw new RateLimitExceededException('Chunk upload rejected: rate limit exceeded for ' . $this->rateLimitKey);
        }
    }

    private function currentState(string $identifier): ?UploadState
    {
        try {
            return $this->metadata->get($identifier);
        } catch (Throwable) {
            return null;
        }
    }

    private function dispatchFailure(?UploadState $state, Throwable $exception): void
    {
        try {
            $this->dispatcher->dispatch(new UploadFailedEvent($state, $exception));
        } catch (Throwable) {
        }
    }
}
