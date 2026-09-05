<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation\Rules;

use Resumable\ChunkedUploader\Core\Contracts\ValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

final class MaxChunkSizeRule implements ValidationRuleInterface
{
    /**
     * @param int $maxBytes Maximum chunk size in bytes.
     */
    public function __construct(private readonly int $maxBytes)
    {
    }

    /**
     * Enforces the declared and actual chunk byte limit.
     *
     * @param Chunk $chunk Chunk to inspect.
     * @return void
     * @throws InvalidChunkException When the chunk exceeds the limit.
     */
    public function validate(Chunk $chunk): void
    {
        $size = @filesize($chunk->tmpFilePath);
        if ($size === false || $size > $this->maxBytes || $chunk->chunkSize > $this->maxBytes || $chunk->chunkSize !== $size) {
            throw new InvalidChunkException('Chunk size is invalid or exceeds the configured maximum.');
        }
    }
}
