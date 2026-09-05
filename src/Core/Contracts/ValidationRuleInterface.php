<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Models\Chunk;

interface ValidationRuleInterface
{
    /**
     * Validates one chunk and throws a domain exception on failure.
     *
     * @param Chunk $chunk Chunk to validate.
     * @return void
     */
    public function validate(Chunk $chunk): void;
}
