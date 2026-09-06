<?php

declare(strict_types=1);

// File: tests/Unit/PathSanitizerTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Exceptions\PathTraversalException;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Tests\TestCase;

final class PathSanitizerTest extends TestCase
{
    #[Test]
    public function test_it_normalizes_a_safe_filename_and_lowercases_the_extension(): void
    {
        self::assertSame('video.mp4', (new PathSanitizer())->sanitizeFilename('video.MP4'));
    }

    #[Test]
    #[DataProvider('traversalVectors')]
    public function test_it_rejects_directory_traversal_and_null_byte_injection(string $filename): void
    {
        $this->expectException(PathTraversalException::class);
        (new PathSanitizer())->sanitizeFilename($filename);
    }

    public static function traversalVectors(): iterable
    {
        yield 'unix absolute path' => ['/etc/passwd'];
        yield 'unix traversal' => ['../../etc/passwd'];
        yield 'deep traversal' => ['../../../../../../etc/shadow'];
        yield 'mixed traversal' => ['upload/../test.mp4'];
        yield 'traversal with extension' => ['../images/../../etc/passwd'];
        yield 'windows traversal' => ['..\\secret.txt'];
        yield 'windows drive path' => ['C:\\Windows\\system32\\cmd.exe'];
        yield 'encoded traversal' => ['..%2f%2e%2e%2fetc/passwd'];
        yield 'null byte before extension' => ["image.jpg\0.php"];
        yield 'null byte in middle' => ["foo\0bar.txt"];
        yield 'dot dot filename' => ['..'];
        yield 'dot dot filename with ext' => ['..jpg'];
    }

    #[Test]
    #[DataProvider('safeFilenames')]
    public function test_it_allows_legitimate_filenames(string $input, string $expected): void
    {
        self::assertSame($expected, (new PathSanitizer())->sanitizeFilename($input));
    }

    public static function safeFilenames(): iterable
    {
        yield 'simple' => ['report.txt', 'report.txt'];
        yield 'spaces' => ['quarterly report.pdf', 'quarterly_report.pdf'];
        yield 'uppercase extension' => ['PHOTO.JPG', 'PHOTO.jpg'];
        yield 'hyphen and underscore' => ['my-file_v2.tar.gz', 'my-file_v2.tar.gz'];
        yield 'numbers' => ['12345.csv', '12345.csv'];
        yield 'unicode stripped (multi-byte becomes underscores)' => ['café menu.txt', 'caf___menu.txt'];
        yield 'dotted prefix trimmed' => ['.hidden.txt', 'hidden.txt'];
        yield 'multiple dots in name' => ['a.b.c.png', 'a.b.c.png'];
    }

    #[Test]
    public function test_it_rejects_unsafe_identifiers_instead_of_rewriting_them(): void
    {
        $this->expectException(SecurityViolationException::class);
        (new PathSanitizer())->sanitizeIdentifier('../upload');
    }

    #[Test]
    #[DataProvider('identifierVectors')]
    public function test_it_rejects_unsafe_upload_identifiers(string $identifier): void
    {
        $this->expectException(SecurityViolationException::class);
        (new PathSanitizer())->sanitizeIdentifier($identifier);
    }

    public static function identifierVectors(): iterable
    {
        yield 'traversal' => ['../../upload'];
        yield 'slash' => ['a/b'];
        yield 'backslash' => ['a\\b'];
        yield 'whitespace' => ['a b'];
        yield 'newline' => ["a\nb"];
        yield 'special characters' => ['a!b@c#'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('safeIdentifiers')]
    public function test_it_accepts_safe_upload_identifiers(string $identifier): void
    {
        self::assertSame($identifier, (new PathSanitizer())->sanitizeIdentifier($identifier));
    }

    public static function safeIdentifiers(): iterable
    {
        yield 'alphanumeric' => ['upload123'];
        yield 'with hyphens' => ['upload-123'];
        yield 'with underscores' => ['upload_123'];
        yield 'mixed' => ['Upload-ABC_123'];
    }

    #[Test]
    public function test_is_valid_identifier_respects_the_configured_pattern(): void
    {
        $sanitizer = new PathSanitizer();
        self::assertTrue($sanitizer->isValidIdentifier('upload_123', '/^[a-zA-Z0-9_-]{1,128}$/D'));
        self::assertFalse($sanitizer->isValidIdentifier('not/ok', '/^[a-zA-Z0-9_-]{1,128}$/D'));
        self::assertTrue($sanitizer->isValidIdentifier('ok', '/^[a-z]+$/D'));
        self::assertFalse($sanitizer->isValidIdentifier('OK', '/^[a-z]+$/D'));
    }

    #[Test]
    public function test_it_truncates_overly_long_filenames_to_255_characters(): void
    {
        $long = str_repeat('a', 500) . '.txt';
        self::assertSame(255, strlen((new PathSanitizer())->sanitizeFilename($long)));
    }
}
