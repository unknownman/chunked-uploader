<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Tests\TestCase;

final class UploadTokenServiceTest extends TestCase
{
    public function test_it_generates_and_verifies_a_token(): void
    {
        $service = new UploadTokenService('unit-test-secret');
        $token = $service->createToken('upload-1', 4, 1000, '127.0.0.1');

        self::assertTrue($service->verifyToken($token, 'upload-1', 4, 1000, '127.0.0.1'));
        self::assertFalse($service->verifyToken($token, 'upload-1', 5, 1000, '127.0.0.1'));
        self::assertFalse($service->verifyToken($token, 'upload-1', 4, 1001, '127.0.0.1'));
        self::assertFalse($service->verifyToken($token . 'x', 'upload-1', 4, 1000, '127.0.0.1'));
    }

    public function test_it_binds_tokens_to_the_client_salt(): void
    {
        $service = new UploadTokenService('unit-test-secret');
        $token = $service->createToken('upload-1', 1, 5, 'fingerprint-a');

        self::assertFalse($service->verifyToken($token, 'upload-1', 1, 5, 'fingerprint-b'));
    }
}
