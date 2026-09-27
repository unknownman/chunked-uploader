<?php

declare(strict_types=1);

// File: src/Core/Drivers/Storage/S3ChecksumCodec.php

namespace Resumable\ChunkedUploader\Core\Drivers\Storage;

use Resumable\ChunkedUploader\Core\Exceptions\ChecksumMismatchException;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\ChunkChecksum;

/**
 * Translates a client-supplied chunk digest into the S3 request parameters that
 * make the object store verify the bytes it received.
 *
 * Why this exists
 * ---------------
 * Validating a digest in PHP means reading the chunk back off the local disk and
 * hashing it there, which proves only that the file the web server wrote is
 * intact. It says nothing about what actually crossed the network, and it cannot
 * detect corruption introduced between PHP and the wire. Sending the digest
 * alongside the part instead makes S3 hash the bytes *it* received and reject
 * the upload itself, which is the only place the check is actually meaningful.
 *
 * Wire-format handling (hex vs base64) lives in {@see ChunkChecksum}, shared
 * with the local validation rule so both paths agree on what a digest means.
 */
final class S3ChecksumCodec
{
    public const ALGORITHM_SHA256 = 'SHA256';

    public const ALGORITHM_MD5 = 'MD5';

    private const SHA256_BYTES = 32;

    /**
     * Digest mismatches S3 raises, and what each one actually means.
     *
     * `InvalidDigest` is deliberately *not* grouped with the others: it means the
     * digest the client sent is not even well formed, so retrying the identical
     * request will fail identically. The other two mean the bytes diverged in
     * transit, where a fresh chunk from the client has a real chance of
     * succeeding.
     *
     * @var array<string, string>
     */
    private const ERROR_MESSAGES = [
        'BadDigest' => 'S3 rejected the chunk: the bytes it received do not match the client-supplied digest. '
            . 'The part was most likely corrupted in transit, or the client computed its checksum over a different '
            . 'byte range than it uploaded. Retry the chunk.',
        'XAmzContentSHA256Mismatch' => 'S3 rejected the chunk: the payload checksum S3 computed does not match the '
            . 'one it was sent, so the part was corrupted in transit. Retry the chunk.',
        'InvalidDigest' => 'S3 rejected the client-supplied digest as malformed, so it could not be verified. '
            . 'This is a client bug, not a network fault: retrying the identical request will fail again. '
            . 'Confirm the client sends a base64-encoded SHA-256 or MD5 of the exact chunk bytes.',
    ];

    /**
     * Builds the `uploadPart` parameters that enable native S3 verification.
     *
     * The return type is a closed union of constant array shapes rather than
     * `array<string, string>` on purpose. `S3Client::uploadPart()` declares a
     * sealed argument shape, so the spread at the call site is only provable when
     * the keys are known statically. Widening this to a generic map makes the
     * call unverifiable and pushes the contract back onto a suppression.
     *
     * @return array{}|array{ChecksumSHA256: string, ChecksumAlgorithm: 'SHA256'}|array{ContentMD5: string}
     *         Parameters to merge into the command payload; empty when the
     *         client sent no usable digest
     *
     * @throws InvalidChunkException when a digest is present but unusable
     */
    public static function requestParameters(Chunk $chunk): array
    {
        $digest = ChunkChecksum::decode($chunk->checksum);

        if ($digest === null) {
            return [];
        }

        return self::algorithmFor($digest) === self::ALGORITHM_SHA256
            ? self::sha256Parameters($digest)
            : self::md5Parameters($digest);
    }

    private static function algorithmFor(string $digest): string
    {
        return strlen($digest) === self::SHA256_BYTES ? self::ALGORITHM_SHA256 : self::ALGORITHM_MD5;
    }

    /**
     * `ChecksumSHA256` binds to the `x-amz-checksum-sha256` request header.
     *
     * `ChecksumAlgorithm` is sent alongside it so the SDK does not also attach a
     * default CRC32: the middleware skips its own value whenever an
     * `x-amz-checksum-*` header is already present, and naming the algorithm
     * states the wire intent explicitly.
     *
     * @return array{ChecksumSHA256: string, ChecksumAlgorithm: 'SHA256'}
     */
    private static function sha256Parameters(string $digest): array
    {
        return [
            'ChecksumSHA256' => base64_encode($digest),
            'ChecksumAlgorithm' => self::ALGORITHM_SHA256,
        ];
    }

    /**
     * `ContentMD5` binds to the `Content-MD5` request header.
     *
     * @return array{ContentMD5: string}
     */
    private static function md5Parameters(string $digest): array
    {
        return ['ContentMD5' => base64_encode($digest)];
    }

    /**
     * Turns an S3 digest failure into a typed domain exception.
     *
     * @throws ChecksumMismatchException when the code is a digest failure
     */
    public static function throwForAwsError(string $errorCode, ?string $awsMessage = null): never
    {
        $message = self::ERROR_MESSAGES[$errorCode] ?? null;

        if ($message === null) {
            // Not a digest failure, so the caller re-throws as it sees fit.
            throw new \InvalidArgumentException($errorCode);
        }

        if ($awsMessage !== null && $awsMessage !== '') {
            $message .= ' S3 reported: ' . $awsMessage;
        }

        throw new ChecksumMismatchException($message, $errorCode);
    }

    /**
     * Whether an S3 error code denotes a digest verification failure.
     */
    public static function isDigestFailure(?string $errorCode): bool
    {
        return $errorCode !== null && isset(self::ERROR_MESSAGES[$errorCode]);
    }
}
