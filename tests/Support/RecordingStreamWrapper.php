<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Support;

/**
 * A `fopen()`-able stream that records whether it was ever closed.
 *
 * Exists so a test can assert that a driver releases the source handle on the
 * error paths, not only on success. A resource-count assertion would be
 * equivalent in spirit but couples the test to whatever else the runner happens
 * to have open; this records the open/close pair for the exact handle the
 * driver opened.
 */
final class RecordingStreamWrapper
{
    public static int $opens = 0;

    public static int $closes = 0;

    /**
     * The scheme this wrapper answers to, e.g. `recording://chunk`.
     */
    public const SCHEME = 'chunkrecording';

    private string $contents = '';

    private int $position = 0;

    public static function register(): void
    {
        self::$opens = 0;
        self::$closes = 0;

        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public static function reset(): void
    {
        self::$opens = 0;
        self::$closes = 0;
    }

    /**
     * True when every handle the driver opened was also closed.
     */
    public static function isBalanced(): bool
    {
        return self::$opens > 0 && self::$opens === self::$closes;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opens++;

        return true;
    }

    public function stream_read(int $count): string
    {
        $slice = substr($this->contents, $this->position, $count);
        $this->position += strlen($slice);

        return $slice;
    }

    public function stream_write(string $data): int
    {
        $this->contents .= $data;

        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $this->position = $offset;

        return true;
    }

    public function stream_stat(): array
    {
        return ['size' => strlen($this->contents)];
    }

    /**
     * Required for filesize()/stat() against the wrapper path, which the Chunk
     * value object calls while it is being constructed.
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['size' => 4];
    }

    public function stream_close(): void
    {
        self::$closes++;
    }
}
