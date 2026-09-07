<?php

declare(strict_types=1);

// File: src/Core/UploadManager.php

namespace Resumable\ChunkedUploader\Core;

use Psr\Log\LoggerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\RateLimiterInterface;
use Resumable\ChunkedUploader\Core\Contracts\UploadManagerInterface;
use Resumable\ChunkedUploader\Core\Events\ChunkUploadedEvent;
use Resumable\ChunkedUploader\Core\Events\FileAssembledEvent;
use Resumable\ChunkedUploader\Core\Events\UploadFailedEvent;
use Resumable\ChunkedUploader\Core\Exceptions\RateLimitExceededException;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Throwable;

/**
 * Sole coordinator for the chunked upload workflow.
 *
 * Validates each inbound chunk via the injected {@see ChunkValidatorInterface},
 * persists its payload, atomically advances upload state, emits lifecycle
 * events, and triggers assembly the moment the final chunk arrives. All
 * cross-cutting concerns (security, persistence, assembly) are injected, keeping
 * this class a thin orchestrator that is trivially testable and framework
 * agnostic.
 */
final class UploadManager implements UploadManagerInterface
{
    /**
     * @param RateLimiterInterface|null $rateLimiter     Optional chunk-flood guard keyed by client.
     * @param int|null                  $maxChunkAttempts Maximum accepted chunks per rate-limit window.
     * @param int                       $rateLimitWindow  Window length in seconds used by the limiter.
     * @param string                    $rateLimitKey     Request-scoped limiter key (client IP/token).
     */
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
    ) {
    }

    public function processChunk(Chunk $chunk): UploadState
    {
        $this->enforceRateLimit($chunk);

        // Step 1: Validate the chunk before any disk I/O.
        try {
            $this->validator->validate($chunk);
        } catch (Throwable $e) {
            $this->logger?->warning('Chunk validation failed', [
                'identifier' => $chunk->identifier,
                'index' => $chunk->index,
                'error' => $e->getMessage(),
            ]);
            $this->dispatchFailure($this->currentState($chunk->identifier), $e);
            throw $e;
        }

        // Step 2: Load (or seed) the upload state.
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

            try {
                $this->metadata->save($state);
            } catch (Throwable $e) {
                $this->logger?->error('Failed to seed upload state', ['error' => $e->getMessage()]);
                $this->dispatchFailure($state, $e);
                throw new UploadFailedException('Failed to initialize upload state: ' . $e->getMessage(), 0, $e);
            }
        }

        // Step 3: Idempotency check — an already-accepted chunk is a no-op.
        if ($state->hasChunk($chunk->index)) {
            if ($this->progress->isComplete($state) && $state->finalPath === null) {
                $state = $this->tryAssembleAndFinalize($state, $chunk);
            }

            return $state;
        }

        // Step 4: Persist the chunk payload.
        try {
            $this->storage->store($chunk);
        } catch (Throwable $e) {
            $this->logger?->error('Failed to store chunk', ['error' => $e->getMessage()]);
            $this->dispatchFailure($state, $e);
            throw new UploadFailedException('Failed to store chunk: ' . $e->getMessage(), 0, $e);
        }

        // Step 5: Atomically advance progress and persist the updated state.
        try {
            $state = $this->metadata->markChunkAsUploaded($chunk->identifier, $chunk->index);
        } catch (Throwable $e) {
            $this->logger?->error('Failed to record chunk upload', ['error' => $e->getMessage()]);
            $this->dispatchFailure($state, $e);
            throw new UploadFailedException('Failed to record uploaded chunk: ' . $e->getMessage(), 0, $e);
        }

        // Step 6: Emit the chunk-accepted lifecycle event.
        $this->dispatcher->dispatch(new ChunkUploadedEvent($state, $chunk));

        // Step 7: If complete, assemble and finalize.
        if ($this->progress->isComplete($state)) {
            $state = $this->tryAssembleAndFinalize($state, $chunk);
        }

        return $state;
    }

    public function cancelUpload(string $identifier): void
    {
        try {
            $this->storage->deleteChunks($identifier);
        } catch (Throwable $e) {
            $this->logger?->error('Failed to delete chunks on cancel', ['error' => $e->getMessage()]);
            $this->dispatchFailure($this->currentState($identifier), $e);
            throw new UploadFailedException('Failed to delete chunks: ' . $e->getMessage(), 0, $e);
        }

        try {
            $this->metadata->delete($identifier);
        } catch (Throwable $e) {
            $this->logger?->error('Failed to delete metadata on cancel', ['error' => $e->getMessage()]);
            $this->dispatchFailure($this->currentState($identifier), $e);
            throw new UploadFailedException('Failed to delete metadata: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getStatus(string $identifier): ?UploadState
    {
        return $this->metadata->get($identifier);
    }

    /**
     * Assembles the completed upload, cleans up its chunks, persists the final
     * path, and emits the assembled event.
     *
     * @return UploadState The finalized state carrying the final artifact path
     * @throws UploadFailedException when assembly or finalization fails
     */
    private function tryAssembleAndFinalize(UploadState $state, Chunk $chunk): UploadState
    {
        try {
            $finalPath = $this->assembler->assemble($state, $this->storage);

            $this->storage->deleteChunks($chunk->identifier);

            $finalized = $state->withFinalPath($finalPath);
            $this->metadata->save($finalized);

            $this->dispatcher->dispatch(new FileAssembledEvent($finalized, $finalPath));

            return $finalized;
        } catch (Throwable $e) {
            $this->logger?->error('Failed to assemble upload', ['error' => $e->getMessage()]);
            $this->dispatchFailure($state, $e);
            throw new UploadFailedException('Assembly failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Guards against chunk-flood denial of service.
     *
     * When a rate limiter is configured, each inbound chunk is recorded against
     * a request-scoped key. Once the configured ceiling is crossed within the
     * window, the request is rejected before any validation or disk I/O.
     */
    private function enforceRateLimit(Chunk $chunk): void
    {
        if ($this->rateLimiter === null || $this->maxChunkAttempts === null) {
            return;
        }

        $this->rateLimiter->hit($this->rateLimitKey, $this->rateLimitWindow);
        if ($this->rateLimiter->tooManyAttempts($this->rateLimitKey, $this->maxChunkAttempts)) {
            throw new RateLimitExceededException(
                'Chunk upload rejected: rate limit exceeded for ' . $this->rateLimitKey,
            );
        }
    }

    /**
     * Returns the current persisted state (or a minimal placeholder) so failure
     * events always carry meaningful context.
     */
    private function currentState(string $identifier): ?UploadState
    {
        try {
            return $this->metadata->get($identifier);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Dispatches an {@see UploadFailedEvent} for the given state and exception,
     * never letting a listener failure mask the original error.
     */
    private function dispatchFailure(?UploadState $state, Throwable $exception): void
    {
        try {
            $this->dispatcher->dispatch(new UploadFailedEvent($state, $exception));
        } catch (Throwable) {
            // A listener failure must not mask the original exception.
        }
    }
}
