<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class ChunkedUpload
{
    /**
     * Null values inherit the global UploaderConfig value.
     *
     * @param list<string>|null $allowedMimeTypes
     */
    public function __construct(
        public ?int $maxFileSize = null,
        public ?int $maxChunkSize = null,
        public ?int $maxChunks = null,
        public ?array $allowedMimeTypes = null,
        public ?string $tokenSalt = null,
    ) {
        if ($this->maxFileSize !== null && $this->maxFileSize < 1) {
            throw new \InvalidArgumentException('maxFileSize must be positive.');
        }
        if ($this->maxChunkSize !== null && $this->maxChunkSize < 1) {
            throw new \InvalidArgumentException('maxChunkSize must be positive.');
        }
        if ($this->maxChunks !== null && $this->maxChunks < 1) {
            throw new \InvalidArgumentException('maxChunks must be positive.');
        }
    }
}
