<?php

declare(strict_types=1);

// File: tests/Unit/AssemblyHeartbeatTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Locking\AssemblyHeartbeat;
use Resumable\ChunkedUploader\Tests\Support\InMemoryLockManager;
use Resumable\ChunkedUploader\Tests\Support\RecordingMetricsTracker;
use Resumable\ChunkedUploader\Tests\TestCase;

/**
 * Covers the lease-renewal decision logic in {@see AssemblyHeartbeat}.
 *
 * Time is injected rather than slept through. The window is `ttl / 2` seconds,
 * so proving it behaviourally with the real clock would need multi-second tests
 * and a suite whose result depends on machine load. A fake clock makes each
 * boundary exact, so a one-second regression in the arithmetic shows up as a
 * clear assertion failure instead of a timeout.
 */
final class AssemblyHeartbeatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        InMemoryLockManager::reset();
    }

    protected function tearDown(): void
    {
        InMemoryLockManager::reset();

        parent::tearDown();
    }

    #[Test]
    public function test_the_renewal_window_is_half_the_lease(): void
    {
        $heartbeat = new AssemblyHeartbeat(
            new InMemoryLockManager(),
            'assembly:lock:x',
            60,
            new RecordingMetricsTracker(),
            static fn (): float => 0.0,
        );

        self::assertSame(30.0, $heartbeat->intervalSeconds());
    }

    #[Test]
    public function test_no_renewal_happens_before_the_window_opens(): void
    {
        $locks = new InMemoryLockManager();
        $locks->acquire('k', 60);
        $now = 0.0;
        $metrics = new RecordingMetricsTracker();
        $heartbeat = new AssemblyHeartbeat(
            $locks,
            'k',
            60,
            $metrics,
            static function () use (&$now): float {
                return $now;
            },
        );

        // Just short of the window: a beat here must cost nothing, because the
        // lease still has a full TTL of headroom.
        $now = 29.9;
        $heartbeat->tick();

        self::assertSame(0, $heartbeat->renewalCount());
        self::assertSame(0, InMemoryLockManager::renewalCount('k'));
        self::assertFalse($heartbeat->lost());
    }

    #[Test]
    public function test_a_beat_past_the_window_extends_the_lease(): void
    {
        $locks = new InMemoryLockManager();
        $locks->acquire('k', 60);
        $deadlineBefore = InMemoryLockManager::expiresAt('k');
        $now = 0.0;
        $heartbeat = new AssemblyHeartbeat(
            $locks,
            'k',
            60,
            new RecordingMetricsTracker(),
            static function () use (&$now): float {
                return $now;
            },
        );

        // Half the lease has genuinely passed in lock-manager time too, so the
        // renewal has real work to do rather than racing an unexpired deadline.
        InMemoryLockManager::advance(30);
        $now = 30.0;
        $heartbeat->tick();

        self::assertSame(1, $heartbeat->renewalCount());
        self::assertFalse($heartbeat->lost());
        self::assertGreaterThan(
            (int) $deadlineBefore,
            (int) InMemoryLockManager::expiresAt('k'),
            'The recorded lease deadline must move forward, not merely be re-asserted.',
        );
    }

    #[Test]
    public function test_the_window_is_measured_from_the_last_success_not_the_last_check(): void
    {
        $locks = new InMemoryLockManager();
        $locks->acquire('k', 60);
        $now = 0.0;
        $heartbeat = new AssemblyHeartbeat(
            $locks,
            'k',
            60,
            new RecordingMetricsTracker(),
            static function () use (&$now): float {
                return $now;
            },
        );

        InMemoryLockManager::advance(30);
        $now = 30.0;
        $heartbeat->tick();
        self::assertSame(1, $heartbeat->renewalCount());

        // A second beat at 45s is only 15s after the last success, so it must not
        // renew again. Measuring from the last *check* would renew here and drain
        // the datastore with a write per tick instead of a write per half-TTL.
        InMemoryLockManager::advance(15);
        $now = 45.0;
        $heartbeat->tick();

        self::assertSame(1, $heartbeat->renewalCount(), 'Beats inside the window must not hit the datastore.');
    }

    #[Test]
    public function test_a_long_keeps_being_renewed_across_many_beats(): void
    {
        $locks = new InMemoryLockManager();
        $locks->acquire('k', 60);
        $now = 0.0;
        $heartbeat = new AssemblyHeartbeat(
            $locks,
            'k',
            60,
            new RecordingMetricsTracker(),
            static function () use (&$now): float {
                return $now;
            },
        );

        // Five minutes of simulated assembly at one beat per chunk.
        for ($i = 1; $i <= 50; ++$i) {
            InMemoryLockManager::advance(6);
            $now += 6.0;
            $heartbeat->tick();
        }

        self::assertSame(10, $heartbeat->renewalCount(), 'Six-second steps should renew every thirty seconds.');
        self::assertFalse($heartbeat->lost());
        self::assertTrue(InMemoryLockManager::isLocked('k'), 'The lease must still be held at the end of a long assembly.');
    }

    #[Test]
    public function test_a_lost_lease_latches_and_is_reported_once(): void
    {
        $locks = new InMemoryLockManager();
        $locks->acquire('k', 60);
        $metrics = new RecordingMetricsTracker();
        $now = 0.0;
        $heartbeat = new AssemblyHeartbeat(
            $locks,
            'k',
            60,
            $metrics,
            static function () use (&$now): float {
                return $now;
            },
        );

        InMemoryLockManager::advance(30);
        $now = 30.0;
        InMemoryLockManager::stealByAnotherNode('k', 60);
        $heartbeat->tick();

        self::assertTrue($heartbeat->lost());

        // Further beats must not keep calling the datastore or re-report.
        $now = 120.0;
        $heartbeat->tick();
        $heartbeat->tick();

        self::assertCount(1, $metrics->leaseExpirations, 'A lost lease is one event, not one per beat.');
    }

    #[Test]
    public function test_a_stopped_heartbeat_never_renews_again(): void
    {
        $locks = new InMemoryLockManager();
        $locks->acquire('k', 60);
        $now = 0.0;
        $heartbeat = new AssemblyHeartbeat(
            $locks,
            'k',
            60,
            new RecordingMetricsTracker(),
            static function () use (&$now): float {
                return $now;
            },
        );

        $heartbeat->stop();
        self::assertTrue($heartbeat->stopped());

        InMemoryLockManager::advance(30);
        $now = 30.0;
        $heartbeat->tick();

        self::assertSame(0, $heartbeat->renewalCount(), 'A stopped heartbeat must not resurrect a lease being released.');
    }

    #[Test]
    public function test_renewing_a_lock_this_node_never_acquired_is_refused(): void
    {
        $locks = new InMemoryLockManager();
        $now = 0.0;
        $heartbeat = new AssemblyHeartbeat(
            $locks,
            'never-acquired',
            60,
            new RecordingMetricsTracker(),
            static function () use (&$now): float {
                return $now;
            },
        );

        $now = 30.0;
        $heartbeat->tick();

        self::assertTrue($heartbeat->lost());
        self::assertFalse(InMemoryLockManager::isLocked('never-acquired'));
    }
}
