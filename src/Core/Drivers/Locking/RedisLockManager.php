<?php

declare(strict_types=1);

// File: src/Core/Drivers/Locking/RedisLockManager.php

namespace Resumable\ChunkedUploader\Core\Drivers\Locking;

use Predis\Response\Status;
use Redis;
use Resumable\ChunkedUploader\Core\Contracts\LockManagerInterface;

/**
 * Distributed lock backed by Redis, using only native atomic commands.
 *
 * Acquisition is a single `SET key token NX PX <ms>`. Redis applies `SET` as a
 * single indivisible operation, so the `NX` flag makes acquisition a
 * race-free compare-and-set with no `WATCH`/`MULTI` retry loop, no spinning,
 * and no window in which two callers can both believe they won. `PX` attaches
 * the lease to the same atomic write, so a lock can never be created without
 * an expiry -- the failure mode that turns a crash into a permanently stuck
 * upload.
 *
 * Release is a compare-and-delete Lua script. A bare `DEL` would be wrong: by
 * the time a slow node reaches its `finally` block its lease may have expired
 * and been re-acquired by another node, and an unconditional delete would
 * release a lock this process no longer owns -- reintroducing exactly the
 * double-assembly race the lock exists to prevent. The script compares the
 * stored token first and only then deletes.
 *
 * The script touches a single key, so under Redis Cluster all keys resolve to
 * one hash slot and `CROSSSLOT` cannot occur.
 *
 * The owner token is generated once per instance. In a typical deployment one
 * instance serves one request, which makes the token a faithful identity for
 * "this node, this attempt"; a fresh attempt gets a fresh instance and hence a
 * fresh token, so a retried release can never delete a successor's lock.
 */
final class RedisLockManager implements LockManagerInterface
{
    /**
     * Compare-and-delete: only the owner may release.
     *
     * @var string
     */
    private const RELEASE_LUA = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    /**
     * Identifies this holder to the datastore.
     *
     * Combines process identity with random bytes so two workers on the same
     * host never share a token, and so a token is never guessable by another
     * tenant sharing the Redis instance.
     */
    private readonly string $token;

    /**
     * Locks currently held by this instance, used to make release a no-op when
     * acquisition never succeeded.
     *
     * @var array<string, true>
     */
    private array $held = [];

    public function __construct(
        private readonly \Predis\ClientInterface|Redis $redis,
        private readonly string $keyPrefix = 'chunked-uploader:lock:',
    ) {
        $this->token = self::generateToken();
    }

    public function acquire(string $key, int $ttlSeconds = 60): bool
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Lock TTL must be at least one second.');
        }

        if (isset($this->held[$key])) {
            // Already ours. Re-entering acquire for a held key would either
            // deadlock this holder against itself or, worse, silently succeed
            // against a different owner's key, so the caller must be told the
            // truth instead.
            return true;
        }

        $redisKey = $this->key($key);
        $milliseconds = $ttlSeconds * 1000;

        $acquired = $this->redis instanceof Redis
            ? $this->acquireWithPhpRedis($redisKey, $milliseconds)
            : $this->acquireWithPredis($redisKey, $milliseconds);

        if ($acquired) {
            $this->held[$key] = true;
        }

        return $acquired;
    }

    public function release(string $key): void
    {
        if (!isset($this->held[$key])) {
            // Never acquired, or already released. Releasing anyway would risk
            // deleting another node's lock if our lease had lapsed.
            return;
        }

        unset($this->held[$key]);

        $redisKey = $this->key($key);

        if ($this->redis instanceof Redis) {
            $this->redis->eval(self::RELEASE_LUA, [$redisKey, $this->token], 1);

            return;
        }

        $this->redis->eval(self::RELEASE_LUA, 1, $redisKey, $this->token);
    }

    /**
     * phpredis takes NX/PX as an options array and returns a bool.
     */
    private function acquireWithPhpRedis(string $redisKey, int $milliseconds): bool
    {
        return $this->redis->set($redisKey, $this->token, ['nx', 'px' => $milliseconds]) === true;
    }

    /**
     * Predis takes the modifiers as trailing variadic arguments and returns a
     * `Status` object on success, or null/`false` when the NX precondition fails.
     *
     * Every member of Predis' declared return union is handled explicitly: the
     * command can legitimately hand back a bool or a raw string depending on
     * the client version, and silently treating an unexpected value as success
     * would break mutual exclusion.
     */
    private function acquireWithPredis(string $redisKey, int $milliseconds): bool
    {
        $result = $this->redis->set($redisKey, $this->token, 'PX', $milliseconds, 'NX');

        if ($result instanceof Status) {
            return strtoupper((string) $result) === 'OK';
        }

        if (is_string($result)) {
            return strtoupper($result) === 'OK';
        }

        return $result === true;
    }

    /**
     * Builds a namespaced, collision-resistant key.
     *
     * The caller's key is hashed rather than concatenated verbatim: identifiers
     * are attacker-influenced, and an unhashed key would let a crafted
     * identifier collide with, or redirect the lock onto, an unrelated key.
     */
    private function key(string $key): string
    {
        return $this->keyPrefix . hash('sha256', $key);
    }

    private static function generateToken(): string
    {
        $pid = getmypid();

        return ($pid === false ? 'na' : (string) $pid) . '-' . bin2hex(random_bytes(16));
    }
}
