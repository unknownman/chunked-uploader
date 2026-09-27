<?php

declare(strict_types=1);

// File: tests/Unit/ChecksumRuleTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Validation\Rules\ChecksumRule;
use Resumable\ChunkedUploader\Tests\TestCase;

final class ChecksumRuleTest extends TestCase
{
    #[Test]
    public function test_it_accepts_a_matching_sha256_checksum(): void
    {
        $path = $this->temporaryFile('resumable-content-' . str_repeat('x', 4096));
        $chunk = new Chunk(
            identifier: 'c',
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: 1024,
            totalSize: 1024,
            tmpFilePath: $path,
            originalFilename: 'payload.bin',
            checksum: hash_file('sha256', $path),
        );

        (new ChecksumRule())->validate($chunk);

        self::assertTrue(true);
    }

    #[Test]
    public function test_it_rejects_a_tampered_payload_via_checksum_mismatch(): void
    {
        $path = $this->temporaryFile('honest-contents');
        $chunk = new Chunk(
            identifier: 'c',
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: 15,
            totalSize: 15,
            tmpFilePath: $path,
            originalFilename: 'payload.bin',
            checksum: hash('sha256', 'evil-contents'),
        );

        $this->expectException(InvalidChunkException::class);
        (new ChecksumRule())->validate($chunk);
    }

    #[Test]
    public function test_it_is_a_no_op_when_no_checksum_is_provided(): void
    {
        $path = $this->temporaryFile('anything');
        $chunk = new Chunk(
            identifier: 'c',
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: 8,
            totalSize: 8,
            tmpFilePath: $path,
            originalFilename: 'payload.bin',
        );

        (new ChecksumRule())->validate($chunk);

        self::assertTrue(true);
    }

    #[Test]
    public function test_it_streams_large_files_without_loading_them_into_memory(): void
    {
        $data = str_repeat('Z', 6 * 1024 * 1024); // 6 MiB
        $path = $this->temporaryFile($data);

        $before = memory_get_usage(true);
        $chunk = new Chunk(
            identifier: 'big',
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: strlen($data) / 2,
            totalSize: strlen($data),
            tmpFilePath: $path,
            originalFilename: 'big.bin',
            checksum: hash('sha256', $data),
        );

        (new ChecksumRule())->validate($chunk);
        $growth = memory_get_usage(true) - $before;

        self::assertLessThan(10 * 1024 * 1024, $growth);
    }

    /**
     * Browsers send base64 because that is what WebCrypto produces, so this is
     * the encoding real clients use. Comparing wire strings directly would fail
     * here: `hash_final()` yields hex, so a correct base64 digest would never
     * match and every upload would be reported as corrupted.
     */
    #[Test]
    public function test_it_accepts_a_base64_sha256_checksum(): void
    {
        $body = 'base64-encoded-digest-' . str_repeat('y', 2048);
        $path = $this->temporaryFile($body);
        $chunk = $this->chunkFor($path, base64_encode(hash('sha256', $body, true)));

        (new ChecksumRule())->validate($chunk);

        self::assertTrue(true);
    }

    #[Test]
    public function test_it_rejects_a_base64_sha256_checksum_for_a_tampered_payload(): void
    {
        $path = $this->temporaryFile('honest-contents');
        $chunk = $this->chunkFor($path, base64_encode(hash('sha256', 'evil-contents', true)));

        $this->expectException(InvalidChunkException::class);
        $this->expectExceptionMessage('Chunk checksum does not match its contents');

        (new ChecksumRule())->validate($chunk);
    }

    /**
     * An MD5 digest is a valid, well-formed checksum that simply is not the
     * algorithm this rule hashes with, so it must be reported as a mismatch
     * rather than crashing or silently passing.
     */
    #[Test]
    public function test_it_rejects_an_md5_checksum_under_a_sha256_rule(): void
    {
        $body = 'some-content';
        $path = $this->temporaryFile($body);
        $chunk = $this->chunkFor($path, hash('md5', $body));

        $this->expectException(InvalidChunkException::class);
        $this->expectExceptionMessage('declared a md5 digest');

        (new ChecksumRule())->validate($chunk);
    }

    #[Test]
    public function test_a_blank_checksum_is_treated_as_absent_rather_than_malformed(): void
    {
        $path = $this->temporaryFile('anything');

        (new ChecksumRule())->validate($this->chunkFor($path, '   '));

        self::assertTrue(true);
    }

    #[Test]
    public function test_a_malformed_checksum_is_rejected_before_the_file_is_read(): void
    {
        $path = $this->temporaryFile('anything');
        $chunk = $this->chunkFor($path, 'obviously-not-a-digest');

        $this->expectException(InvalidChunkException::class);
        $this->expectExceptionMessage('Unsupported chunk checksum');

        (new ChecksumRule())->validate($chunk);
    }

    private function chunkFor(string $path, ?string $checksum): Chunk
    {
        $size = (int) filesize($path);

        return new Chunk(
            identifier: 'c',
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: $size,
            totalSize: $size,
            tmpFilePath: $path,
            originalFilename: 'payload.bin',
            checksum: $checksum,
        );
    }
}
