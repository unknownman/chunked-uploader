<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AllowedMimes
{
    /**
     * @param list<string> $mimes
     */
    public function __construct(public array $mimes)
    {
        if ($this->mimes === [] || array_filter($this->mimes, static fn (mixed $mime): bool => !is_string($mime) || $mime === '') !== []) {
            throw new \InvalidArgumentException('AllowedMimes requires a non-empty list of MIME strings.');
        }
    }
}
