<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Support;

use Redis;

/**
 * In-memory phpredis double for test doubles of the Redis-backed core classes.
 *
 * Implements the exact surface the metadata repository and rate limiter rely on:
 * get/set/del/expire/incr, plus a Lua-free eval that mimics the atomically
 * appended-chunk script. No real Redis server is ever contacted.
 */
final class FakeRedis extends Redis
{
    /** @var array<string, string> */
    public array $data = [];

    /** @var array<string, int> */
    public array $expirations = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? false;
    }

    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        $this->data[$key] = (string) $value;
        return true;
    }

    public function del(array|string $keys, string ...$other_keys): int
    {
        $all = array_merge((array) $keys, $other_keys);
        $count = 0;
        foreach ($all as $key) {
            if (isset($this->data[$key])) {
                unset($this->data[$key], $this->expirations[$key]);
                $count++;
            }
        }
        return $count;
    }

    public function expire(string $key, int $timeout, ?string $mode = null): bool
    {
        $this->expirations[$key] = $timeout;
        return true;
    }

    public function incr(string $key, int $by = 1): int|false
    {
        $current = (int) ($this->data[$key] ?? 0);
        $this->data[$key] = (string) ($current + $by);
        return $current + $by;
    }

    public function script(string $command, mixed ...$args): mixed
    {
        return sha1((string) ($args[0] ?? ''));
    }

    public function scan(string|int|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false
    {
        $keys = array_keys($this->data);
        if ($pattern !== null) {
            $keys = array_values(array_filter($keys, static fn (string $key): bool => fnmatch($pattern, $key)));
        }
        sort($keys, SORT_STRING);

        $cursor = (int) ($iterator ?? 0);
        if ($cursor >= count($keys)) {
            $iterator = 0;
            return false;
        }

        $page = array_slice($keys, $cursor, max($count, 1));
        $iterator = $cursor + count($page);
        if ($iterator >= count($keys)) {
            // phpredis signals the end of a scan by resetting the cursor to 0.
            $iterator = 0;
        }

        return $page;
    }

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $key = $args[0] ?? null;
        $chunkIndex = (int) ($args[1] ?? -1);

        if (!is_string($key) || !isset($this->data[$key])) {
            return false;
        }

        $state = json_decode($this->data[$key], true);
        if (!is_array($state)) {
            return false;
        }

        $uploaded = array_map('intval', $state['uploaded'] ?? []);
        if (!in_array($chunkIndex, $uploaded, true)) {
            $uploaded[] = $chunkIndex;
            sort($uploaded, SORT_NUMERIC);
        }

        $state['uploaded'] = $uploaded;
        $state['isCompleted'] = count($uploaded) === (int) $state['totalChunks'];
        $state['updatedAt'] = time();

        $encoded = json_encode($state);
        $this->data[$key] = (string) $encoded;

        return $encoded;
    }
}
