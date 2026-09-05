<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Drivers\Security;

/**
 * HMAC-based upload token generator and verifier.
 */
final class UploadTokenManager
{
    public function __construct(private readonly string $secret)
    {
    }

    public function generateToken(string $identifier): string
    {
        $this->ensureIdentifierSafe($identifier);

        return hash_hmac('sha256', $identifier, $this->secret);
    }

    public function verifyToken(string $identifier, string $token): bool
    {
        $this->ensureIdentifierSafe($identifier);

        $expected = $this->generateToken($identifier);

        return hash_equals($expected, $token);
    }

    private function ensureIdentifierSafe(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z0-9]+$/', $identifier)) {
            throw new \InvalidArgumentException('Unsafe identifier');
        }
    }
}
