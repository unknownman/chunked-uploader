<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Resumable\ChunkedUploader\Core\Drivers\Locking\PdoLockManager;
use Resumable\ChunkedUploader\Core\Drivers\Locking\RedisLockManager;
use Resumable\ChunkedUploader\Tests\Support\FakeRedis;

final class LockManagerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
    }

    private function pdoLocks(): PdoLockManager
    {
        $manager = new PdoLockManager($this->pdo);
        $manager->ensureSchema();

        return $manager;
    }

    private function redisLocks(): RedisLockManager
    {
        return new RedisLockManager(new FakeRedis());
    }

    public function testFirstCaller_acquires_the_lock(): void
    {
        self::assertTrue($this->pdoLocks()->acquire('assembly:lock:abc', 30));
    }

    public function testSecondNode_is_refused_while_the_lock_is_held(): void
    {
        $first = $this->pdoLocks();
        self::assertTrue($first->acquire('assembly:lock:abc', 30));

        $second = $this->pdoLocks();
        self::assertFalse($second->acquire('assembly:lock:abc', 30));
    }

    public function test_releasing_lets_the_next_node_in(): void
    {
        $first = $this->pdoLocks();
        self::assertTrue($first->acquire('k', 30));
        $first->release('k');

        self::assertTrue($this->pdoLocks()->acquire('k', 30));
    }

    public function test_redis_refuses_a_second_node_while_held(): void
    {
        $redis = new FakeRedis();
        self::assertTrue((new RedisLockManager($redis))->acquire('k', 30));
        self::assertFalse((new RedisLockManager($redis))->acquire('k', 30));
    }

    public function test_redis_release_lets_the_next_node_in(): void
    {
        $redis = new FakeRedis();
        $first = new RedisLockManager($redis);
        self::assertTrue($first->acquire('k', 30));
        $first->release('k');

        self::assertTrue((new RedisLockManager($redis))->acquire('k', 30));
    }

    public function test_lapsed_holder_does_not_release_a_successor_lock(): void
    {
        $redis = new FakeRedis();
        $first = new RedisLockManager($redis);
        $second = new RedisLockManager($redis);

        self::assertTrue($first->acquire('k', 1));

        // The first node's lease expires and a second node takes over.
        $this->advanceRedisClock($redis, 2);
        self::assertTrue($second->acquire('k', 30));

        // The stale holder now finally unwinds; it must not free node two's lock.
        $first->release('k');
        self::assertFalse((new RedisLockManager($redis))->acquire('k', 30));
    }

    public function test_pdo_lapsed_holder_does_not_release_a_successor_lock(): void
    {
        $first = $this->pdoLocks();
        $second = $this->pdoLocks();

        self::assertTrue($first->acquire('k', 1));

        // Force the lease to lapse so node two can reclaim the dead lock.
        $this->pdo->exec("UPDATE chunked_uploader_locks SET expires_at = 1");
        self::assertTrue($second->acquire('k', 30));

        $first->release('k');
        self::assertFalse($this->pdoLocks()->acquire('k', 30));
    }

    public function test_pdo_reclaims_a_lock_abandoned_by_a_crashed_node(): void
    {
        $crashed = $this->pdoLocks();
        self::assertTrue($crashed->acquire('k', 1));

        // A crash means release() never runs; only the lease can free the row.
        $this->pdo->exec("UPDATE chunked_uploader_locks SET expires_at = 1");

        self::assertTrue($this->pdoLocks()->acquire('k', 30), 'an expired lease must be reclaimable');
    }

    public function test_purge_expired_removes_only_stale_rows(): void
    {
        $locks = $this->pdoLocks();
        $locks->acquire('stale', 1);
        $this->pdo->exec("UPDATE chunked_uploader_locks SET expires_at = 1 WHERE lock_key = 'stale'");
        $locks->acquire('live', 30);

        self::assertSame(1, $locks->purgeExpired());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM chunked_uploader_locks')->fetchColumn());
    }

    public function test_release_is_idempotent_and_silent_for_unheld_keys(): void
    {
        $locks = $this->pdoLocks();
        $locks->release('never-acquired');
        $locks->acquire('k', 30);
        $locks->release('k');
        $locks->release('k');

        self::assertTrue($this->pdoLocks()->acquire('k', 30));
    }

    public function test_redis_release_is_idempotent(): void
    {
        $locks = $this->redisLocks();
        $locks->release('never-acquired');
        $locks->acquire('k', 30);
        $locks->release('k');
        $locks->release('k');

        self::assertTrue($this->redisLocks()->acquire('k', 30));
    }

    /**
     * @dataProvider invalidTtlProvider
     */
    public function test_a_non_positive_ttl_is_rejected(int $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pdoLocks()->acquire('k', $ttl);
    }

    public static function invalidTtlProvider(): array
    {
        return [[0], [-1]];
    }

    public function test_redis_rejects_a_non_positive_ttl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->redisLocks()->acquire('k', 0);
    }

    public function test_different_keys_do_not_contend(): void
    {
        $locks = $this->pdoLocks();
        self::assertTrue($locks->acquire('a', 30));
        self::assertTrue($locks->acquire('b', 30));
    }

    /**
     * Moves the fake's lease clock forward so a 1s TTL can lapse without sleeping.
     */
    private function advanceRedisClock(FakeRedis $redis, int $seconds): void
    {
        foreach (array_keys($redis->deadlines) as $key) {
            $redis->deadlines[$key] -= $seconds;
        }
    }
}
