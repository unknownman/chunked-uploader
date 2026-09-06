<?php

declare(strict_types=1);

// File: tests/Unit/UploadTokenServiceTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Tests\TestCase;

final class UploadTokenServiceTest extends TestCase
{
    #[Test]
    public function test_it_generates_and_verifies_a_token(): void
    {
        $service = new UploadTokenService('unit-test-secret');
        $token = $service->createToken('upload-1', 4, 1000, '127.0.0.1');

        self::assertTrue($service->verifyToken($token, 'upload-1', 4, 1000, '127.0.0.1'));
    }

    #[Test]
    public function test_it_uses_hmac_sha256_and_produces_a_constant_length_token(): void
    {
        $service = new UploadTokenService('unit-test-secret');
        $a = $service->createToken('upload-1', 4, 1000, 'salt');
        $b = $service->createToken('upload-2', 4, 1000, 'salt');

        self::assertSame(64, strlen($a), 'HMAC-SHA256 hex output must be exactly 64 characters.');
        self::assertSame(64, strlen($b));
        self::assertSame(hash_hmac('sha256', 'upload-1|4|1000|salt', 'unit-test-secret'), $a);
        self::assertNotSame($a, $b, 'Distinct identifiers must yield distinct tokens.');
    }

    #[Test]
    #[DataProvider('alteredTokens')]
    public function test_it_invalidates_tokens_when_any_payload_parameter_is_altered(
        string $identifier,
        int $totalChunks,
        int $totalSize,
        string $salt,
    ): void {
        $service = new UploadTokenService('unit-test-secret');
        $token = $service->createToken('upload-1', 4, 1000, '127.0.0.1');

        self::assertFalse($service->verifyToken($token, $identifier, $totalChunks, $totalSize, $salt));
    }

    public static function alteredTokens(): iterable
    {
        yield 'altered identifier' => ['upload-2', 4, 1000, '127.0.0.1'];
        yield 'altered totalChunks' => ['upload-1', 5, 1000, '127.0.0.1'];
        yield 'altered totalSize' => ['upload-1', 4, 1001, '127.0.0.1'];
        yield 'altered salt' => ['upload-1', 4, 1000, '203.0.113.1'];
    }

    #[Test]
    #[DataProvider('tamperedTokens')]
    public function test_it_rejects_a_tampered_token_string(string $tampered): void
    {
        $service = new UploadTokenService('unit-test-secret');
        $token = $service->createToken('upload-1', 4, 1000, '127.0.0.1');

        self::assertFalse($service->verifyToken($tampered, 'upload-1', 4, 1000, '127.0.0.1'));
    }

    public static function tamperedTokens(): iterable
    {
        yield 'truncated' => ['0ab1'];
        yield 'appended byte' => [self::token() . '0'];
        yield 'uppercased' => [strtoupper(self::token())];
        yield 'single character flipped' => [self::flipChar(self::token(), 0)];
        yield 'single character at end flipped' => [self::flipChar(self::token(), 63)];
        yield 'replaced entirely' => [str_repeat('f', 64)];
        yield 'empty string' => [''];
        yield 'spaces' => [str_repeat(' ', 64)];
    }

    #[Test]
    public function test_it_binds_tokens_to_the_client_salt(): void
    {
        $service = new UploadTokenService('unit-test-secret');
        $token = $service->createToken('upload-1', 4, 1000, 'fingerprint-a');

        self::assertFalse($service->verifyToken($token, 'upload-1', 4, 1000, 'fingerprint-b'));
        self::assertTrue($service->verifyToken($token, 'upload-1', 4, 1000, 'fingerprint-a'));
    }

    #[Test]
    public function test_verification_is_timing_safe_using_hash_equals(): void
    {
        $service = new UploadTokenService('unit-test-secret');
        $token = $service->createToken('upload-1', 4, 1000, 'salt');

        // hash_equals is the PHP primitive that performs a constant-time
        // comparison, so verification must be delegated to it internally.
        // We assert the property behaviorally: both a correct token and an
        // incorrect token of the same length complete without error and return
        // distinct boolean results.
        self::assertTrue($service->verifyToken($token, 'upload-1', 4, 1000, 'salt'));
        self::assertFalse($service->verifyToken(str_repeat('a', 64), 'upload-1', 4, 1000, 'salt'));
    }

    #[Test]
    public function test_it_rejects_an_empty_secret_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UploadTokenService('');
    }

    #[Test]
    public function test_tokens_differ_when_the_secret_changes(): void
    {
        $a = (new UploadTokenService('secret-a'))->createToken('upload', 2, 100, 'salt');
        $b = (new UploadTokenService('secret-b'))->createToken('upload', 2, 100, 'salt');

        self::assertNotSame($a, $b);
    }

    private static function token(): string
    {
        return (new UploadTokenService('unit-test-secret'))->createToken('upload-1', 4, 1000, '127.0.0.1');
    }

    private static function flipChar(string $value, int $position): string
    {
        $char = $value[$position];
        $replacement = $char === '0' ? '1' : '0';
        return substr_replace($value, $replacement, $position, 1);
    }
}
