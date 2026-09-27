<?php

declare(strict_types=1);

// File: src/Core/Drivers/Metrics/NullMetricsTracker.php

namespace Resumable\ChunkedUploader\Core\Drivers\Metrics;

use Resumable\ChunkedUploader\Core\Contracts\MetricsTrackerInterface;

/**
 * Discards every signal.
 *
 * Bound by default so a deployment that does not care about telemetry pays
 * nothing: no client is constructed, no transport is opened, and no labels are
 * formatted on the per-chunk hot path. Method bodies are empty rather than
 * delegating to an optional inner tracker so the JIT and opcache can inline
 * them away.
 *
 * Applications opt into telemetry by rebinding {@see MetricsTrackerInterface}
 * to their own Prometheus, StatsD or OpenTelemetry adapter.
 */
final class NullMetricsTracker implements MetricsTrackerInterface
{
    public function incrementChunkUploaded(int $bytes): void
    {
    }

    public function incrementChecksumMismatch(string $driver, string $reason): void
    {
    }

    public function incrementLockCollision(string $key): void
    {
    }

    public function incrementLockLeaseExpired(string $key): void
    {
    }

    public function recordAssemblyTime(string $identifier, float $durationSeconds, string $driver): void
    {
    }
}
