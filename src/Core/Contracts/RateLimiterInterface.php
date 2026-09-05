<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

interface RateLimiterInterface
{
    /**
     * Records one attempt and returns the resulting count.
     *
     * @param string $key Rate-limit key.
     * @param int $decaySeconds Window duration in seconds.
     * @return int Current attempt count.
     */
    public function hit(string $key, int $decaySeconds = 60): int;

    /**
     * Determines whether the attempt count has reached a limit.
     *
     * @param string $key Rate-limit key.
     * @param int $maxAttempts Maximum allowed attempts.
     * @return bool True when the limit has been reached.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool;

    /**
     * Clears the attempt counter.
     *
     * @param string $key Rate-limit key.
     * @return void
     */
    public function resetAttempts(string $key): void;
}
