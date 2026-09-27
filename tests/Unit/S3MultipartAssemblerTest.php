<?php

declare(strict_types=1);

// File: tests/Unit/S3MultipartAssemblerTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Assembler\S3MultipartAssembler;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Exceptions\AssemblyException;
use Resumable\ChunkedUploader\Core\Exceptions\MissingChunkException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\TestCase;
use Resumable\ChunkedUploader\Tests\Support\FakeS3Client;

final class S3MultipartAssemblerTest extends TestCase
{
    #[Test]
    public function test_complete_multipart_upload_sends_every_part_in_ascending_order(): void
    {
        $client = new FakeS3Client();

        $state = (new UploadState('track', 3, 30, 'report.pdf', [0, 1, 2], false))
            ->withMultipartUploadId('upload-abc')
            ->withPartEtag(1, '"e1"')
            ->withPartEtag(2, '"e2"')
            ->withPartEtag(3, '"e3"');

        $uri = (new S3MultipartAssembler($client, 'my-bucket'))->assemble($state, $this->storageStub());

        $calls = $client->argsFor('completeMultipartUpload');
        self::assertCount(1, $calls);
        self::assertSame('my-bucket', $calls[0]['Bucket']);
        self::assertSame('chunks/track/uploads/report.pdf', $calls[0]['Key']);
        self::assertSame('upload-abc', $calls[0]['UploadId']);
        self::assertSame([
            ['PartNumber' => 1, 'ETag' => '"e1"'],
            ['PartNumber' => 2, 'ETag' => '"e2"'],
            ['PartNumber' => 3, 'ETag' => '"e3"'],
        ], $calls[0]['Parts']);

        self::assertSame('s3://my-bucket/chunks/track/uploads/report.pdf', $uri);
    }

    #[Test]
    public function test_parts_are_sorted_even_when_chunks_arrived_out_of_order(): void
    {
        $client = new FakeS3Client();

        $state = (new UploadState('track', 3, 30, 'report.pdf', [0, 1, 2], false))
            ->withMultipartUploadId('upload-abc')
            ->withPartEtag(3, '"e3"')
            ->withPartEtag(1, '"e1"')
            ->withPartEtag(2, '"e2"');

        (new S3MultipartAssembler($client, 'my-bucket'))->assemble($state, $this->storageStub());

        $parts = $client->argsFor('completeMultipartUpload')[0]['Parts'];

        // S3 rejects an out-of-order manifest, and parallel chunk uploads make
        // out-of-order arrival the normal case rather than an edge case.
        self::assertSame([1, 2, 3], array_column($parts, 'PartNumber'));
    }

    #[Test]
    public function test_a_missing_part_etag_fails_instead_of_silently_truncating_the_object(): void
    {
        $client = new FakeS3Client();

        $state = (new UploadState('track', 3, 30, 'report.pdf', [0, 1, 2], false))
            ->withMultipartUploadId('upload-abc')
            ->withPartEtag(1, '"e1"')
            ->withPartEtag(3, '"e3"');

        try {
            (new S3MultipartAssembler($client, 'my-bucket'))->assemble($state, $this->storageStub());
            self::fail('Expected a MissingChunkException.');
        } catch (MissingChunkException $e) {
            self::assertStringContainsString('missing parts: 2', $e->getMessage());
        }

        // Omitting a part from the manifest would produce a plausible-looking
        // file that is quietly missing a third of its data.
        self::assertSame([], $client->argsFor('completeMultipartUpload'));
    }

    #[Test]
    public function test_completion_requires_a_multipart_upload_to_be_in_progress(): void
    {
        $client = new FakeS3Client();
        $state = new UploadState('track', 1, 4, 'x.bin', [0], false);

        $this->expectException(AssemblyException::class);
        $this->expectExceptionMessageMatches('/No S3 multipart upload is in progress/');

        (new S3MultipartAssembler($client, 'my-bucket'))->assemble($state, $this->storageStub());
    }

    #[Test]
    public function test_an_sdk_failure_during_completion_is_wrapped(): void
    {
        $client = new FakeS3Client();
        $client->on('completeMultipartUpload', static fn (): array => throw new \Aws\Exception\AwsException(
            'InvalidPart',
            new \Aws\Command('CompleteMultipartUpload', []),
            ['code' => 'InvalidPart'],
        ));

        $state = (new UploadState('track', 1, 4, 'x.bin', [0], false))
            ->withMultipartUploadId('upload-abc')
            ->withPartEtag(1, '"e1"');

        $this->expectException(AssemblyException::class);
        $this->expectExceptionMessageMatches('/Failed to complete S3 multipart upload/');

        (new S3MultipartAssembler($client, 'my-bucket'))->assemble($state, $this->storageStub());
    }

    #[Test]
    public function test_the_assembler_never_reads_chunks_back_from_storage(): void
    {
        $client = new FakeS3Client();
        $storage = $this->storageStub();

        $state = (new UploadState('track', 2, 8, 'x.bin', [0, 1], false))
            ->withMultipartUploadId('upload-abc')
            ->withPartEtag(1, '"e1"')
            ->withPartEtag(2, '"e2"');

        (new S3MultipartAssembler($client, 'my-bucket'))->assemble($state, $storage);

        // Reassembly happens inside S3, so no byte should flow back through the
        // application. A read here would silently reintroduce full client-side
        // assembly and the memory profile that motivated this design.
        self::assertSame(0, $storage->reads);
    }

    private function storageStub(): ReadCountingStorage
    {
        return new ReadCountingStorage();
    }
}

/**
 * Storage double that counts reads, proving the assembler never pulls chunk
 * bytes back out of storage.
 */
final class ReadCountingStorage implements ChunkStorageInterface
{
    public int $reads = 0;

    public function store(Chunk $chunk): void
    {
    }

    public function getChunkStream(Chunk $chunk): mixed
    {
        $this->reads++;

        return null;
    }

    public function deleteChunks(string $identifier): void
    {
    }

    public function deleteChunk(Chunk $chunk): void
    {
    }

    public function cleanOrphanedChunks(int $ttlSeconds): int
    {
        return 0;
    }

    public function hasChunks(string $identifier): bool
    {
        return true;
    }
}
