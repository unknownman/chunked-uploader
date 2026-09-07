<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Resumable\ChunkedUploader\Core\Attributes\AllowedMimes;
use Resumable\ChunkedUploader\Core\Attributes\ChunkedUpload;
use Resumable\ChunkedUploader\Core\Attributes\MaxChunkSize;
use Resumable\ChunkedUploader\Core\Attributes\MaxFileSize;
use Resumable\ChunkedUploader\Core\Configuration\ChunkedUploadConfigResolver;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Tests\TestCase;

final class AttributesTest extends TestCase
{
    #[Test]
    public function test_granular_attributes_overlay_a_combined_endpoint_attribute(): void
    {
        $config = (new ChunkedUploadConfigResolver())->resolve(
            new ReflectionMethod(GranularAttributeFixture::class, 'upload'),
            new UploaderConfig(maxChunkSize: 10, maxFileSize: 100, allowedMimeTypes: ['image/png']),
        );

        self::assertSame(20, $config->maxChunkSize);
        self::assertSame(500, $config->maxFileSize);
        self::assertSame(['video/mp4'], $config->allowedMimeTypes);
        self::assertSame('video', $config->tokenSalt);
    }
}

final class GranularAttributeFixture
{
    #[ChunkedUpload(tokenSalt: 'video', maxFileSize: 250)]
    #[AllowedMimes(['video/mp4'])]
    #[MaxFileSize(500)]
    #[MaxChunkSize(20)]
    public function upload(): void
    {
    }
}
