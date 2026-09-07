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
     * Windows-reserved device names that are forbidden even with an extension
     * (CON, PRN, AUX, NUL, COM1-9, LPT1-9). Rejecting them keeps assembled
     * filenames portable to Windows hosts regardless of where the server runs.
     */
    private const RESERVED_DEVICE_NAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    private const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'cgi', 'inc',
        'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'war',
    ];

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

        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new PathTraversalException('Filename is empty or unsafe.');
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if ($extension !== '') {
            $stem = substr($name, 0, -(strlen($extension) + 1));
            $name = $stem . '.' . strtolower($extension);
        }

        if (in_array(strtoupper($extension === '' ? $name : substr($name, 0, -(strlen($extension) + 1))), self::RESERVED_DEVICE_NAMES, true)) {
            throw new PathTraversalException('Filename uses a reserved device name.');
        }

        if ($this->containsExecutableExtension($name)) {
            throw new PathTraversalException('Filename uses a prohibited executable extension.');
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

    public function containsExecutableExtension(string $name): bool
    {
        $parts = explode('.', strtolower($name));
        array_shift($parts);

        foreach ($parts as $extension) {
            if (in_array($extension, self::EXECUTABLE_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }
}
