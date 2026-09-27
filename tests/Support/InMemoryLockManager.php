<?php

declare(strict_types=1);

// File: tests/Support/InMemoryLockManager.php

namespace Resumable\ChunkedUploader\Tests\Support;

use Resumable\ChunkedUploader\Core\Contracts\LockManagerInterface;

/**
 * Process-wide lock double whose state is shared by every instance.
 *
 * Each instance is a separate "node", but they all consult one shared table, so
 * a test can hold the lock through one uploader and observe a second uploader
 * being turned away -- the exact contention the real drivers coordinate across
 * machines, reproduced deterministically in a single-threaded test.
 *
 * Ownership is token-scoped exactly as in the real implementations, so a lapsed
 * holder cannot release a successor's lock.
 *
 * Time is a settable clock rather than {@see time()}, so a test can make an
 * assembly take an hour in a few microseconds. That matters for the heartbeat
 * tests specifically: a real lease expiry needs real seconds to elapse, which
 * would make the suite slow and, on a loaded CI box, flaky. Sleeping would also
 * test nothing about the ttl/2 decision logic, only that the process was still
 * running.
 */
final class InMemoryLockManager implements LockManagerInterface
{
    /** @var array<string, array{token: string, expiresAt: int}> */
    private static array $table = [];

    private static int $sequence = 0;

    /**
     * Simulated wall clock, in epoch seconds.
     */
    private static int $now = 1_700_000_000;

    /**
     * Renewal count per key, so a test can assert the heartbeat fired a specific
     * number of times rather than merely that it fired at all.
     *
     * @var array<string, int>
     */
    private static array $renewals = [];

    /**
     * The token this instance actually wrote, keyed by lock.
     *
     * Stored rather than re-read from the shared table on release: comparing the
     * table against itself would always match and would hide the very ownership
     * violation these tests check for.
     *
     * @var array<string, string>
     */
    private array $held = [];

    public static function reset(): void
    {
        self::$table = [];
        self::$sequence = 0;
        self::$renewals = [];
        self::$now = 1_700_000_000;
    }

    /**
     * The simulated clock, so a test can assert against absolute deadlines.
     */
    public static function now(): int
    {
        return self::$now;
    }

    /**
     * Moves the clock forward and drops any lease the jump expired.
     *
     * Expiry is applied as a side effect rather than lazily on read so that a
     * test can observe the key disappear, which is what a real datastore does
     * once the deadline passes.
     */
    public static function advance(int $seconds): void
    {
        self::$now += $seconds;

        foreach (self::$table as $key => $entry) {
            if ($entry['expiresAt'] <= self::$now) {
                unset(self::$table[$key]);
            }
        }
    }

    public static function isLocked(string $key): bool
    {
        return isset(self::$table[$key]);
    }

    public function acquire(string $key, int $ttlSeconds = 60): bool
    {
        if (isset($this->held[$key])) {
            return true;
        }

        if (isset(self::$table[$key]) && self::$table[$key]['expiresAt'] > self::$now) {
            return false;
        }

        $token = 'node-' . (++self::$sequence);
        self::$table[$key] = [
            'token' => $token,
            'expiresAt' => self::$now + $ttlSeconds,
        ];
        $this->held[$key] = $token;

        return true;
    }

    public function release(string $key): void
    {
        if (!isset($this->held[$key])) {
            return;
        }

        $token = $this->held[$key];
        unset($this->held[$key]);

        // Owner-checked: a lapsed holder whose key was already reclaimed must not
        // delete the successor's lock.
        if (isset(self::$table[$key]) && self::$table[$key]['token'] === $token) {
            unset(self::$table[$key]);
        }
    }

    public function renew(string $key, int $ttlSeconds = 60): bool
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Lock TTL must be at least one second.');
        }

        $token = $this->held[$key] ?? null;
        if ($token === null) {
            return false;
        }

        // Token-scoped, mirroring the real drivers: a node that no longer owns
        // the key must not be able to push out somebody else's deadline.
        if (!isset(self::$table[$key]) || self::$table[$key]['token'] !== $token) {
            unset($this->held[$key]);

            return false;
        }

        self::$table[$key]['expiresAt'] = self::$now + $ttlSeconds;
        self::$renewals[$key] = (self::$renewals[$key] ?? 0) + 1;

        return true;
    }

    /**
     * The lease deadline recorded for a key, or null when it is not locked.
     */
    public static function expiresAt(string $key): ?int
    {
        return self::$table[$key]['expiresAt'] ?? null;
    }

    public static function renewalCount(string $key): int
    {
        return self::$renewals[$key] ?? 0;
    }

    /**
     * Simulates another node winning the key, bypassing acquisition.
     *
     * Models the real failure this guards against: the lease lapsed, a
     * competitor took the lock, and the original holder has no way to know until
     * it next verifies. Overwrites the entry outright so the lapsed holder's
     * token no longer matches, exactly as a competing `acquire()` would leave it.
     */
    public static function stealByAnotherNode(string $key, int $ttlSeconds = 60): void
    {
        self::$table[$key] = [
            'token' => 'competitor-' . (++self::$sequence),
            'expiresAt' => self::$now + $ttlSeconds,
        ];
    }

    /**
     * Holds the lock on behalf of an external owner, to simulate a node that is
     * mid-assembly and has not released it yet.
     */
    public function lockExternally(string $key, int $ttlSeconds = 60): void
    {
        self::$table[$key] = [
            'token' => 'external',
            'expiresAt' => self::$now + $ttlSeconds,
        ];
    }
}
