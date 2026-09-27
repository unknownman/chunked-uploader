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

    public function test_renew_extends_the_lease_for_pdo(): void
    {
        $locks = $this->pdoLocks();
        self::assertTrue($locks->acquire('k', 30));

        $before = $this->expiryOf('k');
        self::assertTrue($locks->renew('k', 120));
        self::assertGreaterThan($before, $this->expiryOf('k'), 'Renewal must push the deadline out.');
    }

    public function test_renew_extends_the_lease_for_redis(): void
    {
        $redis = new FakeRedis();
        $locks = new RedisLockManager($redis);
        self::assertTrue($locks->acquire('k', 5));

        $before = (int) $redis->deadlineFor((string) $redis->onlyKey());
        self::assertTrue($locks->renew('k', 600));

        self::assertGreaterThan(
            $before,
            (int) $redis->deadlineFor((string) $redis->onlyKey()),
            'PEXPIRE must move the key deadline rather than re-asserting the old one.',
        );
    }

    public function test_renew_refuses_a_key_another_node_now_owns(): void
    {
        $locks = $this->pdoLocks();
        self::assertTrue($locks->acquire('k', 30));

        // The lease lapsed and a competitor took the key over. The lapsed holder
        // must not be able to extend a lock it no longer owns.
        $this->expireAndSteal('k');

        self::assertFalse($locks->renew('k', 600), 'Renewal must be owner-verified.');
    }

    public function test_renew_refuses_after_the_lease_expired(): void
    {
        $locks = $this->pdoLocks();
        self::assertTrue($locks->acquire('k', 30));

        $this->expireAndSteal('k');

        self::assertFalse($locks->renew('k', 600));
    }

    public function test_renew_refuses_a_lock_never_acquired(): void
    {
        self::assertFalse($this->pdoLocks()->renew('never-acquired', 60));
        self::assertFalse($this->redisLocks()->renew('never-acquired', 60));
    }

    public function test_a_renewed_lease_still_excludes_other_nodes(): void
    {
        $locks = $this->pdoLocks();
        self::assertTrue($locks->acquire('k', 30));
        self::assertTrue($locks->renew('k', 300));

        self::assertFalse($this->pdoLocks()->acquire('k', 30), 'Renewal must not make the lock available.');
    }

    public function test_a_lapsed_holder_cannot_release_its_successors_lock(): void
    {
        $first = $this->pdoLocks();
        self::assertTrue($first->acquire('k', 30));

        // Lapse, then a second node takes the key.
        $this->expireAndSteal('k');

        $first->release('k');

        self::assertNotNull(
            $this->expiryOf('k'),
            'The original holder deleting the key would hand a third node a lock nobody holds.',
        );
    }

    public function test_renew_rejects_a_non_positive_ttl(): void
    {
        $locks = $this->pdoLocks();
        $locks->acquire('k', 30);

        $this->expectException(\InvalidArgumentException::class);
        $locks->renew('k', 0);
    }

    public function test_redis_renew_refuses_a_key_another_node_now_owns(): void
    {
        $redis = new FakeRedis();
        $locks = new RedisLockManager($redis);
        self::assertTrue($locks->acquire('k', 30));

        $redis->stealByAnotherNode((string) $redis->onlyKey(), 'someone-else', 60);

        self::assertFalse($locks->renew('k', 600), 'The Lua compare must reject a foreign token.');
    }

    public function test_redis_renew_refuses_an_expired_key(): void
    {
        $redis = new FakeRedis();
        $locks = new RedisLockManager($redis);
        self::assertTrue($locks->acquire('k', 30));

        $redis->expireNow((string) $redis->onlyKey());

        self::assertFalse($locks->renew('k', 600), 'PEXPIRE on a missing key must report failure.');
    }

    private function expiryOf(string $key): int
    {
        $stmt = $this->pdo->query("SELECT expires_at FROM chunked_uploader_locks WHERE lock_key = '" . $key . "'");
        $value = $stmt === false ? false : $stmt->fetchColumn();

        self::assertNotFalse($value, 'Expected a lock row for ' . $key);

        return (int) $value;
    }

    /**
     * Simulates a lease that lapsed and was then taken over by another node.
     */
    private function expireAndSteal(string $key): void
    {
        $this->pdo->exec("UPDATE chunked_uploader_locks SET expires_at = 1, token = 'competitor' WHERE lock_key = '"
            . $key . "'");
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
