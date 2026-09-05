<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use Resumable\ChunkedUploader\Core\Exceptions\InvalidMimeTypeException;
use Resumable\ChunkedUploader\Core\Security\MagicByteValidator;
use Resumable\ChunkedUploader\Tests\TestCase;

final class MagicByteValidatorTest extends TestCase
{
    public function test_it_reads_only_a_file_header_and_accepts_allowed_mime(): void
    {
        $path = $this->temporaryFile("plain text header\n" . str_repeat('x', 1024 * 1024));
        $validator = new MagicByteValidator();

        self::assertSame('text/plain', $validator->detectMimeType($path));
        self::assertTrue($validator->validate($path, ['text/plain']));
    }

    public function test_it_rejects_an_unapproved_detected_mime_before_storage(): void
    {
        $path = $this->temporaryFile('plain text');

        $this->expectException(InvalidMimeTypeException::class);
        (new MagicByteValidator())->validate($path, ['image/jpeg']);
    }
}
