<?php

declare(strict_types=1);

// File: tests/Unit/S3ChecksumCodecTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Resumable\ChunkedUploader\Core\Drivers\Storage\S3ChecksumCodec;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;

final class S3ChecksumCodecTest extends TestCase
{
    #[Test]
    #[DataProvider('wireFormatProvider')]
    public function test_it_maps_every_accepted_wire_format_to_the_sdk_parameters(
        string $checksum,
        array $expected,
    ): void {
        self::assertSame($expected, S3ChecksumCodec::requestParameters($this->chunk($checksum)));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function wireFormatProvider(): iterable
    {
        $sha256 = hash('sha256', 'DATA', true);
        $md5 = hash('md5', 'DATA', true);

        yield 'base64 sha256' => [base64_encode($sha256), [
            'ChecksumSHA256' => base64_encode($sha256),
            'ChecksumAlgorithm' => 'SHA256',
        ]];

        yield 'hex sha256' => [bin2hex($sha256), [
            'ChecksumSHA256' => base64_encode($sha256),
            'ChecksumAlgorithm' => 'SHA256',
        ]];

        yield 'base64 md5' => [base64_encode($md5), ['ContentMD5' => base64_encode($md5)]];

        yield 'hex md5' => [bin2hex($md5), ['ContentMD5' => base64_encode($md5)]];

        yield 'uppercase hex sha256' => [strtoupper(bin2hex($sha256)), [
            'ChecksumSHA256' => base64_encode($sha256),
            'ChecksumAlgorithm' => 'SHA256',
        ]];

        yield 'surrounding whitespace' => ["  " . base64_encode($sha256) . "\n", [
            'ChecksumSHA256' => base64_encode($sha256),
            'ChecksumAlgorithm' => 'SHA256',
        ]];
    }

    /**
     * Base64 is case-sensitive, so any case folding anywhere in the decode path
     * silently corrupts a digest whose encoding contains uppercase characters.
     * Sweeping a range of payloads is what makes that regression detectable --
     * a single fixed digest can easily avoid the uppercase range entirely.
     */
    #[Test]
    public function test_base64_digests_are_never_case_folded(): void
    {
        $sawUppercase = false;

        for ($i = 0; $i < 200; $i++) {
            $body = 'payload-' . $i;
            $wire = base64_encode(hash('sha256', $body, true));

            $sawUppercase = $sawUppercase || preg_match('/[A-Z]/', $wire) === 1;

            self::assertSame(
                ['ChecksumSHA256' => $wire, 'ChecksumAlgorithm' => 'SHA256'],
                S3ChecksumCodec::requestParameters($this->chunk($wire)),
                'base64 digest was altered for body: ' . $body,
            );
        }

        self::assertTrue($sawUppercase, 'fixture must exercise uppercase base64 to be meaningful');
    }

    #[Test]
    public function test_a_32_character_hex_digest_is_md5_and_never_misread_as_base64(): void
    {
        // The only genuinely ambiguous case: base64 of 16 bytes is 24 characters,
        // so a 32-character value can only be hex. Hex must therefore win.
        $md5 = hash('md5', 'DATA', true);

        self::assertSame(['ContentMD5' => base64_encode($md5)], S3ChecksumCodec::requestParameters($this->chunk(bin2hex($md5))));
    }

    #[Test]
    public function test_no_checksum_means_no_parameters(): void
    {
        self::assertSame([], S3ChecksumCodec::requestParameters($this->chunk(null)));
    }

    #[Test]
    public function test_a_blank_checksum_is_treated_as_absent(): void
    {
        self::assertSame([], S3ChecksumCodec::requestParameters($this->chunk('')));
        self::assertSame([], S3ChecksumCodec::requestParameters($this->chunk('   ')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableDigestProvider(): iterable
    {
        yield 'not a digest' => ['hello'];
        yield 'wrong length' => [str_repeat('a', 40)];
        yield 'hex with a stray character' => [str_repeat('z', 64)];
        yield 'truncated sha256' => [substr(hash('sha256', 'DATA'), 0, 63)];
        yield 'sha1 length' => [hash('sha1', 'DATA')];
    }

    #[Test]
    #[DataProvider('unusableDigestProvider')]
    public function test_an_unusable_digest_is_rejected_with_an_actionable_message(string $checksum): void
    {
        $this->expectException(InvalidChunkException::class);
        $this->expectExceptionMessage('Expected a hex or base64 SHA-256 (32 bytes) or MD5 (16 bytes) digest');

        S3ChecksumCodec::requestParameters($this->chunk($checksum));
    }

    #[Test]
    public function test_it_recognises_exactly_the_digest_failure_codes(): void
    {
        self::assertTrue(S3ChecksumCodec::isDigestFailure('BadDigest'));
        self::assertTrue(S3ChecksumCodec::isDigestFailure('InvalidDigest'));
        self::assertTrue(S3ChecksumCodec::isDigestFailure('XAmzContentSHA256Mismatch'));

        self::assertFalse(S3ChecksumCodec::isDigestFailure('InternalError'));
        self::assertFalse(S3ChecksumCodec::isDigestFailure('NoSuchUpload'));
        self::assertFalse(S3ChecksumCodec::isDigestFailure(null));
    }

    #[Test]
    public function test_the_s3_message_is_appended_for_operator_correlation(): void
    {
        try {
            S3ChecksumCodec::throwForAwsError('BadDigest', 'The Content-MD5 you specified did not match.');
            self::fail('Expected a digest failure to throw.');
        } catch (InvalidChunkException $e) {
            self::assertStringContainsString('S3 reported: The Content-MD5 you specified', $e->getMessage());
        }
    }

    #[Test]
    public function test_an_unrelated_error_code_is_not_treated_as_a_digest_failure(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        S3ChecksumCodec::throwForAwsError('InternalError', 'boom');
    }

    private function chunk(?string $checksum): Chunk
    {
        return new Chunk(
            identifier: 'track',
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: 4,
            totalSize: 4,
            tmpFilePath: '',
            originalFilename: 'x.bin',
            checksum: $checksum,
        );
    }
}
