<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation\Rules;

use Resumable\ChunkedUploader\Core\Contracts\ConfigurableValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

final class MaxTotalSizeRule implements ConfigurableValidationRuleInterface
{
    /**
     * @param int $maxBytes Maximum total upload size.
     */
    public function __construct(private readonly int $maxBytes)
    {
    }

    /**
     * Enforces the total upload byte limit.
     *
     * @param Chunk $chunk Chunk carrying total upload metadata.
     * @return void
     * @throws InvalidChunkException When total size exceeds the limit.
     */
    public function validate(Chunk $chunk): void
    {
        $this->validateLimit($chunk, $this->maxBytes);
    }

    public function validateWithConfig(Chunk $chunk, UploaderConfig $config): void
    {
        $this->validateLimit($chunk, $config->maxFileSize);
    }

    private function validateLimit(Chunk $chunk, int $maxBytes): void
    {
        if ($chunk->totalSize < 1 || $chunk->totalSize > $maxBytes) {
            throw new InvalidChunkException('Total upload size exceeds the configured maximum.');
        }
    }
}
