<?php

declare(strict_types=1);

// File: tests/Support/RecordingMetricsTracker.php

namespace Resumable\ChunkedUploader\Tests\Support;

use Resumable\ChunkedUploader\Core\Contracts\MetricsTrackerInterface;

/**
 * Captures every telemetry signal so tests can assert on them.
 *
 * Lives in Support rather than inside a test file so several test cases can
 * share one tracker instance: PHPUnit loads a test file for the class it names,
 * so a double declared alongside a test is invisible to every other file.
 */
final class RecordingMetricsTracker implements MetricsTrackerInterface
{
    public int $chunkBytes = 0;

    /** @var list<array{driver: string, reason: string}> */
    public array $checksumMismatches = [];

    /** @var list<string> */
    public array $collisions = [];

    /** @var list<string> */
    public array $leaseExpirations = [];

    /** @var list<array{identifier: string, seconds: float, driver: string}> */
    public array $assemblyTimes = [];

    public function incrementChunkUploaded(int $bytes): void
    {
        $this->chunkBytes += $bytes;
    }

    public function incrementChecksumMismatch(string $driver, string $reason): void
    {
        $this->checksumMismatches[] = ['driver' => $driver, 'reason' => $reason];
    }

    public function incrementLockCollision(string $key): void
    {
        $this->collisions[] = $key;
    }

    public function incrementLockLeaseExpired(string $key): void
    {
        $this->leaseExpirations[] = $key;
    }

    public function recordAssemblyTime(string $identifier, float $durationSeconds, string $driver): void
    {
        $this->assemblyTimes[] = ['identifier' => $identifier, 'seconds' => $durationSeconds, 'driver' => $driver];
    }
}
