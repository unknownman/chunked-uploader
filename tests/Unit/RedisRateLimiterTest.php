<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Security\RateLimiting\RedisRateLimiter;
use Resumable\ChunkedUploader\Tests\Support\FakeRedis;
use Resumable\ChunkedUploader\Tests\TestCase;

final class RedisRateLimiterTest extends TestCase
{
    private FakeRedis $redis;
    private RedisRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new FakeRedis();
        $this->limiter = new RedisRateLimiter($this->redis, 'ratelimit:');
    }

    #[Test]
    public function test_hit_increments_and_applies_the_window_expiry_on_first_attempt(): void
    {
        $this->limiter->hit('client-a', 60);
        $this->limiter->hit('client-a', 60);

        $key = 'ratelimit:' . hash('sha256', 'client-a');
        self::assertSame('2', $this->redis->data[$key]);
        self::assertSame(60, $this->redis->expirations[$key]);
    }

    #[Test]
    public function test_hit_refreshes_only_when_reaching_one(): void
    {
        $this->limiter->hit('client-b', 30);
        self::assertSame('1', $this->redis->data['ratelimit:' . hash('sha256', 'client-b')]);
        self::assertSame(30, $this->redis->expirations['ratelimit:' . hash('sha256', 'client-b')]);
    }

    #[Test]
    public function test_too_many_attempts_respects_the_ceiling(): void
    {
        $this->limiter->hit('client-c', 60);
        $this->limiter->hit('client-c', 60);

        self::assertFalse($this->limiter->tooManyAttempts('client-c', 3));
        self::assertTrue($this->limiter->tooManyAttempts('client-c', 2));
    }

    #[Test]
    public function test_too_many_attempts_is_false_for_an_unseen_key(): void
    {
        self::assertFalse($this->limiter->tooManyAttempts('never-seen', 1));
    }

    #[Test]
    public function test_reset_attempts_clears_the_counter(): void
    {
        $this->limiter->hit('client-d', 60);
        $this->limiter->resetAttempts('client-d');

        self::assertFalse($this->limiter->tooManyAttempts('client-d', 1));
    }

    #[Test]
    public function test_hit_rejects_a_non_positive_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->hit('client-e', 0);
    }

    #[Test]
    public function test_too_many_attempts_rejects_a_non_positive_maximum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->tooManyAttempts('client-f', 0);
    }

    #[Test]
    public function test_keys_are_hashed_and_namespaced(): void
    {
        $this->limiter->hit('gürkan/übertrag', 60);

        self::assertArrayHasKey('ratelimit:' . hash('sha256', 'gürkan/übertrag'), $this->redis->data);
        self::assertCount(1, $this->redis->data);
    }
}
