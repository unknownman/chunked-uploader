<?php

declare(strict_types=1);

// File: src/Core/Contracts/MetricsTrackerInterface.php

namespace Resumable\ChunkedUploader\Core\Contracts;

/**
 * Receives operational signals from the upload pipeline.
 *
 * The package deliberately records nothing itself: shipping a metrics client
 * would couple a library to a particular backend, and Prometheus, StatsD and
 * OpenTelemetry all differ in transport, label conventions and cardinality
 * rules. Instead this contract names the *facts* worth observing and leaves the
 * export mechanism to the application, which binds its own implementation.
 *
 * Design rules for implementors
 * -----------------------------
 *
 *  1. **Never throw.** Every method is called from inside `finally` blocks and
 *     from the middle of error handling. A tracker that throws on a label it
 *     dislikes would replace a genuine upload failure with a metrics failure,
 *     destroying the very diagnostic it was meant to capture.
 *  2. **Keep cardinality bounded.** `$key` and `$identifier` are derived from
 *     client-supplied upload identifiers, so they are effectively unbounded.
 *     Hash them, truncate them, or sample them; do not emit one time series per
 *     upload.
 *  3. **Be cheap.** These run in the per-chunk hot path, where a synchronous
 *     export would dominate the request.
 *
 * {@see \Resumable\ChunkedUploader\Core\Drivers\Metrics\NullMetricsTracker} is
 * the no-op bound by default, so an application opts in by rebinding this
 * interface rather than by configuring the package.
 */
interface MetricsTrackerInterface
{
    /**
     * Records a chunk that was accepted and durably recorded.
     *
     * @param int $bytes Size of the chunk payload, useful for throughput and
     *                    bandwidth-saturation metrics
     */
    public function incrementChunkUploaded(int $bytes): void;

    /**
     * Records a chunk rejected because its digest did not match the bytes.
     *
     * @param string $driver Storage driver that rejected it, e.g. `s3` or `local`
     * @param string $reason Coarse reason, e.g. the S3 error code or
     *                       `local-mismatch`. Implementations must treat this as
     *                       a low-cardinality label, not a free-form message
     */
    public function incrementChecksumMismatch(string $driver, string $reason): void;

    /**
     * Records losing the race to acquire an assembly lock.
     *
     * A non-zero rate is normal at low concurrency and a correctness concern at
     * high concurrency, where it indicates the wait budget is being consumed by
     * contention rather than by work.
     *
     * @param string $key Contended lock key
     */
    public function incrementLockCollision(string $key): void;

    /**
     * Records that a node discovered mid-critical-section that its lease was gone.
     *
     * This is the signal that the heartbeat failed to keep a lease alive, or
     * that the critical section overran the TTL. Unlike a collision, which is a
     * scheduling outcome, any value at all here means mutual exclusion was
     * temporarily broken and deserves an alert.
     *
     * @param string $key Lock key whose lease was lost
     */
    public function incrementLockLeaseExpired(string $key): void;

    /**
     * Records how long a finalisation took, end to end.
     *
     * @param string $identifier    Upload identifier; high cardinality, so
     *                              aggregate before exporting
     * @param float  $durationSeconds Wall-clock seconds spent in the assembler
     * @param string $driver         Storage driver that performed the assembly
     */
    public function recordAssemblyTime(string $identifier, float $durationSeconds, string $driver): void;
}
