<?php

declare(strict_types=1);

// File: src/Core/Locking/AssemblyHeartbeat.php

namespace Resumable\ChunkedUploader\Core\Locking;

use Resumable\ChunkedUploader\Core\Contracts\LockManagerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetricsTrackerInterface;
use Resumable\ChunkedUploader\Core\Drivers\Metrics\NullMetricsTracker;

/**
 * Keeps an assembly lease alive across a critical section that may outlast it.
 *
 * What this can and cannot do
 * ---------------------------
 * PHP has no preemption: a timer callback cannot fire while a blocking call is
 * in progress, and `register_tick_function` only runs between statements of the
 * *same* function. There is therefore no way to renew a lease from inside an
 * opaque blocking call such as S3's `CompleteMultipartUpload`. This class does
 * not pretend otherwise. It renews only when an assembler explicitly calls
 * {@see tick()} at a point where it is safe to do so, which means the benefit is
 * proportional to how finely the assembler is willing to check in:
 *
 *  - A streaming assembler that copies part by part ticks once per part, and a
 *    multi-gigabyte assembly of 5 MiB parts gets hundreds of renewal
 *    opportunities. This is where the heartbeat is load-bearing.
 *  - A single-shot assembler has no checkpoint, so no renewal happens. The lease
 *    is not renewed, and the loss is detected by
 *    {@see \Resumable\ChunkedUploader\Core\ChunkUploader} after the call returns
 *    rather than prevented. Such a critical section must simply be sized to fit
 *    inside the TTL.
 *
 * Renewal window
 * --------------
 * A beat fires once `ttl / 2` has elapsed since the last successful renewal, not
 * once `ttl / 2` has elapsed since the last *check*. Measuring from the last
 * success means a beat that arrives early costs nothing and a beat that arrives
 * late still has a full TTL of remaining headroom before expiry, so a single
 * missed or slow beat cannot lose the lock on its own.
 *
 * @see \Resumable\ChunkedUploader\Core\Contracts\HeartbeatAwareAssemblerInterface
 */
final class AssemblyHeartbeat
{
    /**
     * Elapsed seconds since the last successful renewal, or since construction.
     */
    private float $lastRenewedAt;

    private bool $lost = false;

    private bool $stopped = false;

    private int $renewals = 0;

    /**
     * @param LockManagerInterface   $locks      Manager holding the lease
     * @param string                 $key        Lock key being held
     * @param int                    $ttlSeconds Full lease length, used both as
     *                                           the renewal target and as the
     *                                           divisor for the safety window
     * @param MetricsTrackerInterface $metrics    Records a lost lease
     * @param (\Closure(): float)|null $clock    Monotonic time source, injected by
     *                                           tests to avoid real sleeping.
     *                                           Defaults to {@see microtime()}.
     */
    public function __construct(
        private readonly LockManagerInterface $locks,
        private readonly string $key,
        private readonly int $ttlSeconds,
        private readonly MetricsTrackerInterface $metrics = new NullMetricsTracker(),
        private readonly ?\Closure $clock = null,
    ) {
        $this->lastRenewedAt = $this->now();
    }

    /**
     * Seconds between renewals: half the lease.
     */
    public function intervalSeconds(): float
    {
        return $this->ttlSeconds / 2;
    }

    /**
     * Renews the lease if the safety window has elapsed.
     *
     * Safe and cheap to call on every iteration of an assembly loop: it performs
     * no I/O until the window has actually passed, and it is idempotent once
     * {@see stop()} has been called.
     */
    public function tick(): void
    {
        if ($this->lost || $this->stopped) {
            // Already known lost, or the critical section is over. Renewing now
            // would either be futile or actively harmful: after stop() the
            // uploader is about to release, and a renewal racing that release
            // could resurrect a lease it is giving up.
            return;
        }

        if (($this->now() - $this->lastRenewedAt) < $this->intervalSeconds()) {
            return;
        }

        if ($this->locks->renew($this->key, $this->ttlSeconds)) {
            $this->lastRenewedAt = $this->now();
            ++$this->renewals;

            return;
        }

        // The lease is gone. Record it once and latch: continuing to call
        // renew() would spam the datastore, and the caller needs a single
        // unambiguous answer when it inspects lost() after the assembly.
        $this->lost = true;
        $this->metrics->incrementLockLeaseExpired($this->key);
    }

    /**
     * Whether the lease was found to be lost at any point.
     *
     * The caller must check this before publishing final state. A lost lease
     * means another node may be inside the critical section right now, so
     * writing `finalPath` and deleting the chunks from here would race the new
     * winner.
     */
    public function lost(): bool
    {
        return $this->lost;
    }

    /**
     * Number of successful renewals, for assertions and diagnostics.
     */
    public function renewalCount(): int
    {
        return $this->renewals;
    }

    /**
     * Permanently stops the heartbeat.
     *
     * Called from a `finally` block once the critical section ends, so the
     * heartbeat cannot outlive the lock it protects and renew a lease the
     * uploader is in the middle of releasing.
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Whether {@see stop()} has been called.
     */
    public function stopped(): bool
    {
        return $this->stopped;
    }

    private function now(): float
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }

        return microtime(true);
    }
}
