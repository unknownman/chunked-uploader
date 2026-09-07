<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Resumable\ChunkedUploader\Core\Attributes\ChunkedUpload;
use Resumable\ChunkedUploader\Core\Configuration\ChunkedUploadConfigResolver;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Tests\TestCase;

final class ChunkedUploadConfigResolverTest extends TestCase
{
    #[Test]
    public function test_endpoint_attribute_overrides_only_declared_global_values(): void
    {
        $resolver = new ChunkedUploadConfigResolver();
        $global = new UploaderConfig(
            maxChunkSize: 10,
            maxFileSize: 100,
            maxChunks: 4,
            allowedMimeTypes: ['image/png'],
            tokenSalt: 'global',
        );

        $resolved = $resolver->resolve(
            new ReflectionMethod(AttributeFixture::class, 'upload'),
            $global,
        );

        self::assertSame(20, $resolved->maxChunkSize);
        self::assertSame(100, $resolved->maxFileSize);
        self::assertSame(2, $resolved->maxChunks);
        self::assertSame(['video/mp4'], $resolved->allowedMimeTypes);
        self::assertSame('endpoint', $resolved->tokenSalt);
    }

    #[Test]
    public function test_unannotated_endpoints_return_the_same_global_instance(): void
    {
        $global = new UploaderConfig();
        $resolved = (new ChunkedUploadConfigResolver())->resolve(
            new ReflectionMethod(AttributeFixture::class, 'plain'),
            $global,
        );

        self::assertSame($global, $resolved);
    }
}

final class AttributeFixture
{
    #[ChunkedUpload(maxChunkSize: 20, maxChunks: 2, allowedMimeTypes: ['video/mp4'], tokenSalt: 'endpoint')]
    public function upload(): void
    {
    }

    public function plain(): void
    {
    }
}
