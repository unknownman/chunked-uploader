<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation;

use Resumable\ChunkedUploader\Core\Contracts\ConfigurableValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Contracts\ValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
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
    public function validate(Chunk $chunk, ?UploaderConfig $config = null): void
    {
        foreach ($this->rules as $rule) {
            if ($config !== null && $rule instanceof ConfigurableValidationRuleInterface) {
                $rule->validateWithConfig($chunk, $config);
                continue;
            }

            $rule->validate($chunk);
        }
    }
}
