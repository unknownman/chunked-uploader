<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Drivers\Storage\S3ChunkStorage;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Exceptions\StorageException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\TestCase;

use function stream_get_contents;

final class S3ChunkStorageTest extends TestCase
{
    #[Test]
    public function test_store_streams_the_chunk_via_a_native_resource_without_buffering(): void
    {
        $client = new FakeS3Client();
        $client->on('putObject', static function (array $args): array {
            self::assertSame('uploads', $args['Bucket']);
            self::assertSame('chunks/track/chunk_0.part', $args['Key']);
            self::assertSame(4, $args['ContentLength']);
            self::assertIsResource($args['Body']);

            $contents = stream_get_contents($args['Body']);
            self::assertSame('DATA', $contents);

            return [];
        });

        $storage = new S3ChunkStorage($client, 'uploads');
        $storage->store($this->chunkFor('track', $this->temporaryFile('DATA')));

        self::assertSame('putObject', $client->calls[0][0]);
    }

    #[Test]
    public function test_get_chunk_stream_returns_a_psr7_stream_that_reads_from_the_head(): void
    {
        $client = new FakeS3Client();
        $client->on('getObject', static fn (array $args): array => [
            'Body' => Utils::streamFor('part-bytes'),
        ]);

        $storage = new S3ChunkStorage($client, 'uploads');
        $stream = $storage->getChunkStream($this->chunkFor('track'));

        self::assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $stream);
        self::assertSame('part-bytes', $stream->getContents());
    }

    #[Test]
    public function test_get_chunk_stream_maps_missing_keys_to_chunk_not_found(): void
    {
        $client = new FakeS3Client();
        $client->on('getObject', static fn (): array => throw new \Aws\Exception\AwsException(
            'Object not found',
            new \Aws\Command('GetObject', []),
            ['code' => 'NoSuchKey'],
        ));

        $storage = new S3ChunkStorage($client, 'uploads');

        $this->expectException(ChunkNotFoundException::class);
        $storage->getChunkStream($this->chunkFor('track'));
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

    #[Test]
    public function test_get_chunk_stream_rejects_a_non_stream_body(): void
    {
        $client = new FakeS3Client();
        $client->on('getObject', static fn (array $args): array => ['Body' => 'plain string']);

        $storage = new S3ChunkStorage($client, 'uploads');

        $this->expectException(StorageException::class);
        $storage->getChunkStream($this->chunkFor('track'));
    }

    private function chunkFor(string $identifier, ?string $path = null): Chunk
    {
        return new Chunk(
            identifier: $identifier,
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: $path === null ? 0 : filesize($path),
            totalSize: $path === null ? 0 : filesize($path),
            tmpFilePath: $path ?? '',
            originalFilename: 'x.bin',
        );
    }
}

/**
 * Minimal AWS SDK client double that captures command calls and dispatches them
 * to per-command handlers. AWS SDK commands such as putObject/getObject are
 * routed through Client::__call(), so they cannot be stubbed with PHPUnit mocks.
 */
final class FakeS3Client extends S3Client
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    /** @var array<string, callable> */
    private array $handlers = [];

    public function __construct()
    {
    }

    public function on(string $command, callable $handler): void
    {
        $this->handlers[$command] = $handler;
    }

    public function __call($name, $arguments)
    {
        $this->calls[] = [$name, $arguments];

        $handler = $this->handlers[$name] ?? null;
        if ($handler !== null) {
            return $handler(...$arguments);
        }

        return [];
    }
}
