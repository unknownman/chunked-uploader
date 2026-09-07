<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation\Rules;

use Resumable\ChunkedUploader\Core\Contracts\ConfigurableValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\MagicByteValidator;

final class MagicByteRule implements ConfigurableValidationRuleInterface
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
        $this->validateAllowList($chunk, $this->allowedMimes);
    }

    public function validateWithConfig(Chunk $chunk, UploaderConfig $config): void
    {
        $this->validateAllowList($chunk, $config->allowedMimeTypes);
    }

    /** @param list<string> $allowedMimes */
    private function validateAllowList(Chunk $chunk, array $allowedMimes): void
    {
        if ($allowedMimes === []) {
            return;
        }

        $this->validator->validateFile($chunk->tmpFilePath, $allowedMimes);
    }
}
