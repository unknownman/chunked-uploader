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
use Resumable\ChunkedUploader\Core\Contracts\LockManagerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\RateLimiterInterface;
use Resumable\ChunkedUploader\Core\Events\ChunkUploadedEvent;
use Resumable\ChunkedUploader\Core\Events\FileAssembledEvent;
use Resumable\ChunkedUploader\Core\Events\UploadFailedEvent;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkUploaderException;
use Resumable\ChunkedUploader\Core\Exceptions\RateLimitExceededException;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Throwable;

/** Coordinates validation, persistence, progress, assembly, and cleanup. */
final class ChunkUploader implements ChunkUploaderInterface
{
    /**
     * Namespace for the per-upload assembly mutex.
     *
     * Prefixed rather than keyed by the bare identifier so an assembly lock can
     * never collide with a future lock kind, and so a caller-supplied
     * identifier cannot be mistaken for a different resource's lock.
     */
    public const ASSEMBLY_LOCK_PREFIX = 'assembly:lock:';

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
        private readonly ?LockManagerInterface $lockManager = null,
        private readonly int $assemblyLockTtl = 60,
        private readonly int $assemblyWaitSeconds = 10,
        private readonly int $assemblyPollMicroseconds = 100_000,
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
        } catch (ChunkUploaderException $e) {
            // A driver that already classified the failure -- a corrupt part
            // rejected by S3's digest check, say -- keeps its own type. Flattening
            // it into UploadFailedException would tell a client to retry a
            // malformed request forever, when retrying identical bytes cannot help.
            $this->dispatchFailure($state, $e);
            throw $e;
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

    /**
     * Serializes finalization for one upload across every application server.
     *
     * Without coordination this method is a classic time-of-check/time-of-use
     * race: two nodes can each receive one of the last chunks, both observe a
     * complete upload, and both enter the assembler. The consequences differ by
     * backend but are damaging in every one -- for S3 it is two concurrent
     * CompleteMultipartUpload calls on one upload id (one fails with a
     * NoSuchUpload, and the loser can then abort or clean up the winner's
     * parts); for local storage it is two processes writing the same
     * destination file. Only one node may run the critical section.
     *
     * When no lock manager is configured the behaviour is unchanged, which
     * keeps single-node and in-memory deployments working exactly as before.
     */
    private function tryAssembleAndFinalize(UploadState $state, Chunk $chunk): UploadState
    {
        $lockKey = self::ASSEMBLY_LOCK_PREFIX . $chunk->identifier;

        try {
            if ($this->lockManager === null) {
                return $this->assembleIfNeeded($state, $chunk);
            }

            if ($this->lockManager->acquire($lockKey, $this->assemblyLockTtl)) {
                try {
                    return $this->assembleIfNeeded($state, $chunk);
                } finally {
                    $this->releaseLock($lockKey);
                }
            }

            $this->logger?->info('Assembly already in progress for this upload; waiting for the owning node', [
                'identifier' => $chunk->identifier,
            ]);

            return $this->awaitAssembly($state, $chunk, $lockKey, $this->lockManager);
        } catch (Throwable $e) {
            $this->dispatchFailure($state, $e);
            throw new UploadFailedException('Assembly failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The critical section: re-check, assemble once, and publish the result.
     *
     * The freshness re-read must stay inside the lock. The state handed in by
     * the caller was read *before* the lock was acquired, so a node that has
     * held the lock for a while may be working from an upload that another node
     * already finalized. Re-reading after acquisition is what makes the
     * check-then-act sequence atomic with respect to every other node.
     */
    private function assembleIfNeeded(UploadState $state, Chunk $chunk): UploadState
    {
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
    }

    /**
     * Handles the losing side of a contended assembly.
     *
     * Polls for the winner's result and opportunistically takes the lock over
     * if it becomes free, which covers the ordinary case of a duplicated
     * request arriving moments after the real one. If the budget expires the
     * upload is genuinely still in flight, so the freshest state is returned
     * rather than an error: reporting failure for work that another node is
     * actively completing would be misleading, and `finalPath` remains null so
     * a status poll reports the truth.
     */
    private function awaitAssembly(
        UploadState $state,
        Chunk $chunk,
        string $lockKey,
        LockManagerInterface $lockManager,
    ): UploadState {
        $deadline = microtime(true) + $this->assemblyWaitSeconds;

        while (microtime(true) < $deadline) {
            $current = $this->currentState($chunk->identifier);
            if ($current !== null && $current->finalPath !== null) {
                return $current;
            }

            usleep($this->assemblyPollMicroseconds);

            // Retry acquisition: the holder may have finished, or crashed and
            // let its lease lapse, in which case this node finishes the job.
            if ($lockManager->acquire($lockKey, $this->assemblyLockTtl)) {
                try {
                    return $this->assembleIfNeeded($state, $chunk);
                } finally {
                    $this->releaseLock($lockKey);
                }
            }
        }

        $this->logger?->info('Gave up waiting for concurrent assembly; returning current upload status', [
            'identifier' => $chunk->identifier,
            'waitedSeconds' => $this->assemblyWaitSeconds,
        ]);

        return $this->currentState($chunk->identifier) ?? $state;
    }

    /**
     * Releases the lock without ever throwing.
     *
     * Called from `finally` blocks, where an exception would replace -- and so
     * hide -- the failure that triggered the unwind. Lock ownership is already
     * time-bounded by the TTL, so a failed release is not correctness-critical.
     */
    private function releaseLock(string $lockKey): void
    {
        try {
            $this->lockManager?->release($lockKey);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to release upload assembly lock', [
                'error' => $e->getMessage(),
            ]);
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
