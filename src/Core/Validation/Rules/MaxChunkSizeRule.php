<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation\Rules;

use Resumable\ChunkedUploader\Core\Contracts\ConfigurableValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

final class MaxChunkSizeRule implements ConfigurableValidationRuleInterface
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
        $this->validateLimit($chunk, $this->maxBytes);
    }

    public function validateWithConfig(Chunk $chunk, UploaderConfig $config): void
    {
        $this->validateLimit($chunk, $config->maxChunkSize);
    }

    private function validateLimit(Chunk $chunk, int $maxBytes): void
    {
        $size = @filesize($chunk->tmpFilePath);
        if ($size === false || $size > $maxBytes || $chunk->chunkSize > $maxBytes || $chunk->chunkSize !== $size) {
            throw new InvalidChunkException('Chunk size is invalid or exceeds the configured maximum.');
        }
    }
}
