<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Validation\Rules;

use Resumable\ChunkedUploader\Core\Contracts\ValidationRuleInterface;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidMimeTypeException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\MagicByteValidator;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;

final class ExtensionMimeMatchRule implements ValidationRuleInterface
{
    /**
     * @param MagicByteValidator $validator Header-only MIME detector.
     * @param array<string, list<string>> $extensionMap Extension to accepted MIME map.
     */
    public function __construct(private readonly MagicByteValidator $validator, private readonly array $extensionMap = [])
    {
    }

    /**
     * Ensures the filename extension matches the detected MIME type.
     *
     * @param Chunk $chunk Chunk to inspect.
     * @return void
     * @throws InvalidMimeTypeException When the extension and MIME disagree.
     */
    public function validate(Chunk $chunk): void
    {
        $sanitizer = new PathSanitizer();
        if ($sanitizer->containsExecutableExtension($chunk->originalFilename)) {
            throw new InvalidMimeTypeException('Executable filename extensions are not allowed.');
        }

        $extension = strtolower((string) pathinfo($chunk->originalFilename, PATHINFO_EXTENSION));
        $mime = $this->validator->detectMimeType($chunk->tmpFilePath);
        $map = $this->extensionMap !== [] ? $this->extensionMap : self::defaultMap();

        if (!isset($map[$extension]) || !in_array($mime, $map[$extension], true)) {
            throw new InvalidMimeTypeException(sprintf('Extension .%s does not match MIME type %s.', $extension, $mime));
        }
    }

    /**
     * Provides conservative common extension mappings.
     *
     * @return array<string, list<string>> Default extension mapping.
     */
    private static function defaultMap(): array
    {
        return [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
            'gif' => ['image/gif'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'],
            'zip' => ['application/zip'], 'gz' => ['application/gzip'], 'tar' => ['application/x-tar'],
            'mp4' => ['video/mp4'], 'mp3' => ['audio/mpeg'], 'txt' => ['text/plain'],
        ];
    }
}
