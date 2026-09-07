<?php

declare(strict_types=1);

// File: tests/TestCase.php

namespace Resumable\ChunkedUploader\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\RateLimiterInterface;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Core\ChunkUploader;
use Resumable\ChunkedUploader\Core\Validation\ChunkSecurityValidator;
use Resumable\ChunkedUploader\Core\Validation\ValidationPipeline;

/**
 * Base test case for the chunked uploader suite.
 *
 * Configures a self-cleaning temporary filesystem directory per test, exposes
 * in-memory production doubles for storage and metadata, and provides helpers
 * for spinning up a configured ChunkUploader and constructing Chunk fixtures.
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
     * Returns the per-test sandbox directory.
     */
    protected function tempDir(): string
    {
        return $this->temporaryDirectory;
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
    * Builds a production ChunkUploader wired to in-memory doubles.
     *
     * The validator is a real {@see ChunkSecurityValidator} configured with the
     * given secret so token validation behaves exactly as in production, and the
     * dispatcher is a no-op recording dispatcher by default.
     */
    protected function createManager(
        InMemoryChunkStorage $storage,
        InMemoryMetadataRepository $metadata,
        string $secret = 'test-secret',
        string $finalDirectory = 'final',
        ?EventDispatcherInterface $dispatcher = null,
        ?RateLimiterInterface $rateLimiter = null,
        ?int $maxChunkAttempts = null,
        int $rateLimitWindow = 60,
        string $rateLimitKey = 'chunked-uploader:chunks',
    ): ChunkUploader {
        $assembler = new StreamAssembler($this->temporaryDirectory . DIRECTORY_SEPARATOR . $finalDirectory);
        return new ChunkUploader(
            storage: $storage,
            metadata: $metadata,
            progress: $metadata,
            assembler: $assembler,
            validator: $this->createValidator($secret),
            dispatcher: $dispatcher ?? new NullEventDispatcher(),
            rateLimiter: $rateLimiter,
            maxChunkAttempts: $maxChunkAttempts,
            rateLimitWindow: $rateLimitWindow,
            rateLimitKey: $rateLimitKey,
        );
    }

    /**
     * Returns a real composite chunk validator wired with an empty pipeline and
     * the supplied token secret, so token checks are enforced in tests.
     */
    protected function createValidator(string $secret = 'test-secret'): ChunkValidatorInterface
    {
        $sanitizer = new PathSanitizer();
        return new ChunkSecurityValidator(
            sanitizer: $sanitizer,
            pipeline: new ValidationPipeline([]),
            tokenService: new UploadTokenService($secret),
        );
    }

    /**
     * Returns a fully wired in-memory sandbox with an already-constructed
     * manager, storage, metadata, and a token factory for the upload.
     *
    * @return array{manager: ChunkUploader, storage: InMemoryChunkStorage, metadata: InMemoryMetadataRepository, tokenFactory: \Closure(int,int):string, dispatcher: NullEventDispatcher}
     */
    protected function makeSandbox(
        string $identifier = 'upload_test',
        string $secret = 'test-secret',
    ): array {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $dispatcher = new NullEventDispatcher();
        $manager = $this->createManager($storage, $metadata, $secret, 'final', $dispatcher);
        $tokenService = new UploadTokenService($secret);
        $tokenFactory = static fn (int $totalChunks, int $totalSize): string =>
            $tokenService->createToken($identifier, $totalChunks, $totalSize);

        return [
            'manager' => $manager,
            'storage' => $storage,
            'metadata' => $metadata,
            'tokenFactory' => $tokenFactory,
            'dispatcher' => $dispatcher,
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
     * Issues a token for the given upload signature using the test secret.
     */
    protected function issueToken(
        string $identifier,
        int $totalChunks,
        int $totalSize,
        string $salt = '',
        string $secret = 'test-secret',
    ): string {
        return (new UploadTokenService($secret))->createToken($identifier, $totalChunks, $totalSize, $salt);
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

    /** @var array<string, int> Last write timestamp per uploaded artifact */
    private array $timestamps = [];

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
        $this->timestamps[$chunk->identifier][$chunk->index] = time();
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
        unset($this->files[$identifier], $this->timestamps[$identifier]);
    }

    public function deleteChunk(Chunk $chunk): void
    {
        $path = $this->files[$chunk->identifier][$chunk->index] ?? null;
        if ($path === null) {
            return;
        }

        @unlink($path);
        unset($this->files[$chunk->identifier][$chunk->index], $this->timestamps[$chunk->identifier][$chunk->index]);
    }

    public function cleanOrphanedChunks(int $ttlSeconds): int
    {
        $now = time();
        $removed = 0;
        foreach (array_keys($this->files) as $identifier) {
            $oldest = $this->timestamps[$identifier] ?? $now;
            foreach ($this->timestamps[$identifier] ?? [] as $ts) {
                $oldest = min($oldest, $ts);
            }
            if ($oldest + $ttlSeconds <= $now) {
                $count = count($this->files[$identifier] ?? []);
                $this->deleteChunks($identifier);
                $removed += $count;
            }
        }
        return $removed;
    }

    public function hasChunks(string $identifier): bool
    {
        return isset($this->files[$identifier]) && $this->files[$identifier] !== [];
    }

    /**
     * Back-dates a stored artifact's timestamp to simulate staleness.
     */
    public function ageChunks(string $identifier, int $seconds): void
    {
        foreach (array_keys($this->timestamps[$identifier] ?? []) as $index) {
            $this->timestamps[$identifier][$index] = time() - $seconds;
        }
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

    /** @var array<string, int> Last write time per upload */
    private array $updatedAt = [];

    public function save(UploadState $state): void
    {
        $this->states[$state->identifier] = $state;
        $this->updatedAt[$state->identifier] = time();
    }

    public function get(string $identifier): ?UploadState
    {
        return $this->states[$identifier] ?? null;
    }

    public function delete(string $identifier): void
    {
        unset($this->states[$identifier], $this->updatedAt[$identifier]);
    }

    public function markChunkAsUploaded(string $identifier, int $chunkIndex): UploadState
    {
        $state = $this->states[$identifier] ?? throw new \RuntimeException('Missing test state.');
        $state = $state->withUploadedChunk($chunkIndex);
        $this->states[$identifier] = $state;
        $this->updatedAt[$identifier] = time();
        return $state;
    }

    public function cleanExpired(int $ttlSeconds): int
    {
        $cutoff = time() - $ttlSeconds;
        $removed = 0;
        foreach ($this->updatedAt as $identifier => $ts) {
            if ($ts <= $cutoff) {
                $this->delete($identifier);
                $removed++;
            }
        }
        return $removed;
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

    public function ageState(string $identifier, int $seconds): void
    {
        if (isset($this->updatedAt[$identifier])) {
            $this->updatedAt[$identifier] = time() - $seconds;
        }
    }
}

/**
 * No-op, in-memory event dispatcher recording every dispatched event for
 * assertions without requiring a real PSR-14 implementation.
 */
final class NullEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;
        return $event;
    }
}
