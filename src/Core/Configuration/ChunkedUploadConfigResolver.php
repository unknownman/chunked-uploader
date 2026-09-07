<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Configuration;

use ReflectionClass;
use ReflectionMethod;
use Resumable\ChunkedUploader\Core\Attributes\ChunkedUpload;

/** Resolves optional endpoint attributes without coupling the core to a framework. */
final class ChunkedUploadConfigResolver
{
    /**
     * @param ReflectionMethod|ReflectionClass<object> $endpoint
     */
    public function resolve(ReflectionMethod|ReflectionClass $endpoint, UploaderConfig $global): UploaderConfig
    {
        $attribute = $this->attribute($endpoint);
        if ($attribute === null) {
            return $global;
        }

        return $global->withOverrides(
            maxFileSize: $attribute->maxFileSize,
            maxChunkSize: $attribute->maxChunkSize,
            maxChunks: $attribute->maxChunks,
            allowedMimeTypes: $attribute->allowedMimeTypes,
            tokenSalt: $attribute->tokenSalt,
        );
    }

    /**
     * @param ReflectionMethod|ReflectionClass<object> $endpoint
     */
    private function attribute(ReflectionMethod|ReflectionClass $endpoint): ?ChunkedUpload
    {
        $attributes = $endpoint->getAttributes(ChunkedUpload::class);
        if ($attributes === []) {
            return null;
        }

        $instance = $attributes[0]->newInstance();
        if (!$instance instanceof ChunkedUpload) {
            throw new \LogicException('Invalid ChunkedUpload attribute instance.');
        }

        return $instance;
    }
}
