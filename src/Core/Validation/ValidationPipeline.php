<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation;

use Resumable\ChunkedUploader\Core\Contracts\ValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Models\Chunk;

final class ValidationPipeline
{
    /**
     * @param list<ValidationRuleInterface> $rules Rules executed in order.
     */
    public function __construct(private readonly array $rules = [])
    {
        foreach ($this->rules as $rule) {
            if (!$rule instanceof ValidationRuleInterface) {
                throw new \InvalidArgumentException('Every pipeline entry must be a validation rule.');
            }
        }
    }

    /**
     * Runs all registered rules sequentially.
     *
     * @param Chunk $chunk Chunk to validate.
     * @return void
     */
    public function validate(Chunk $chunk): void
    {
        foreach ($this->rules as $rule) {
            $rule->validate($chunk);
        }
    }
}
