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
use Resumable\ChunkedUploader\Core\Contracts\HeartbeatAwareAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\LockManagerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetricsTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\RateLimiterInterface;
use Resumable\ChunkedUploader\Core\Drivers\Metrics\NullMetricsTracker;
use Resumable\ChunkedUploader\Core\Events\ChunkUploadedEvent;
use Resumable\ChunkedUploader\Core\Events\FileAssembledEvent;
use Resumable\ChunkedUploader\Core\Events\UploadFailedEvent;
use Resumable\ChunkedUploader\Core\Exceptions\AssemblyLeaseLostException;
use Resumable\ChunkedUploader\Core\Exceptions\ChecksumMismatchException;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkUploaderException;
use Resumable\ChunkedUploader\Core\Exceptions\RateLimitExceededException;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Locking\AssemblyHeartbeat;
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
        private readonly MetricsTrackerInterface $metrics = new NullMetricsTracker(),
        private readonly ?\Closure $clock = null,
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
        } catch (ChecksumMismatchException $e) {
            // Caught separately from the generic validation failure: a digest
            // mismatch is a data-integrity event that clusters by cause, while
            // an out-of-range index is a client bug. Merged into one counter
            // they would be indistinguishable.
            $this->recordChecksumMismatch($e);
            $this->logger?->warning('Chunk checksum rejected', [
                'identifier' => $chunk->identifier,
                'index' => $chunk->index,
                'error' => $e->getMessage(),
            ]);
            $this->dispatchFailure($this->currentState($chunk->identifier), $e);
            throw $e;
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
        } catch (ChecksumMismatchException $e) {
            $this->recordChecksumMismatch($e);
            $this->dispatchFailure($state, $e);
            throw $e;
        } catch (ChunkUploaderException $e) {
            // A driver that already classified the failure keeps its own type.
            // Flattening it into UploadFailedException would tell a client to
            // retry a malformed request forever, when retrying identical bytes
            // cannot help.
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

        // After the metadata write, not after the storage write: a chunk whose
        // progress record failed is rolled back and deleted, so counting it would
        // report bytes that no longer exist.
        //
        // A non-positive size is skipped rather than recorded as zero. Callers
        // legitimately build chunks without a known length, and a zero-byte
        // observation is indistinguishable from a genuinely empty chunk, which
        // would quietly drag down any throughput average built from this.
        if ($chunk->chunkSize > 0) {
            $this->metrics->incrementChunkUploaded($chunk->chunkSize);
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
                return $this->runCriticalSection($state, $chunk, $lockKey, $this->lockManager);
            }

            $this->logger?->info('Assembly already in progress for this upload; waiting for the owning node', [
                'identifier' => $chunk->identifier,
            ]);

            // A real collision: another node is inside the critical section for
            // this identifier. Worth counting, because a rising rate points at a
            // TTL too short for the assembler or a hot upload being retried.
            $this->metrics->incrementLockCollision($chunk->identifier);

            return $this->awaitAssembly($state, $chunk, $lockKey, $this->lockManager);
        } catch (AssemblyLeaseLostException $e) {
            // Already a classified, actionable condition, so it is re-raised
            // rather than wrapped. Flattening it into UploadFailedException would
            // tell the client its upload failed and invite a retry of data that is
            // intact and that another node is finalizing right now -- the exact
            // advice that turns a benign race into a retry storm.
            throw $e;
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
    private function assembleIfNeeded(
        UploadState $state,
        Chunk $chunk,
        ?AssemblyHeartbeat $heartbeat = null,
        ?LockManagerInterface $lockManager = null,
        ?string $lockKey = null,
    ): UploadState {
        $current = $this->metadata->get($chunk->identifier);
        if ($current !== null) {
            if ($current->finalPath !== null || !$this->progress->isComplete($current)) {
                return $current;
            }
            $state = $current;
        }

        $startedAt = microtime(true);
        $finalPath = $this->assembler->assemble($state, $this->storage);
        $duration = microtime(true) - $startedAt;

        $this->metrics->recordAssemblyTime($chunk->identifier, $duration, $this->storageDriverName());

        // Last gate before anything destructive. `assemble()` has already written
        // the destination file, but deleting chunks and publishing finalPath are
        // the irreversible steps, and both are unsafe if a competitor is inside
        // the critical section right now. This is the defence that holds even
        // when the lock is not.
        $this->assertLeaseStillHeld($state, $heartbeat, $lockManager, $lockKey);

        $this->storage->deleteChunks($chunk->identifier);
        $finalized = $state->withFinalPath($finalPath);
        $this->metadata->save($finalized);
        $this->dispatcher->dispatch(new FileAssembledEvent($finalized, $finalPath));

        return $finalized;
    }

    /**
     * Refuses to finalize if the assembly lock is not demonstrably still ours.
     *
     * The heartbeat only catches loss that happened *while* it was ticking, and
     * an assembler with no checkpoint cannot tick at all. This is the belt to
     * that braces: after the fact, `renew()` doubles as a token-checked ownership
     * assertion, because it succeeds only while the stored token is still this
     * node's.
     *
     * A false result alone is not proof of a competitor, because the lease can
     * also have expired with nobody showing up -- benign, and abandoning a
     * completed assembly over it would be a self-inflicted failure. The two are
     * told apart by re-acquiring: `acquire()` only succeeds when nobody holds the
     * key, so a success proves the lock was merely lapsed and is re-owned, while
     * a failure proves a live competitor.
     *
     * A lease the heartbeat *did* observe being lost is never benign. Mutual
     * exclusion demonstrably failed, and a competitor may have assembled and
     * published already, so this node must not add a second, conflicting result.
     *
     * @throws AssemblyLeaseLostException
     */
    private function assertLeaseStillHeld(
        UploadState $state,
        ?AssemblyHeartbeat $heartbeat,
        ?LockManagerInterface $lockManager,
        ?string $lockKey,
    ): void {
        if ($heartbeat?->lost() === true) {
            throw new AssemblyLeaseLostException(
                'Lost the assembly lock while assembling; another node may be finalizing this upload',
                $state,
            );
        }

        if ($lockManager === null || $lockKey === null) {
            return;
        }

        if ($lockManager->renew($lockKey, $this->assemblyLockTtl)) {
            return;
        }

        if ($lockManager->acquire($lockKey, $this->assemblyLockTtl)) {
            // Expired without a competitor; we hold it again. Nothing to report.
            return;
        }

        $this->logger?->error('Assembly lock was taken by another node mid-assembly; discarding result', [
            'identifier' => $state->identifier,
            'lockKey' => $lockKey,
        ]);

        // Recorded here as well as in the heartbeat, because an assembler with no
        // checkpoint can only be caught after the fact. Without this, the
        // opaque-assembler path would detect the failure correctly and then
        // report nothing, leaving the most serious variant of this condition as
        // the one that never pages.
        $this->metrics->incrementLockLeaseExpired($lockKey);

        throw new AssemblyLeaseLostException(
            'Assembly lock is held by another node; refusing to finalize',
            $state,
        );
    }

    /**
     * Runs the locked critical section with a lease heartbeat attached.
     *
     * The heartbeat is created here and torn down in the same `finally` that
     * releases the lock, so the two can never get out of step: a renewal
     * scheduled after the release could resurrect a lease this node believes it
     * has given up, keeping every other node out of a lock nobody holds.
     *
     * The heartbeat is only attached to assemblers that implement
     * {@see HeartbeatAwareAssemblerInterface}, i.e. that can offer a point where
     * control is back in PHP. Passing it to an opaque single-shot assembler would
     * create the appearance of a lease guard that provably cannot run.
     */
    private function runCriticalSection(
        UploadState $state,
        Chunk $chunk,
        string $lockKey,
        LockManagerInterface $lockManager,
    ): UploadState {
        $heartbeat = new AssemblyHeartbeat(
            $lockManager,
            $lockKey,
            $this->assemblyLockTtl,
            $this->metrics,
            $this->clock,
        );
        $attaches = $this->assembler instanceof HeartbeatAwareAssemblerInterface ? $this->assembler : null;

        if ($attaches !== null) {
            $attaches->setHeartbeat($heartbeat);
        }

        try {
            return $this->assembleIfNeeded(
                $state,
                $chunk,
                $attaches !== null ? $heartbeat : null,
                $lockManager,
                $lockKey,
            );
        } finally {
            // Detach before releasing so a retained assembler cannot renew a
            // lease that is about to be dropped, then stop and release. Order
            // matters: stop() is what makes a late tick a no-op.
            if ($attaches !== null) {
                $attaches->setHeartbeat(null);
            }
            $heartbeat->stop();
            $this->releaseLock($lockKey);
        }
    }

    /**
     * Records a digest failure, falling back to a bounded reason code.
     *
     * The label is derived from the backend's own error code, with a fixed
     * fallback rather than the exception message: messages embed part numbers,
     * filenames and AWS prose, so using them as labels would explode metric
     * cardinality and make the series useless.
     */
    private function recordChecksumMismatch(ChecksumMismatchException $e): void
    {
        $this->metrics->incrementChecksumMismatch(
            $this->storageDriverName(),
            $e->reasonCode() ?? 'unknown-mismatch',
        );
    }

    /**
     * Best-effort driver name for metrics labels.
     *
     * Derived from the storage class rather than injected as a second source of
     * truth, so the label cannot drift away from the driver actually used.
     */
    private function storageDriverName(): string
    {
        return str_contains($this->storage::class, 'S3')
            ? 's3'
            : 'local';
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
                return $this->runCriticalSection($state, $chunk, $lockKey, $lockManager);
            }

            $this->metrics->incrementLockCollision($chunk->identifier);
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
