<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

interface RedisConnectionInterface
{
    /**
     * Increments a key atomically.
     *
     * @param string $key Redis key.
     * @return int New counter value.
     */
    public function increment(string $key): int;

    /**
     * Reads a value from Redis.
     *
     * @param string $key Redis key.
     * @return int Current counter value, or zero when absent.
     */
    public function get(string $key): int;

    /**
     * Sets a key expiry in seconds.
     *
     * @param string $key Redis key.
     * @param int $seconds Expiry duration.
     * @return bool True when Redis accepted the command.
     */
    public function expire(string $key, int $seconds): bool;

    /**
     * Deletes a key.
     *
     * @param string $key Redis key.
     * @return void
     */
    public function delete(string $key): void;
}
