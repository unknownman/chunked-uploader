<?php

declare(strict_types=1);

// File: src/Core/Drivers/Storage/S3ObjectKeyResolver.php

namespace Resumable\ChunkedUploader\Core\Drivers\Storage;

use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;

/**
 * Single source of truth for S3 object keys in the multipart flow.
 *
 * Two collaborators need to agree on the destination key: the storage driver,
 * which initiates the multipart upload against a key, and the assembler, which
 * completes it. If each derived the key independently the two could drift, and
 * the completed object would land somewhere the caller was never told about.
 * Both therefore resolve keys here.
 *
 * Key layout:
 *   <basePrefix><identifier>/<finalPrefix><sanitized filename>
 *   e.g. chunks/9f2a.../uploads/report.pdf
 *
 * The identifier and the filename are both sanitized. Keys are attacker-influenced
 * (the filename arrives from the client), so an unsanitized value could otherwise
 * contain traversal segments or a `?`/`#` that S3 would interpret as part of the
 * key rather than the name.
 */
final class S3ObjectKeyResolver
{
    public function __construct(
        private readonly string $bucket = '',
        private readonly string $basePrefix = 'chunks/',
        private readonly string $finalPrefix = 'uploads/',
        private readonly ?PathSanitizer $sanitizer = null,
    ) {
    }

    /**
     * The key the multipart upload targets, i.e. the final assembled object.
     */
    public function finalKey(UploadState $state): string
    {
        return $this->prefixedNamespace($state->identifier)
            . $this->normalizePrefix($this->finalPrefix)
            . $this->sanitizeFilename($state->originalFilename);
    }

    /**
     * The key a legacy, pre-multipart chunk object would occupy.
     *
     * Retained so a deployment upgrading mid-upload can still reap chunk objects
     * written by the previous single-PUT implementation.
     */
    public function legacyChunkKey(string $identifier, int $index): string
    {
        return $this->prefixedNamespace($identifier) . 'chunk_' . $index . '.part';
    }

    /**
     * Shared prefix covering every part of one upload, used to list/purge
     * leftover artifacts belonging to a single identifier.
     */
    public function uploadPrefix(string $identifier): string
    {
        return $this->prefixedNamespace($identifier);
    }

    /**
     * Root prefix of all temporary objects, used by the orphan sweeper.
     */
    public function rootPrefix(): string
    {
        return $this->normalizePrefix($this->basePrefix);
    }

    /**
     * Fully-qualified location handed back to callers on success.
     */
    public function finalUri(UploadState $state): string
    {
        return 's3://' . $this->bucket . '/' . $this->finalKey($state);
    }

    private function prefixedNamespace(string $identifier): string
    {
        return $this->rootPrefix() . $this->sanitizeIdentifier($identifier) . '/';
    }

    /**
     * Normalizes a configured prefix to exactly one trailing separator.
     *
     * The documented defaults already carry a trailing slash ("chunks/"), and
     * operators routinely write either form, so a naive append yields the
     * doubled separator and keys like "chunks//<id>/...". Trimming both ends
     * first makes the result identical for "chunks", "chunks/" and "/chunks/".
     */
    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix, '/ ');
        if ($prefix === '') {
            return '';
        }

        return $prefix . '/';
    }

    private function sanitizeIdentifier(string $identifier): string
    {
        return ($this->sanitizer ?? new PathSanitizer())->sanitizeIdentifier($identifier);
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return $safe !== null && $safe !== '' ? $safe : 'file.bin';
    }
}
