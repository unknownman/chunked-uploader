<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation\Rules;

use Resumable\ChunkedUploader\Core\Contracts\ValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

final class MaxTotalSizeRule implements ValidationRuleInterface
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
        if ($chunk->totalSize < 1 || $chunk->totalSize > $this->maxBytes) {
            throw new InvalidChunkException('Total upload size exceeds the configured maximum.');
        }
    }
}
