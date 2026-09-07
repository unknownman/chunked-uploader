<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class MaxChunkSize
{
    public function __construct(public int $bytes)
    {
        if ($this->bytes < 1) {
            throw new \InvalidArgumentException('MaxChunkSize must be positive.');
        }
    }
}
