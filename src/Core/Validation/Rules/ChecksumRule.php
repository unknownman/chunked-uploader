<?php

declare(strict_types=1);

// File: src/Core/Validation/Rules/ChecksumRule.php

namespace Resumable\ChunkedUploader\Core\Validation\Rules;

use Resumable\ChunkedUploader\Core\Contracts\ValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\ChunkChecksum;

/**
 * Verifies an optional per-chunk digest against the actual bytes of the
 * uploaded temporary file.
 *
 * The temp file is streamed in buffered reads rather than loaded whole, keeping
 * memory usage constant. When no checksum is present on the chunk, the rule is
 * a no-op.
 *
 * Scope
 * -----
 * This rule only proves the temp file the web server wrote is intact. It
 * cannot detect corruption introduced between PHP and the wire, which is why
 * S3 deployments should disable it and let `S3ChunkStorage` have the object
 * store verify the part natively instead.
 *
 * Encoding
 * --------
 * The incoming digest is normalised to raw bytes by {@see ChunkChecksum} before
 * comparison. Comparing wire strings directly would reject every valid chunk
 * whose client sends base64 (the browser default), because this rule's own
 * digest is raw.
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
        $expected = ChunkChecksum::decode($chunk->checksum);

        if ($expected === null) {
            return;
        }

        $actual = $this->hashFile($chunk->tmpFilePath);

        if (!hash_equals($expected, $actual)) {
            throw new InvalidChunkException(sprintf(
                'Chunk checksum does not match its contents: the client declared a %s digest but the chunk on disk hashes differently.',
                ChunkChecksum::algorithm($chunk->checksum) ?? $this->algorithm,
            ));
        }
    }

    /**
     * @throws InvalidChunkException when the temp file cannot be read
     */
    private function hashFile(string $path): string
    {
        $stream = @fopen($path, 'rb');
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

            return hash_final($context, true);
        } finally {
            fclose($stream);
        }
    }
}
