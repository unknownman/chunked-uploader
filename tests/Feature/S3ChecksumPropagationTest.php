<?php

declare(strict_types=1);

// File: tests/Feature/S3ChecksumPropagationTest.php

namespace Resumable\ChunkedUploader\Tests\Feature;

use Aws\Command;
use Aws\Exception\AwsException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\ChunkUploader;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Drivers\Storage\S3ChunkStorage;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\NullEventDispatcher;
use Resumable\ChunkedUploader\Tests\TestCase;
use Resumable\ChunkedUploader\Tests\Unit\FakeS3Client;

/**
 * Proves a digest rejection reaches the caller as {@see InvalidChunkException}.
 *
 * {@see S3ChunkStorage} already classifies the failure; this guards the second
 * half of the path, where `ChunkUploader` wraps every storage failure. Flattening
 * a digest mismatch into `UploadFailedException` would tell a client to retry
 * bytes that are deterministically bad, so the distinction has to survive.
 */
final class S3ChecksumPropagationTest extends TestCase
{
    #[Test]
    public function test_a_digest_mismatch_surfaces_as_a_typed_domain_exception(): void
    {
        $metadata = new InMemoryMetadataRepository();
        $metadata->save((new UploadState('track', 1, 4, 'x.bin'))->withMultipartUploadId('upload-abc'));

        $uploader = $this->uploader($this->storageRejectingWith('BadDigest', 'The Content-MD5 you specified did not match.', $metadata), $metadata);
        $body = 'DATA';

        try {
            $uploader->processChunk($this->chunkWithChecksum($this->temporaryFile($body), hash('sha256', $body)));
            self::fail('Expected the digest mismatch to surface.');
        } catch (InvalidChunkException $e) {
            self::assertStringContainsString('do not match the client-supplied digest', $e->getMessage());
        }
    }

    #[Test]
    public function test_a_rejected_chunk_is_not_recorded_as_uploaded(): void
    {
        $metadata = new InMemoryMetadataRepository();
        $metadata->save((new UploadState('track', 1, 4, 'x.bin'))->withMultipartUploadId('upload-abc'));

        $uploader = $this->uploader($this->storageRejectingWith('BadDigest', 'mismatch', $metadata), $metadata);

        try {
            $uploader->processChunk($this->chunkWithChecksum($this->temporaryFile('DATA'), hash('sha256', 'DATA')));
        } catch (InvalidChunkException) {
        }

        // S3 refused the part, so progress must not claim otherwise -- otherwise a
        // resume would skip a chunk that was never durably stored.
        $state = $metadata->get('track');
        self::assertNotNull($state);
        self::assertFalse($state->isCompleted);
        self::assertSame([], $state->uploadedChunks);
    }

    #[Test]
    public function test_an_infrastructure_failure_still_surfaces_as_a_storage_exception(): void
    {
        $metadata = new InMemoryMetadataRepository();
        $metadata->save((new UploadState('track', 1, 4, 'x.bin'))->withMultipartUploadId('upload-abc'));

        $uploader = $this->uploader($this->storageRejectingWith('InternalError', 'boom', $metadata), $metadata);

        $this->expectException(UploadFailedException::class);
        $this->expectExceptionMessage('Failed to store chunk');

        $uploader->processChunk($this->chunkWithChecksum($this->temporaryFile('DATA'), hash('sha256', 'DATA')));
    }

    #[Test]
    public function test_a_successful_verified_upload_records_progress(): void
    {
        $metadata = new InMemoryMetadataRepository();
        $metadata->save((new UploadState('track', 1, 4, 'x.bin'))->withMultipartUploadId('upload-abc'));

        $client = new FakeS3Client();
        $client->on('uploadPart', static fn (): array => ['ETag' => '"etag-1"']);
        $client->on('abortMultipartUpload', static fn (): array => []);
        $client->paginate('ListObjectsV2', static fn (): array => ['Contents' => []]);

        $body = 'DATA';
        $uploader = $this->uploader($this->storage($client, $metadata), $metadata);
        $state = $uploader->processChunk($this->chunkWithChecksum($this->temporaryFile($body), hash('sha256', $body)));

        $args = $client->argsFor('uploadPart')[0];
        self::assertSame(base64_encode(hash('sha256', $body, true)), $args['ChecksumSHA256']);
        self::assertSame('SHA256', $args['ChecksumAlgorithm']);
        // Verified end to end: the digest reached S3 and the chunk was accepted.
        self::assertTrue($state->isCompleted);
        self::assertSame('/uploads/final/x.bin', $state->finalPath);
        // Keyed by the 1-based part number S3 needs, holding the verbatim header
        // value (quotes included) that CompleteMultipartUpload expects back.
        self::assertSame([1 => '"etag-1"'], $state->partEtags);
    }

    private function storageRejectingWith(
        string $code,
        string $message,
        InMemoryMetadataRepository $metadata,
    ): S3ChunkStorage {
        $client = new FakeS3Client();
        $client->on('uploadPart', static function () use ($code, $message): array {
            throw new AwsException(
                $code . ': ' . $message,
                new Command('UploadPart'),
                ['code' => $code, 'message' => $message, 'response' => new Response(400, [], '')],
            );
        });

        return $this->storage($client, $metadata);
    }

    /**
     * The storage must share the metadata repository, since that is where the
     * multipart handle lives between requests.
     */
    private function storage(FakeS3Client $client, InMemoryMetadataRepository $metadata): S3ChunkStorage
    {
        return new S3ChunkStorage(
            client: $client,
            bucket: 'uploads',
            basePrefix: 'chunks/',
            metadata: $metadata,
        );
    }

    private function uploader(ChunkStorageInterface $storage, InMemoryMetadataRepository $metadata): ChunkUploader
    {
        $validator = $this->createMock(ChunkValidatorInterface::class);
        $validator->method('validate')->willReturn(true);
        $validator->method('validateWithConfig')->willReturn(true);

        $progress = $this->createMock(ProgressTrackerInterface::class);
        $progress->method('isComplete')->willReturnCallback(
            static fn (UploadState $s): bool => $s->isComplete(),
        );

        return new ChunkUploader(
            storage: $storage,
            metadata: $metadata,
            progress: $progress,
            assembler: $this->assembler(),
            validator: $validator,
            dispatcher: new NullEventDispatcher(),
        );
    }

    private function assembler(): FileAssemblerInterface
    {
        $assembler = $this->createMock(FileAssemblerInterface::class);
        $assembler->method('assemble')->willReturn('/uploads/final/x.bin');

        return $assembler;
    }

    private function chunkWithChecksum(string $path, string $checksum): Chunk
    {
        return new Chunk(
            identifier: 'track',
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: (int) filesize($path),
            totalSize: (int) filesize($path),
            tmpFilePath: $path,
            originalFilename: 'x.bin',
            checksum: $checksum,
        );
    }
}
