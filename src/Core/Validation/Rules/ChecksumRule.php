<?php

declare(strict_types=1);

// File: src/Core/Validation/Rules/ChecksumRule.php

namespace Resumable\ChunkedUploader\Core\Validation\Rules;

use Resumable\ChunkedUploader\Core\Contracts\ValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

/**
 * Verifies an optional per-chunk SHA-256 checksum against the actual bytes of
 * the uploaded temporary file.
 *
 * The temp file is streamed in buffered reads rather than loaded whole, keeping
 * memory usage constant. When no checksum is present on the chunk, the rule is
 * a no-op.
 */
final class ChecksumRule implements ValidationRuleInterface
{
    private const BUFFER = 4194304; // 4 MB

    /**
     * @param string $algorithm Hashing algorithm as recognized by hash_init(); defaults to sha256
     */
    public function __construct(private readonly string $algorithm = 'sha256')
    {
    }

    public function validate(Chunk $chunk): void
    {
        if ($chunk->checksum === null || $chunk->checksum === '') {
            return;
        }

        $stream = @fopen($chunk->tmpFilePath, 'rb');
        if ($stream === false) {
            throw new InvalidChunkException('Unable to open chunk for checksum verification.');
        }

        try {
            $context = hash_init($this->algorithm);
            while (!feof($stream)) {
                $data = fread($stream, self::BUFFER);
                if ($data === false) {
                    throw new InvalidChunkException('Unable to read chunk during checksum verification.');
                }
                hash_update($context, $data);
            }

            $actual = hash_final($context);
        } finally {
            fclose($stream);
        }

        if (!hash_equals($chunk->checksum, $actual)) {
            throw new InvalidChunkException('Chunk checksum does not match its contents.');
        }
    }
}
