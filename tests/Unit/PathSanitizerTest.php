<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Resumable\ChunkedUploader\Core\Exceptions\PathTraversalException;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Tests\TestCase;

final class PathSanitizerTest extends TestCase
{
    public function test_it_normalizes_a_safe_filename_and_extension(): void
    {
        self::assertSame('video.mp4', (new PathSanitizer())->sanitizeFilename('video.MP4'));
    }

    #[DataProvider('traversalVectors')]
    public function test_it_rejects_directory_traversal_and_null_bytes(string $filename): void
    {
        $this->expectException(PathTraversalException::class);
        (new PathSanitizer())->sanitizeFilename($filename);
    }

    public static function traversalVectors(): iterable
    {
        yield 'unix traversal' => ['../../etc/passwd'];
        yield 'mixed traversal' => ['upload/../test.mp4'];
        yield 'windows traversal' => ['..\\secret.txt'];
        yield 'null byte' => ["image.jpg\0.php"];
    }

    public function test_it_rejects_unsafe_identifiers_instead_of_rewriting_them(): void
    {
        $this->expectException(SecurityViolationException::class);
        (new PathSanitizer())->sanitizeIdentifier('../upload');
    }
}
