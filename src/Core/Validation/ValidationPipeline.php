<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation;

use Resumable\ChunkedUploader\Core\Contracts\ConfigurableValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Contracts\ValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Models\Chunk;

final class ValidationPipeline
{
    /** @var list<ValidationRuleInterface> */
    private readonly array $rules;

    /**
     * @param list<ValidationRuleInterface|null> $rules Rules executed in order. Null
     *        entries are dropped, which lets a container express an optional rule
     *        (e.g. the local digest check, disabled when the storage backend
     *        verifies chunks itself) without duplicating the list in PHP.
     */
    public function __construct(array $rules = [])
    {
        $kept = [];
        foreach ($rules as $rule) {
            if ($rule === null) {
                continue;
            }

            if (!$rule instanceof ValidationRuleInterface) {
                throw new \InvalidArgumentException('Every pipeline entry must be a validation rule or null.');
            }

            $kept[] = $rule;
        }

        $this->rules = $kept;
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
