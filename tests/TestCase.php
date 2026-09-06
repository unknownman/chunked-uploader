<?php

declare(strict_types=1);

// File: tests/TestCase.php

namespace Resumable\ChunkedUploader\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\RedisConnectionInterface;
use Resumable\ChunkedUploader\Core\Drivers\Security\UploadTokenManager;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Core\UploadManager;

/**
 * Base test case for the chunked uploader suite.
 *
 * Configures a self-cleaning temporary filesystem directory per test, exposes
 * in-memory production doubles for storage and metadata, and provides helpers
 * for spinning up a configured UploadManager and constructing Chunk fixtures.
 * No real Redis server, S3 bucket, or ClamAV daemon is ever contacted.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = tempnam(sys_get_temp_dir(), 'chunked-uploader-test-');
        if ($directory === false || !unlink($directory) || !mkdir($directory, 0775, true)) {
            self::fail('Unable to create temporary test directory.');
        }
        $this->temporaryDirectory = $directory;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    /**
     * Returns a temporary file path populated with the given contents.
     *
     * The file lives inside the per-test sandbox directory and is removed
     * automatically during tearDown, keeping the host filesystem clean.
     */
    protected function temporaryFile(string $contents): string
    {
        $path = tempnam($this->temporaryDirectory, 'chunk-');
        self::assertNotFalse($path);
        self::assertNotFalse(file_put_contents($path, $contents));
        return $path;
    }

    /**
     * Returns a conveniently large deterministic payload for memory tests.
     */
    protected function payload(int $megabytes, string $byte = 'A'): string
    {
        return str_repeat($byte, $megabytes * 1024 * 1024);
    }

    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }

    /**
     * Builds a production UploadManager wired to in-memory doubles.
     */
    protected function createManager(
        InMemoryChunkStorage $storage,
        InMemoryMetadataRepository $metadata,
        string $secret = 'test-secret',
        string $finalDirectory = 'final',
    ): UploadManager {
        $assembler = new StreamAssembler($this->temporaryDirectory . DIRECTORY_SEPARATOR . $finalDirectory);
        return new UploadManager(
            storage: $storage,
            metadata: $metadata,
            progress: $metadata,
            assembler: $assembler,
            tokenManager: new UploadTokenManager($secret),
        );
    }

    /**
     * Returns a fully wired in-memory sandbox with an already-constructed
     * manager, storage, metadata, and a freshly issued token.
     *
     * @return array{manager: UploadManager, storage: InMemoryChunkStorage, metadata: InMemoryMetadataRepository, token: string}
     */
    protected function makeSandbox(
        string $identifier = 'upload_test',
        string $secret = 'test-secret',
    ): array {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $manager = $this->createManager($storage, $metadata, $secret);
        $token = (new UploadTokenManager($secret))->generateToken($identifier);

        return [
            'manager' => $manager,
            'storage' => $storage,
            'metadata' => $metadata,
            'token' => $token,
        ];
    }

    /**
     * Constructs a Chunk fixture pointing at a freshly written temp file.
     */
    protected function chunk(
        string $path,
        string $token,
        int $index,
        int $totalChunks,
        int $totalSize,
        string $identifier = 'upload_test',
        string $filename = 'payload.txt',
        ?string $checksum = null,
    ): Chunk {
        $size = filesize($path);
        self::assertIsInt($size);
        return new Chunk(
            identifier: $identifier,
            token: $token,
            index: $index,
            totalChunks: $totalChunks,
            chunkSize: $size,
            totalSize: $totalSize,
            tmpFilePath: $path,
            originalFilename: $filename,
            checksum: $checksum,
        );
    }

    /**
     * Creates a mock Redis connection implementing RedisConnectionInterface.
     *
     * @return MockObject&RedisConnectionInterface
     */
    protected function createRedisConnectionMock(): RedisConnectionInterface&MockObject
    {
        return $this->createMock(RedisConnectionInterface::class);
    }
}

/**
 * In-memory, test-only implementation of ChunkStorageInterface.
 *
 * Chunk payloads are physically held in the system temp directory so they can
 * be re-opened as real streams during assembly, exactly like a local chunk
 * driver, but every artifact is destroyed as soon as the double is garbage
 * collected, keeping the host filesystem unpolluted.
 */
class InMemoryChunkStorage implements ChunkStorageInterface
{
    /** @var array<string, array<int, string>> */
    private array $files = [];

    public function __destruct()
    {
        foreach ($this->files as $identifier => $_) {
            $this->deleteChunks($identifier);
        }
    }

    public function store(Chunk $chunk): void
    {
        if (isset($this->files[$chunk->identifier][$chunk->index])) {
            return;
        }
        $path = tempnam(sys_get_temp_dir(), 'stored-chunk-');
        if ($path === false || !copy($chunk->tmpFilePath, $path)) {
            throw new \RuntimeException('Unable to store test chunk.');
        }
        $this->files[$chunk->identifier][$chunk->index] = $path;
    }

    public function getChunkStream(Chunk $chunk): mixed
    {
        $path = $this->files[$chunk->identifier][$chunk->index] ?? null;
        if ($path === null) {
            throw new \Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException('Missing test chunk.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open test chunk.');
        }
        return $stream;
    }

    public function deleteChunks(string $identifier): void
    {
        foreach ($this->files[$identifier] ?? [] as $path) {
            @unlink($path);
        }
        unset($this->files[$identifier]);
    }

    public function hasChunks(string $identifier): bool
    {
        return isset($this->files[$identifier]) && $this->files[$identifier] !== [];
    }
}

/**
 * In-memory, test-only implementation of MetadataRepositoryInterface and
 * ProgressTrackerInterface backed by an associative array of UploadState.
 */
final class InMemoryMetadataRepository implements MetadataRepositoryInterface, ProgressTrackerInterface
{
    /** @var array<string, UploadState> */
    private array $states = [];

    public function save(UploadState $state): void
    {
        $this->states[$state->identifier] = $state;
    }

    public function get(string $identifier): ?UploadState
    {
        return $this->states[$identifier] ?? null;
    }

    public function delete(string $identifier): void
    {
        unset($this->states[$identifier]);
    }

    public function markChunkAsUploaded(string $identifier, int $chunkIndex): UploadState
    {
        $state = $this->states[$identifier] ?? throw new \RuntimeException('Missing test state.');
        $state = $state->withUploadedChunk($chunkIndex);
        $this->states[$identifier] = $state;
        return $state;
    }

    public function getPercentage(UploadState $state): float
    {
        return $state->totalChunks === 0 ? 0.0 : count($state->uploadedChunks) / $state->totalChunks * 100.0;
    }

    public function isComplete(UploadState $state): bool
    {
        return $state->isComplete();
    }

    public function getMissingChunkIndices(UploadState $state): array
    {
        return array_values(array_diff(range(0, $state->totalChunks - 1), $state->uploadedChunks));
    }
}
