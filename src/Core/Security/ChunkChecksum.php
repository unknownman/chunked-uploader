<?php

declare(strict_types=1);

// File: src/Core/Security/ChunkChecksum.php

namespace Resumable\ChunkedUploader\Core\Security;

use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;

/**
 * Single authority for interpreting a client-supplied chunk digest.
 *
 * A digest reaches the server in one of two encodings. Browsers emit base64
 * (WebCrypto returns that natively); PHP's `hash_final()` emits lowercase hex.
 * Both appear in the wild, so every consumer normalises through this class
 * rather than comparing wire strings directly -- a base64 digest compared
 * against a hex digest fails a `hash_equals()` check 100% of the time, which
 * reads as "corrupted upload" and is very hard to diagnose from a stack trace.
 *
 * SHA-256 (32 bytes) and MD5 (16 bytes) are the two digests the supported
 * storage backends accept.
 */
final class ChunkChecksum
{
    public const ALGO_SHA256 = 'sha256';
    public const ALGO_MD5 = 'md5';

    /** Bytes of digest expected per algorithm. */
    private const LENGTHS = [
        self::ALGO_SHA256 => 32,
        self::ALGO_MD5 => 16,
    ];

    /**
     * Decodes a digest to its raw bytes, or null when the client sent none.
     *
     * @throws InvalidChunkException when the value is neither hex nor base64 for
     *                               a supported digest length
     */
    public static function decode(?string $checksum): ?string
    {
        // Note: the value is trimmed but never case-folded. Base64 is
        // case-sensitive, so lowercasing here would silently corrupt a digest
        // whose encoding happens to contain uppercase characters -- the server
        // would then reject bytes the client computed correctly.
        $normalized = trim($checksum ?? '');

        // A blank value means "no digest supplied", which is not an error: the
        // client may be running outside a secure context where WebCrypto and
        // therefore checksums are unavailable.
        if ($normalized === '') {
            return null;
        }

        $binary = self::tryDecode($normalized, self::ALGO_SHA256);

        if ($binary === null) {
            $binary = self::tryDecode($normalized, self::ALGO_MD5);
        }

        if ($binary === null) {
            throw new InvalidChunkException(sprintf(
                'Unsupported chunk checksum. Expected a hex or base64 SHA-256 (32 bytes) or MD5 (16 bytes) digest, got %d characters.',
                strlen($normalized),
            ));
        }

        return $binary;
    }

    /**
     * Returns the algorithm a digest belongs to, or null when absent.
     *
     * @throws InvalidChunkException when the value is unusable
     */
    public static function algorithm(?string $checksum): ?string
    {
        $normalized = trim($checksum ?? '');

        if ($normalized === '') {
            return null;
        }

        if (self::tryDecode($normalized, self::ALGO_SHA256) !== null) {
            return self::ALGO_SHA256;
        }

        if (self::tryDecode($normalized, self::ALGO_MD5) !== null) {
            return self::ALGO_MD5;
        }

        self::decode($normalized);

        return null; // unreachable; decode() always throws for unusable input
    }

    /**
     * Hashes a raw string to the same raw representation {@see decode()} returns.
     */
    public static function hashBinary(string $algorithm, string $data): string
    {
        return hash($algorithm, $data, true);
    }

    /**
     * Attempts a strict decode, returning null when the value simply does not
     * represent this algorithm.
     */
    private static function tryDecode(string $value, string $algorithm): ?string
    {
        $expected = self::LENGTHS[$algorithm];

        // Hex is tried first. The lengths never collide: base64 of 16 bytes is 24
        // characters and of 32 bytes is 44, neither of which is a valid hex
        // length, so trying hex first cannot misclassify base64 input.
        // ctype_xdigit() and hex2bin() both accept mixed case, so no case
        // folding is needed or safe here.
        if (strlen($value) === $expected * 2 && ctype_xdigit($value)) {
            $binary = hex2bin($value);

            return $binary === false ? null : $binary;
        }

        // base64_decode() is deliberately non-strict by default, which would
        // silently accept garbage; strict mode turns that into a clean failure.
        $decoded = base64_decode($value, true);

        if ($decoded !== false && strlen($decoded) === $expected) {
            return $decoded;
        }

        return null;
    }
}
