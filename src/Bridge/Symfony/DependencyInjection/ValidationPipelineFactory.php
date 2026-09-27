<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/DependencyInjection/ValidationPipelineFactory.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection;

use Resumable\ChunkedUploader\Core\Contracts\ValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Security\MagicByteValidator;
use Resumable\ChunkedUploader\Core\Validation\Rules\ChecksumRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\ExtensionMimeMatchRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\MagicByteRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\MaxChunkSizeRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\MaxTotalSizeRule;
use Resumable\ChunkedUploader\Core\Validation\ValidationPipeline;

/**
 * Assembles the chunk validation pipeline, including the conditional digest check.
 *
 * This exists instead of a plain list in services.php because the checksum rule
 * is the one entry that is sometimes absent, and a DI expression cannot express
 * "this list has a conditional member" without duplicating the rules. Building
 * the list here keeps the ordering in a single place.
 *
 * The digest check is local-only by design. In `storage` mode the backend
 * verifies the bytes it actually received, which is the only place a checksum
 * can catch corruption introduced on the wire; re-hashing the temp file as well
 * would read and hash every chunk a second time to learn nothing new.
 */
final class ValidationPipelineFactory
{
    /**
     * Static because a Symfony factory definition keyed on a class-string is
     * invoked statically, and the dependencies all arrive as arguments anyway.
     *
     * @param list<string> $allowedMimeTypes
     */
    public static function create(
        int $maxChunkSize,
        int $maxFileSize,
        array $allowedMimeTypes,
        MagicByteValidator $magicBytes,
        string $checksumVerify = 'local',
    ): ValidationPipeline {
        return new ValidationPipeline(self::rules(
            $maxChunkSize,
            $maxFileSize,
            $allowedMimeTypes,
            $magicBytes,
            $checksumVerify,
        ));
    }

    /**
     * The rules this factory produces, in execution order.
     *
     * @param list<string> $allowedMimeTypes
     * @return list<ValidationRuleInterface>
     */
    public static function rules(
        int $maxChunkSize,
        int $maxFileSize,
        array $allowedMimeTypes,
        MagicByteValidator $magicBytes,
        string $checksumVerify = 'local',
    ): array {
        $rules = [
            new MaxChunkSizeRule($maxChunkSize),
            new MaxTotalSizeRule($maxFileSize),
            new MagicByteRule($magicBytes, $allowedMimeTypes),
            new ExtensionMimeMatchRule($magicBytes),
        ];

        if ($checksumVerify === 'local') {
            $rules[] = new ChecksumRule();
        }

        return $rules;
    }
}
