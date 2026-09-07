<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Security\RateLimiting;

use Predis\ClientInterface;
use Redis;
use Resumable\ChunkedUploader\Core\Contracts\RateLimiterInterface;

/**
 * Fixed-window rate limiter backed by a Redis counter with an expiry.
 *
 * Accepts either a native phpredis \Redis instance or a Predis client, unifying
 * the package on the same `\Redis|\Predis\ClientInterface` union used by the
 * Redis metadata repository. Callers that wire both together can share a single
 * connection.
 */
final class RedisRateLimiter implements RateLimiterInterface
{
    /**
     * @param Redis|ClientInterface $redis   Counter backend, shared with the metadata repository when possible.
     * @param string                $prefix  Key namespace applied before the hashed limiter key.
     */
    public function __construct(
        private readonly Redis|ClientInterface $redis,
        private readonly string $prefix = 'rate-limit:',
    ) {
    }

    /**
     * Atomically increments a rate-limit counter and applies its window expiry.
     *
     * @param string $key Client IP, token, or other stable limiter key.
     * @param int $decaySeconds Window duration in seconds.
     * @return int Current attempt count.
     */
    public function hit(string $key, int $decaySeconds = 60): int
    {
        if ($decaySeconds < 1) {
            throw new \InvalidArgumentException('Decay seconds must be positive.');
        }

        $redisKey = $this->prefix . hash('sha256', $key);
        $count = $this->redis->incr($redisKey);
        if ($count === 1) {
            $this->redis->expire($redisKey, $decaySeconds);
        }

        return $count;
    }

    /**
     * Checks whether a key has reached its configured limit.
     *
     * @param string $key Rate-limit key.
     * @param int $maxAttempts Maximum allowed attempts.
     * @return bool True when the limit has been reached.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Maximum attempts must be positive.');
        }

        $redisKey = $this->prefix . hash('sha256', $key);
        $count = (int) $this->redis->get($redisKey);

        return $count >= $maxAttempts;
    }

    /**
     * Removes a rate-limit counter.
     *
     * @param string $key Rate-limit key.
     * @return void
     */
    public function resetAttempts(string $key): void
    {
        $this->redis->del($this->prefix . hash('sha256', $key));
    }
}
