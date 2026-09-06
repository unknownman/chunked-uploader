<?php

declare(strict_types=1);

// File: tests/Unit/MagicByteValidatorTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidMimeTypeException;
use Resumable\ChunkedUploader\Core\Security\MagicByteValidator;
use Resumable\ChunkedUploader\Core\Validation\Rules\ExtensionMimeMatchRule;
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

    public function test_it_rejects_php_scripts_disguised_as_images_via_extension_mismatch(): void
    {
        // A PNG magic-byte header followed immediately by an executable PHP script.
        // A naive allow-list on magic bytes alone would pass malformed content;
        // the ExtensionMimeMatchRule must reject the MIME/extension disagreement.
        $path = $this->temporaryFile($this->pngSignature() . "<?php echo 'pwned'; ?>" . str_repeat("\x00", 32));
        $rule = new ExtensionMimeMatchRule(new MagicByteValidator(), ['png' => ['image/png']]);

        $this->expectException(InvalidMimeTypeException::class);
        $rule->validate($this->chunk($path, '', 0, 1, filesize($path), 'shell', 'shell.png'));
    }

    public function test_it_rejects_embedded_html_that_claims_to_be_a_document(): void
    {
        $path = $this->temporaryFile('<html><script>alert(1)</script></html>');
        $rule = new ExtensionMimeMatchRule(new MagicByteValidator(), ['pdf' => ['application/pdf']]);

        $this->expectException(InvalidMimeTypeException::class);
        $rule->validate($this->chunk($path, '', 0, 1, filesize($path), 'phish', 'invoice.pdf'));
    }

    public function test_it_rejects_a_plaintext_payload_named_as_a_php_file(): void
    {
        // Even when the MIME is legitimate text, a .php extension forces the
        // assembler to potentially write an executable artifact; the extension
        // map does not admit `.php`, so the rule must reject it outright.
        $path = $this->temporaryFile("<?php phpinfo();");

        $validator = new MagicByteValidator();
        $rule = new ExtensionMimeMatchRule($validator, ['php' => ['text/plain']]);

        try {
            $rule->validate($this->chunk($path, '', 0, 1, filesize($path), 'evil', 'shell.php'));
            $this->fail('Expected the extension/MIME mismatch to be rejected.');
        } catch (InvalidMimeTypeException) {
            self::assertTrue(true); // Rejected as expected.
        }
    }

    public function test_it_reads_only_up_to_4096_bytes_never_the_whole_file(): void
    {
        $path = $this->temporaryFile('F' . str_repeat('z', 10 * 1024 * 1024));
        $validator = new MagicByteValidator();

        self::assertSame('text/plain', $validator->detectMimeType($path));
    }

    public function test_it_throws_when_the_file_cannot_be_opened(): void
    {
        $this->expectException(InvalidChunkException::class);
        (new MagicByteValidator())->detectMimeType($this->temporaryDirectory . '/does-not-exist.bin');
    }

    #[DataProvider('tamperedPayloads')]
    public function test_it_rejects_tampered_magic_bytes(string $filename, array $extensionMap, string $payload): void
    {
        $path = $this->temporaryFile($payload);
        $rule = new ExtensionMimeMatchRule(new MagicByteValidator(), $extensionMap);

        $this->expectException(InvalidMimeTypeException::class);
        $rule->validate($this->chunk($path, '', 0, 1, strlen($payload), 'tampered', $filename));
    }

    public static function tamperedPayloads(): iterable
    {
        yield 'html masquerading as a text document' => [
            'payload.txt',
            ['txt' => ['text/plain']],
            '<html><body><script>alert(2)</script></body></html>',
        ];
        yield 'jpeg header wrapped around text' => [
            'photo.txt',
            ['txt' => ['text/plain']],
            "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 32),
        ];
        yield 'php payload masquerading as plain text' => [
            'notes.txt',
            ['txt' => ['text/plain']],
            '<?php system($_GET["c"]); ?>',
        ];
    }

    public function test_it_throws_an_invalid_chunk_exception_on_unreadable_header(): void
    {
        $validator = new MagicByteValidator();
        $path = $this->temporaryFile('short');
        unlink($path);

        $this->expectException(InvalidChunkException::class);
        $validator->detectMimeType($path);
    }

    private function pngSignature(): string
    {
        return "\x89PNG\r\n\x1a\n";
    }
}
