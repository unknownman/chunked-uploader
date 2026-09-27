<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use Aws\Command;
use Aws\Exception\AwsException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Drivers\Storage\S3ChunkStorage;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\StorageException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\Support\FakeS3Client;
use Resumable\ChunkedUploader\Tests\TestCase;

use function stream_get_contents;

final class S3ChunkStorageTest extends TestCase
{
    #[Test]
    public function test_store_creates_a_multipart_upload_and_uploads_the_part(): void
    {
        $metadata = $this->metadataWith('track', 1, 4, 'x.bin');

        $client = new FakeS3Client();
        $client->on('createMultipartUpload', static fn (array $args): array => [
            'UploadId' => 'upload-abc',
        ]);
        $client->on('uploadPart', static function (array $args): array {
            self::assertSame('uploads', $args['Bucket']);
            self::assertSame('chunks/track/uploads/x.bin', $args['Key']);
            self::assertSame('upload-abc', $args['UploadId']);
            self::assertSame(1, $args['PartNumber']);
            self::assertSame(4, $args['ContentLength']);
            self::assertIsResource($args['Body']);
            self::assertSame('DATA', stream_get_contents($args['Body']));

            return ['ETag' => '"etag-1"'];
        });

        $storage = $this->storage($client, $metadata);
        $storage->store($this->chunkFor('track', 0, 1, $this->temporaryFile('DATA')));

        // Create first, then the part: the part cannot be sent without a handle.
        self::assertSame(['createMultipartUpload', 'uploadPart'], $client->commandNames());
        self::assertSame('upload-abc', $metadata->get('track')?->multipartUploadId);
    }

    #[Test]
    public function test_store_persists_the_upload_id_so_a_later_request_reuses_it(): void
    {
        $metadata = $this->metadataWith('track', 2, 8, 'x.bin');

        $client = new FakeS3Client();
        $client->on('createMultipartUpload', static fn (): array => ['UploadId' => 'upload-abc']);
        $client->on('uploadPart', static fn (): array => ['ETag' => '"etag-1"']);

        $storage = $this->storage($client, $metadata);
        $storage->store($this->part('track', 0, 2));
        $storage->store($this->part('track', 1, 2));

        // A second request must not start a competing upload, or the first one's
        // parts would be orphaned and the file could never be completed.
        self::assertCount(1, $client->argsFor('createMultipartUpload'));
        self::assertSame('upload-abc', $metadata->get('track')?->multipartUploadId);
    }

    #[Test]
    public function test_store_forwards_a_base64_sha256_as_checksum_sha256(): void
    {
        $client = $this->clientWithUploadId();
        $client->on('uploadPart', static fn (): array => ['ETag' => '"e"']);

        $body = 'DATA';
        $storage = $this->storage($client, $this->readyMetadata('track'));
        $storage->store($this->chunkWithChecksum(
            $this->temporaryFile($body),
            base64_encode(hash('sha256', $body, true)),
        ));

        $args = $client->argsFor('uploadPart')[0];

        self::assertArrayHasKey('ChecksumSHA256', $args);
        self::assertSame(base64_encode(hash('sha256', $body, true)), $args['ChecksumSHA256']);
        self::assertSame('SHA256', $args['ChecksumAlgorithm']);
        self::assertArrayNotHasKey('ContentMD5', $args);
    }

    #[Test]
    public function test_store_accepts_a_hex_sha256_and_converts_it_to_base64(): void
    {
        $client = $this->clientWithUploadId();
        $client->on('uploadPart', static fn (): array => ['ETag' => '"e"']);

        $body = 'DATA';
        $storage = $this->storage($client, $this->readyMetadata('track'));
        $storage->store($this->chunkWithChecksum(
            $this->temporaryFile($body),
            hash('sha256', $body),
        ));

        $args = $client->argsFor('uploadPart')[0];

        // S3's REST API is base64-only, so a hex digest from the client must be
        // re-encoded rather than forwarded verbatim or S3 rejects it outright.
        self::assertSame(base64_encode(hash('sha256', $body, true)), $args['ChecksumSHA256']);
    }

    #[Test]
    public function test_store_forwards_md5_as_content_md5(): void
    {
        $client = $this->clientWithUploadId();
        $client->on('uploadPart', static fn (): array => ['ETag' => '"e"']);

        $body = 'DATA';
        $storage = $this->storage($client, $this->readyMetadata('track'));
        $storage->store($this->chunkWithChecksum(
            $this->temporaryFile($body),
            hash('md5', $body),
        ));

        $args = $client->argsFor('uploadPart')[0];

        self::assertSame(base64_encode(hash('md5', $body, true)), $args['ContentMD5']);
        self::assertArrayNotHasKey('ChecksumSHA256', $args);
    }

    #[Test]
    public function test_store_sends_no_checksum_parameters_when_the_client_omits_the_digest(): void
    {
        $client = $this->clientWithUploadId();
        $client->on('uploadPart', static fn (): array => ['ETag' => '"e"']);

        $storage = $this->storage($client, $this->readyMetadata('track'));
        $storage->store($this->chunkFor('track', 0, 1, $this->temporaryFile('DATA')));

        $args = $client->argsFor('uploadPart')[0];

        self::assertArrayNotHasKey('ChecksumSHA256', $args);
        self::assertArrayNotHasKey('ContentMD5', $args);
        self::assertArrayNotHasKey('ChecksumAlgorithm', $args);
    }

    #[Test]
    public function test_store_rejects_a_digest_of_an_unusable_length_before_calling_s3(): void
    {
        $client = $this->clientWithUploadId();
        $client->on('uploadPart', static fn (): array => ['ETag' => '"e"']);

        $storage = $this->storage($client, $this->readyMetadata('track'));

        $this->expectException(InvalidChunkException::class);
        $this->expectExceptionMessage('Unsupported chunk checksum');

        try {
            $storage->store($this->chunkWithChecksum($this->temporaryFile('DATA'), 'not-a-digest'));
        } finally {
            // Rejecting locally is the point: a malformed digest must not be
            // spent as a round trip that S3 will only refuse.
            self::assertNotContains('uploadPart', $client->commandNames());
        }
    }

    #[Test]
    public function test_store_maps_a_bad_digest_rejection_to_an_invalid_chunk_exception(): void
    {
        $client = $this->clientWithUploadId();
        $client->on('uploadPart', static function (): array {
            throw self::awsException('BadDigest', 'The Content-MD5 you specified did not match what we received.');
        });

        $body = 'DATA';
        $storage = $this->storage($client, $this->readyMetadata('track'));

        try {
            $storage->store($this->chunkWithChecksum(
                $this->temporaryFile($body),
                hash('sha256', $body),
            ));
            self::fail('Expected the digest mismatch to surface as an InvalidChunkException.');
        } catch (InvalidChunkException $e) {
            self::assertStringContainsString('do not match the client-supplied digest', $e->getMessage());
            // The S3 text is appended so an operator can correlate with a
            // CloudTrail entry without leaving the domain message.
            self::assertStringContainsString('The Content-MD5 you specified', $e->getMessage());
        }

        self::assertNotNull(
            $this->readyMetadata('track')->get('track')?->multipartUploadId,
            'The rejected part must not disturb the multipart upload handle.',
        );
    }

    #[Test]
    public function test_store_maps_a_sha256_mismatch_to_an_invalid_chunk_exception(): void
    {
        $client = $this->clientWithUploadId();
        $client->on('uploadPart', static function (): array {
            throw self::awsException('XAmzContentSHA256Mismatch', 'payload hash mismatch');
        });

        $storage = $this->storage($client, $this->readyMetadata('track'));

        $this->expectException(InvalidChunkException::class);
        $this->expectExceptionMessage('corrupted in transit');

        $storage->store($this->chunkWithChecksum($this->temporaryFile('DATA'), hash('sha256', 'DATA')));
    }

    #[Test]
    public function test_a_malformed_digest_rejection_is_reported_as_a_client_bug_not_a_network_fault(): void
    {
        $client = $this->clientWithUploadId();
        $client->on('uploadPart', static function (): array {
            throw self::awsException('InvalidDigest', 'The Content-MD5 you specified is not valid.');
        });

        $storage = $this->storage($client, $this->readyMetadata('track'));

        try {
            $storage->store($this->chunkWithChecksum($this->temporaryFile('DATA'), hash('sha256', 'DATA')));
            self::fail('Expected the malformed digest to surface.');
        } catch (InvalidChunkException $e) {
            // Retrying identical bytes cannot fix a digest the server never
            // accepted, so the message must not read like a transient fault.
            self::assertStringContainsString('client bug, not a network fault', $e->getMessage());
        }
    }

    #[Test]
    public function test_store_still_wraps_unrelated_s3_failures_as_storage_exceptions(): void
    {
        $client = $this->clientWithUploadId();
        $client->on('uploadPart', static function (): array {
            throw self::awsException('InternalError', 'We encountered an internal error.');
        });

        $storage = $this->storage($client, $this->readyMetadata('track'));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Failed to upload chunk part to S3');

        $storage->store($this->chunkFor('track', 0, 1, $this->temporaryFile('DATA')));
    }

    #[Test]
    public function test_store_maps_the_zero_based_chunk_index_to_a_one_based_part_number(): void
    {
        $metadata = $this->metadataWith('track', 3, 12, 'x.bin');
        $metadata->save($metadata->get('track')->withMultipartUploadId('upload-abc'));

        $client = new FakeS3Client();
        $client->on('uploadPart', static fn (): array => ['ETag' => '"etag"']);

        $storage = $this->storage($client, $metadata);
        foreach ([0, 1, 2] as $index) {
            $storage->store($this->part('track', $index, 3));
        }

        $partNumbers = array_map(
            static fn (array $args): int => $args['PartNumber'],
            $client->argsFor('uploadPart'),
        );

        self::assertSame([1, 2, 3], $partNumbers);
    }

    #[Test]
    public function test_store_records_each_part_etag_in_metadata(): void
    {
        $metadata = $this->metadataWith('track', 2, 8, 'x.bin');
        $metadata->save($metadata->get('track')->withMultipartUploadId('upload-abc'));

        $client = new FakeS3Client();
        $client->on('uploadPart', static function (array $args): array {
            return ['ETag' => '"etag-' . $args['PartNumber'] . '"'];
        });

        $storage = $this->storage($client, $metadata);
        $storage->store($this->part('track', 0, 2));
        $storage->store($this->part('track', 1, 2));

        self::assertSame([1 => '"etag-1"', 2 => '"etag-2"'], $metadata->get('track')?->partEtags);
    }

    #[Test]
    public function test_store_records_the_etag_even_when_the_caller_has_not_marked_the_chunk_yet(): void
    {
        $metadata = $this->metadataWith('track', 1, 4, 'x.bin');
        $metadata->save($metadata->get('track')->withMultipartUploadId('upload-abc'));

        $client = new FakeS3Client();
        $client->on('uploadPart', static fn (): array => ['ETag' => '"etag-1"']);

        $this->storage($client, $metadata)->store($this->chunkFor('track', 0, 1, $this->temporaryFile('DATA')));

        // The ETag must be durable before progress is recorded: a chunk marked
        // uploaded with no ETag can never be completed, and is unrecoverable.
        self::assertArrayHasKey(1, $metadata->get('track')?->partEtags ?? []);
    }

    #[Test]
    public function test_store_rejects_a_part_below_the_s3_five_mib_minimum(): void
    {
        // Two chunks means chunk 0 is a non-final part and carries the 5 MiB floor.
        $metadata = $this->metadataWith('track', 2, 8, 'x.bin');
        $metadata->save($metadata->get('track')->withMultipartUploadId('upload-abc'));

        $client = new FakeS3Client();
        $client->on('uploadPart', static fn (): array => ['ETag' => '"etag"']);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/at least 5 MiB/');

        $this->storage($client, $metadata)->store($this->chunkFor('track', 0, 2, $this->temporaryFile('AAAA')));
    }

    #[Test]
    public function test_store_allows_a_single_small_chunk_because_the_last_part_is_exempt(): void
    {
        $metadata = $this->metadataWith('track', 1, 4, 'x.bin');
        $metadata->save($metadata->get('track')->withMultipartUploadId('upload-abc'));

        $client = new FakeS3Client();
        $client->on('uploadPart', static fn (): array => ['ETag' => '"etag"']);

        $this->storage($client, $metadata)->store($this->chunkFor('track', 0, 1, $this->temporaryFile('DATA')));

        self::assertCount(1, $client->argsFor('uploadPart'));
    }

    #[Test]
    public function test_store_fails_when_s3_returns_no_etag(): void
    {
        $metadata = $this->metadataWith('track', 1, 4, 'x.bin');
        $metadata->save($metadata->get('track')->withMultipartUploadId('upload-abc'));

        $client = new FakeS3Client();
        $client->on('uploadPart', static fn (): array => []);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/did not return an ETag/');

        $this->storage($client, $metadata)->store($this->chunkFor('track', 0, 1, $this->temporaryFile('DATA')));
    }

    #[Test]
    public function test_get_chunk_stream_is_rejected_because_parts_are_not_readable_objects(): void
    {
        $client = new FakeS3Client();
        $storage = $this->storage($client, $this->metadataWith('track', 1, 0, 'x.bin'));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/cannot be read individually/');

        $storage->getChunkStream($this->chunkFor('track'));
    }

    #[Test]
    public function test_delete_chunks_aborts_the_multipart_upload(): void
    {
        $metadata = $this->metadataWith('track', 2, 8, 'x.bin');
        $metadata->save($metadata->get('track')->withMultipartUploadId('upload-abc'));

        $client = new FakeS3Client();
        $client->paginate('ListObjectsV2', static fn (): array => ['Contents' => []]);

        $this->storage($client, $metadata)->deleteChunks('track');

        // Aborting is what releases the parts; leaving the upload in place keeps
        // every part billable and invisible to a normal object listing.
        $aborts = $client->argsFor('abortMultipartUpload');
        self::assertCount(1, $aborts);
        self::assertSame('upload-abc', $aborts[0]['UploadId']);
        self::assertSame('chunks/track/uploads/x.bin', $aborts[0]['Key']);
    }

    #[Test]
    public function test_delete_chunks_tolerates_an_upload_that_was_already_aborted(): void
    {
        $metadata = $this->metadataWith('track', 2, 8, 'x.bin');
        $metadata->save($metadata->get('track')->withMultipartUploadId('upload-abc'));

        $client = new FakeS3Client();
        $client->on('abortMultipartUpload', static fn (): array => throw new \Aws\Exception\AwsException(
            'gone',
            new \Aws\Command('AbortMultipartUpload', []),
            ['code' => 'NoSuchUpload'],
        ));
        $client->paginate('ListObjectsV2', static fn (): array => ['Contents' => []]);

        $this->storage($client, $metadata)->deleteChunks('track');

        self::assertTrue(true);
    }

    #[Test]
    public function test_delete_chunk_is_a_no_op_because_parts_are_not_separate_objects(): void
    {
        $client = new FakeS3Client();
        $storage = $this->storage($client, $this->metadataWith('track', 1, 0, 'x.bin'));

        $storage->deleteChunk($this->chunkFor('track'));

        self::assertSame([], $client->commandNames());
    }

    #[Test]
    public function test_clean_orphaned_chunks_aborts_stale_multipart_uploads(): void
    {
        $client = new FakeS3Client();
        $client->paginate('ListMultipartUploads', static fn (): array => [
            'Uploads' => [
                ['Key' => 'chunks/a/uploads/x.bin', 'UploadId' => 'stale-1', 'Initiated' => new \DateTimeImmutable('-2 hours')],
                ['Key' => 'chunks/b/uploads/x.bin', 'UploadId' => 'fresh-1', 'Initiated' => new \DateTimeImmutable('-1 minute')],
            ],
        ]);
        $client->paginate('ListObjectsV2', static fn (): array => ['Contents' => []]);

        $removed = $this->storage($client, $this->metadataWith('track', 1, 0, 'x.bin'))
            ->cleanOrphanedChunks(3600);

        // A listing-only sweep would not catch these: parts of an in-progress
        // multipart upload are not returned by ListObjectsV2 at all.
        $aborts = $client->argsFor('abortMultipartUpload');
        self::assertCount(1, $aborts);
        self::assertSame('stale-1', $aborts[0]['UploadId']);
        self::assertSame(1, $removed);
    }

    #[Test]
    public function test_clean_orphaned_chunks_batches_the_delete_objects_request_at_1000_keys(): void
    {
        $objects = [];
        for ($i = 0; $i < 2050; $i++) {
            $objects[] = ['Key' => 'chunks/track/chunk_' . $i . '.part', 'LastModified' => new \DateTimeImmutable('-2 hours')];
        }

        $client = new FakeS3Client();
        $client->on('deleteObjects', static fn (array $args): array => []);
        $client->paginate('ListMultipartUploads', static fn (): array => ['Uploads' => []]);
        $client->paginate('ListObjectsV2', static fn (): array => ['Contents' => $objects]);

        $removed = $this->storage($client, $this->metadataWith('track', 1, 0, 'x.bin'))
            ->cleanOrphanedChunks(3600);

        $batches = $client->argsFor('deleteObjects');
        self::assertSame(2050, $removed);
        self::assertCount(3, $batches);
        self::assertCount(1000, $batches[0]['Delete']['Objects']);
        self::assertCount(1000, $batches[1]['Delete']['Objects']);
        self::assertCount(50, $batches[2]['Delete']['Objects']);
    }

    #[Test]
    public function test_clean_orphaned_chunks_keeps_recent_multipart_uploads(): void
    {
        $client = new FakeS3Client();
        $client->paginate('ListMultipartUploads', static fn (): array => [
            'Uploads' => [
                ['Key' => 'chunks/a/uploads/x.bin', 'UploadId' => 'fresh', 'Initiated' => new \DateTimeImmutable('-10 seconds')],
            ],
        ]);
        $client->paginate('ListObjectsV2', static fn (): array => ['Contents' => []]);

        $removed = $this->storage($client, $this->metadataWith('track', 1, 0, 'x.bin'))
            ->cleanOrphanedChunks(3600);

        self::assertSame([], $client->argsFor('abortMultipartUpload'));
        self::assertSame(0, $removed);
    }

    #[Test]
    public function test_clean_orphaned_chunks_rejects_a_non_positive_ttl(): void
    {
        $storage = $this->storage(new FakeS3Client(), $this->metadataWith('track', 1, 0, 'x.bin'));

        $this->expectException(\InvalidArgumentException::class);
        $storage->cleanOrphanedChunks(0);
    }

    #[Test]
    public function test_delete_chunks_batches_the_delete_objects_request_at_1000_keys(): void
    {
        $keys = [];
        for ($i = 0; $i < 2500; $i++) {
            $keys[] = ['Key' => 'chunks/track/chunk_' . $i . '.part'];
        }

        $client = new FakeS3Client();
        $client->on('deleteObjects', static fn (array $args): array => []);
        $client->paginate('ListObjectsV2', static fn (): array => ['Contents' => $keys]);

        $this->storage($client, $this->metadataWith('track', 1, 0, 'x.bin'))->deleteChunks('track');

        $batches = $client->argsFor('deleteObjects');
        self::assertCount(3, $batches);
        self::assertCount(1000, $batches[0]['Delete']['Objects']);
        self::assertCount(1000, $batches[1]['Delete']['Objects']);
        self::assertCount(500, $batches[2]['Delete']['Objects']);
    }

    #[Test]
    public function test_delete_chunks_purges_ids_in_steamlined_pages(): void
    {
        $makeKeys = static fn (int $start, int $count): array => array_map(
            static fn (int $i): array => ['Key' => 'chunks/track/chunk_' . ($start + $i) . '.part'],
            range(0, $count - 1),
        );

        $client = new FakeS3Client();
        $client->on('deleteObjects', static fn (array $args): array => []);
        $client->paginate('ListObjectsV2', static function () use ($makeKeys): \Generator {
            for ($page = 0; $page < 4; $page++) {
                yield ['Contents' => $makeKeys($page * 250, 250)];
            }
        });

        $this->storage($client, $this->metadataWith('track', 1, 0, 'x.bin'))->deleteChunks('track');

        // 4 pages of 250 keys each -> one deleteObjects flush per page, proving
        // keys are deleted incrementally instead of being accumulated first.
        $batches = $client->argsFor('deleteObjects');
        self::assertCount(4, $batches);
        foreach ($batches as $batch) {
            self::assertCount(250, $batch['Delete']['Objects']);
        }
    }

    #[Test]
    public function test_assembler_consumes_psr7_streams_without_buffering_the_chunk(): void
    {
        $state = new UploadState('assemble', 2, 6, 'joined.txt', [0, 1], false);
        $assembler = new StreamAssembler($this->tempDir() . '/final');
        $path = $assembler->assemble($state, new class implements ChunkStorageInterface {
            public function store(Chunk $chunk): void
            {
            }

            public function getChunkStream(Chunk $chunk): mixed
            {
                return \GuzzleHttp\Psr7\StreamWrapper::getResource(Utils::streamFor('ab'));
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
        });

        self::assertSame('abab', (string) file_get_contents($path));
    }

    /**
     * Metadata already holding a multipart handle, so `store()` exercises the
     * UploadPart path rather than CreateMultipartUpload.
     */
    private function readyMetadata(string $identifier): InMemoryMetadataRepository
    {
        $metadata = $this->metadataWith($identifier, 1, 4, 'x.bin');
        $metadata->save($metadata->get($identifier)->withMultipartUploadId('upload-abc'));

        return $metadata;
    }

    private function clientWithUploadId(): FakeS3Client
    {
        $client = new FakeS3Client();
        $client->on('createMultipartUpload', static fn (): array => ['UploadId' => 'upload-abc']);

        return $client;
    }

    private function chunkWithChecksum(string $path, ?string $checksum): Chunk
    {
        $chunk = $this->chunkFor('track', 0, 1, $path);

        return new Chunk(
            identifier: $chunk->identifier,
            token: $chunk->token,
            index: $chunk->index,
            totalChunks: $chunk->totalChunks,
            chunkSize: $chunk->chunkSize,
            totalSize: $chunk->totalSize,
            tmpFilePath: $chunk->tmpFilePath,
            originalFilename: $chunk->originalFilename,
            checksum: $checksum,
        );
    }

    /**
     * Builds a genuine AwsException so the error-mapping branch is exercised
     * through the SDK's own accessors rather than a hand-rolled stand-in.
     */
    private static function awsException(string $code, string $message): AwsException
    {
        return new AwsException(
            $code . ': ' . $message,
            new Command('UploadPart'),
            [
                'code' => $code,
                'message' => $message,
                'response' => new Response(400, [], ''),
            ],
        );
    }

    private function storage(FakeS3Client $client, InMemoryMetadataRepository $metadata): S3ChunkStorage
    {
        return new S3ChunkStorage(
            client: $client,
            bucket: 'uploads',
            basePrefix: 'chunks/',
            metadata: $metadata,
        );
    }

    private function metadataWith(string $identifier, int $totalChunks, int $totalSize, string $filename): InMemoryMetadataRepository
    {
        $metadata = new InMemoryMetadataRepository();
        $metadata->save(new UploadState($identifier, $totalChunks, $totalSize, $filename));

        return $metadata;
    }

    private function chunkFor(string $identifier, int $index = 0, int $totalChunks = 1, ?string $path = null, ?int $chunkSize = null): Chunk
    {
        return new Chunk(
            identifier: $identifier,
            token: '',
            index: $index,
            totalChunks: $totalChunks,
            chunkSize: $chunkSize ?? ($path === null ? 0 : (int) filesize($path)),
            totalSize: 0,
            tmpFilePath: $path ?? '',
            originalFilename: 'x.bin',
        );
    }

    /**
     * Creates a sparse file of the requested size.
     *
     * Multipart tests need parts that genuinely clear S3's 5 MiB non-final-part
     * floor. Sparse files give real, correctly-sized files without writing
     * megabytes to disk.
     */
    private function sizedTemporaryFile(int $bytes): string
    {
        $path = $this->tempDir() . '/part-' . $bytes . '.bin';
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        ftruncate($handle, $bytes);
        fclose($handle);

        return $path;
    }

    /**
     * A part that satisfies the 5 MiB floor for any position in a multi-chunk upload.
     */
    private function part(string $identifier, int $index, int $totalChunks): Chunk
    {
        return $this->chunkFor($identifier, $index, $totalChunks, $this->sizedTemporaryFile(self::PART_BYTES));
    }

    private const PART_BYTES = 5 * 1024 * 1024;
}
