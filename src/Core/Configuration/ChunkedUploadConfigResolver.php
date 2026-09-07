<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Configuration;

use ReflectionClass;
use ReflectionMethod;
use Resumable\ChunkedUploader\Core\Attributes\AllowedMimes;
use Resumable\ChunkedUploader\Core\Attributes\ChunkedUpload;
use Resumable\ChunkedUploader\Core\Attributes\MaxChunkSize;
use Resumable\ChunkedUploader\Core\Attributes\MaxFileSize;

/** Resolves optional endpoint attributes without coupling the core to a framework. */
final class ChunkedUploadConfigResolver
{
    /**
     * @param ReflectionMethod|ReflectionClass<object> $endpoint
     */
    public function resolve(ReflectionMethod|ReflectionClass $endpoint, UploaderConfig $global): UploaderConfig
    {
        if ($this->attributes($endpoint) === []) {
            return $global;
        }

        $maxFileSize = null;
        $maxChunkSize = null;
        $maxChunks = null;
        $allowedMimeTypes = null;
        $tokenSalt = null;

        foreach ($this->attributes($endpoint) as $attribute) {
            if ($attribute instanceof ChunkedUpload) {
                $maxFileSize = $attribute->maxFileSize ?? $maxFileSize;
                $maxChunkSize = $attribute->maxChunkSize ?? $maxChunkSize;
                $maxChunks = $attribute->maxChunks ?? $maxChunks;
                $allowedMimeTypes = $attribute->allowedMimeTypes ?? $allowedMimeTypes;
                $tokenSalt = $attribute->tokenSalt ?? $tokenSalt;
            } elseif ($attribute instanceof AllowedMimes) {
                $allowedMimeTypes = $attribute->mimes;
            } elseif ($attribute instanceof MaxFileSize) {
                $maxFileSize = $attribute->bytes;
            } elseif ($attribute instanceof MaxChunkSize) {
                $maxChunkSize = $attribute->bytes;
            }
        }

        return $global->withOverrides($maxFileSize, $maxChunkSize, $maxChunks, $allowedMimeTypes, $tokenSalt);
    }

    /**
     * @param ReflectionMethod|ReflectionClass<object> $endpoint
     */
    /**
     * @param ReflectionMethod|ReflectionClass<object> $endpoint
     * @return list<ChunkedUpload|AllowedMimes|MaxFileSize|MaxChunkSize>
     */
    private function attributes(ReflectionMethod|ReflectionClass $endpoint): array
    {
        $reflectionTargets = $endpoint instanceof ReflectionMethod
            ? [$endpoint->getDeclaringClass(), $endpoint]
            : [$endpoint];
        $resolved = [];
        foreach ($reflectionTargets as $target) {
            foreach ($target->getAttributes() as $attribute) {
                $instance = $attribute->newInstance();
                if ($instance instanceof ChunkedUpload || $instance instanceof AllowedMimes || $instance instanceof MaxFileSize || $instance instanceof MaxChunkSize) {
                    $resolved[] = $instance;
                }
            }
        }

        return $resolved;
    }
}
