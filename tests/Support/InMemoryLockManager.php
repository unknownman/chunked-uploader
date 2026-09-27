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
 * holder cannot release a successor's lock, and calls to {@see self::advance()}
 * let a test move the clock to observe lease expiry deterministically instead
 * of sleeping.
 */
final class InMemoryLockManager implements LockManagerInterface
{
    /** @var array<string, array{token: string, expiresAt: int}> */
    private static array $table = [];

    private static int $sequence = 0;

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
    }

    /**
     * Simulates elapsed time so leases can be expired without sleeping.
     */
    public static function advance(int $seconds): void
    {
        foreach (self::$table as $key => $entry) {
            if ($entry['expiresAt'] <= time() + $seconds) {
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

        if (isset(self::$table[$key]) && self::$table[$key]['expiresAt'] > time()) {
            return false;
        }

        $token = 'node-' . (++self::$sequence);
        self::$table[$key] = [
            'token' => $token,
            'expiresAt' => time() + $ttlSeconds,
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

    /**
     * Holds the lock on behalf of an external owner, to simulate a node that is
     * mid-assembly and has not released it yet.
     */
    public function lockExternally(string $key, int $ttlSeconds = 60): void
    {
        self::$table[$key] = [
            'token' => 'external',
            'expiresAt' => time() + $ttlSeconds,
        ];
    }
}
