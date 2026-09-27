<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Support;

use Redis;

/**
 * In-memory phpredis double for test doubles of the Redis-backed core classes.
 *
 * Implements the exact surface the metadata repository, rate limiter, and lock
 * manager rely on: get/set/del/expire/incr plus a Lua dispatcher for the
 * append-chunk, record-ETag, and owner-checked lock-release scripts. `SET`
 * honours the NX and PX/EX modifiers so lock mutual exclusion and leases behave
 * as they do on a real server, rather than silently succeeding. No real Redis
 * server is ever contacted.
 */
final class FakeRedis extends Redis
{
    /** @var array<string, string> */
    public array $data = [];

    /** @var array<string, int> */
    public array $expirations = [];

    /**
     * Absolute expiry timestamps set through the atomic `SET ... PX`/`EX` path.
     *
     * Kept separate from {@see self::$expirations}, which records the relative
     * TTL handed to `EXPIRE` so existing assertions stay valid. Locks are only
     * ever created via `SET NX PX`, so their lease lives here and is evaluated
     * lazily on read, the way a real server expires keys without cooperation.
     *
     * @var array<string, int>
     */
    public array $deadlines = [];

    public function get(string $key): mixed
    {
        if ($this->hasExpired($key)) {
            return false;
        }

        return $this->data[$key] ?? false;
    }

    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        if ($this->hasExpired($key)) {
            $this->forget($key);
        }

        $options = $this->normalizeSetOptions($options);

        if (($options['nx'] ?? false) && isset($this->data[$key])) {
            return false;
        }

        $this->data[$key] = (string) $value;

        if (isset($options['px'])) {
            $this->deadlines[$key] = time() + (int) ceil($options['px'] / 1000);
        } elseif (isset($options['ex'])) {
            $this->deadlines[$key] = time() + (int) $options['ex'];
        } else {
            unset($this->deadlines[$key]);
        }

        return true;
    }

    public function del(array|string $keys, string ...$other_keys): int
    {
        $all = array_merge((array) $keys, $other_keys);
        $count = 0;
        foreach ($all as $key) {
            if (isset($this->data[$key])) {
                $this->forget($key);
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

    /**
     * Emulates the atomic Lua scripts: chunk append, part-ETag record, and the
     * lock's owner-checked release.
     *
     * The scripts are dispatched by content rather than assumed, because three
     * distinct ones exist. Treating an unfamiliar one as the chunk script would
     * silently drop ETag writes, double-count progress, and make a lock appear
     * releasable by any caller -- hiding exactly the bugs these tests exist to
     * catch.
     *
     * @param list<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        if (str_contains($script, 'redis.call(\'DEL\'')) {
            return $this->applyReleaseLock($args);
        }

        $key = $args[0] ?? null;

        if (!is_string($key) || $this->get($key) === false) {
            return false;
        }

        $state = json_decode((string) $this->data[$key], true);
        if (!is_array($state)) {
            return false;
        }

        $state = str_contains($script, 'partEtags')
            ? $this->applyRecordEtag($state, $args)
            : $this->applyMarkChunk($state, $args);

        $state['updatedAt'] = time();

        $encoded = json_encode($state);
        $this->data[$key] = (string) $encoded;

        return $encoded;
    }

    /**
     * Compare-and-delete, matching the real release script: the key is removed
     * only when the stored token equals the caller's, so a lapsed holder cannot
     * free a lock that a successor now owns.
     *
     * @param list<mixed> $args
     */
    private function applyReleaseLock(array $args): int
    {
        $key = $args[0] ?? null;
        $token = $args[1] ?? null;

        if (!is_string($key) || !is_string($token) || $this->get($key) === false) {
            return 0;
        }

        if ($this->data[$key] !== $token) {
            return 0;
        }

        $this->forget($key);

        return 1;
    }

    /**
     * @param array<int|string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function normalizeSetOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];

        foreach ($options as $name => $value) {
            if (is_string($name)) {
                $normalized[strtolower($name)] = $value;
                continue;
            }

            $normalized[strtolower((string) $value)] = true;
        }

        return $normalized;
    }

    private function hasExpired(string $key): bool
    {
        return isset($this->deadlines[$key]) && $this->deadlines[$key] <= time();
    }

    private function forget(string $key): void
    {
        unset($this->data[$key], $this->expirations[$key], $this->deadlines[$key]);
    }

    /**
     * @param list<mixed> $args
     *
     * @return array<string, mixed>
     */
    private function applyMarkChunk(array $state, array $args): array
    {
        $chunkIndex = (int) ($args[1] ?? -1);

        $uploaded = array_map('intval', $state['uploaded'] ?? []);
        if (!in_array($chunkIndex, $uploaded, true)) {
            $uploaded[] = $chunkIndex;
            sort($uploaded, SORT_NUMERIC);
        }

        $state['uploaded'] = $uploaded;
        $state['isCompleted'] = count($uploaded) === (int) $state['totalChunks'];

        return $state;
    }

    /**
     * Mirrors the `p`-prefixed key convention the real script relies on to keep
     * cjson from encoding a table keyed 1..n as a JSON array.
     *
     * @param list<mixed> $args
     *
     * @return array<string, mixed>
     */
    private function applyRecordEtag(array $state, array $args): array
    {
        $etags = $state['partEtags'] ?? [];
        if (!is_array($etags)) {
            $etags = [];
        }

        $etags['p' . (int) ($args[1] ?? 0)] = (string) ($args[2] ?? '');
        $state['partEtags'] = $etags;

        return $state;
    }
}
