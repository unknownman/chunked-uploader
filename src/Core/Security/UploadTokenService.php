<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Security;

final class UploadTokenService
{
    public function __construct(private readonly string $secret)
    {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('The upload token secret must not be empty.');
        }
    }

    /**
     * Creates a token bound to upload identity and client context.
     *
     * @param string $identifier Upload identifier.
     * @param int $totalChunks Expected chunk count.
     * @param int $totalSize Expected total byte size.
     * @param string $salt Client IP or fingerprint binding.
     * @return string Hexadecimal HMAC-SHA256 token.
     */
    public function createToken(string $identifier, int $totalChunks, int $totalSize, string $salt = ''): string
    {
        return hash_hmac('sha256', $this->payload($identifier, $totalChunks, $totalSize, $salt), $this->secret);
    }

    /**
     * Verifies a token using a timing-safe comparison.
     *
     * @param string $token Presented token.
     * @param string $identifier Upload identifier.
     * @param int $totalChunks Expected chunk count.
     * @param int $totalSize Expected total byte size.
     * @param string $salt Client IP or fingerprint binding.
     * @return bool True when the token matches exactly.
     */
    public function verifyToken(string $token, string $identifier, int $totalChunks, int $totalSize, string $salt = ''): bool
    {
        return hash_equals($this->createToken($identifier, $totalChunks, $totalSize, $salt), $token);
    }

    /**
     * Builds the canonical token payload.
     *
     * @param string $identifier Upload identifier.
     * @param int $totalChunks Expected chunk count.
     * @param int $totalSize Expected total byte size.
     * @param string $salt Client binding.
     * @return string Canonical payload.
     */
    private function payload(string $identifier, int $totalChunks, int $totalSize, string $salt): string
    {
        return implode('|', [$identifier, (string) $totalChunks, (string) $totalSize, $salt]);
    }
}
