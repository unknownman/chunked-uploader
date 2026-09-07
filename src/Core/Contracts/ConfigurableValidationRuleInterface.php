<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Models\Chunk;

interface ConfigurableValidationRuleInterface extends ValidationRuleInterface
{
    public function validateWithConfig(Chunk $chunk, UploaderConfig $config): void;
}
