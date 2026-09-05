<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Security;

use Resumable\ChunkedUploader\Core\Exceptions\PathTraversalException;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;

/**
 * Sanitizes and validates user-supplied filenames and upload identifiers.
 *
 * Defense-in-depth against directory traversal and malformed naming: strips null
 * bytes and control characters, removes traversal segments and path separators,
 * and reduces the input to a safe basename before any filesystem interaction.
 */
final class PathSanitizer
{
    /**
     * Returns a sanitized basename safe for use as a local filename segment.
     *
     * Null bytes, control characters, directory separators, and traversal
     * segments are stripped. The result is truncated to a conservative length
     * and never contains a bare path separator.
     */
    public function sanitizeFilename(string $name): string
    {
        if (str_contains($name, "\0") || str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new PathTraversalException('Filename contains a path traversal marker.');
        }

        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
        $name = trim($name, '.-');

        if ($name === '' || $name === '.' || $name === '..') {
            throw new PathTraversalException('Filename is empty or unsafe.');
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if ($extension !== '') {
            $stem = substr($name, 0, -(strlen($extension) + 1));
            $name = $stem . '.' . strtolower($extension);
        }

        return substr($name, 0, 255);
    }

    /**
     * Returns whether the given upload identifier is structurally valid per
     * the configured pattern. Identifiers must fully match the pattern and
     * must not contain traversal segments or separators.
     */
    public function isValidIdentifier(string $identifier, string $pattern): bool
    {
        return preg_match($pattern, $identifier) === 1;
    }

    /**
     * Sanitizes an identifier by removing every character outside the safe
     * alphanumeric/underscore/hyphen set and collapsing runs of separators.
     */
    public function sanitizeIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]+$/D', $identifier) !== 1) {
            throw new SecurityViolationException('Upload identifier contains unsafe characters.');
        }

        return $identifier;
    }
}