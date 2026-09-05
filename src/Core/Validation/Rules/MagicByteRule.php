<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation\Rules;

use Resumable\ChunkedUploader\Core\Contracts\ValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\MagicByteValidator;

final class MagicByteRule implements ValidationRuleInterface
{
    /**
     * @param MagicByteValidator $validator Header-only MIME detector.
     * @param list<string> $allowedMimes MIME allow-list.
     */
    public function __construct(private readonly MagicByteValidator $validator, private readonly array $allowedMimes)
    {
    }

    /**
     * Validates the chunk's detected MIME type.
     *
     * @param Chunk $chunk Chunk to inspect.
     * @return void
     */
    public function validate(Chunk $chunk): void
    {
        $this->validator->validateFile($chunk->tmpFilePath, $this->allowedMimes);
    }
}
