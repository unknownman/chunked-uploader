<?php

declare(strict_types=1);

// File: tests/Unit/ChunkUploaderAssemblyLockTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\ChunkUploader;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\LockManagerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\NullEventDispatcher;
use Resumable\ChunkedUploader\Tests\Support\InMemoryLockManager;
use Resumable\ChunkedUploader\Tests\TestCase;

/**
 * Covers the cross-server race in {@see ChunkUploader::tryAssembleAndFinalize()}.
 *
 * Two nodes each receiving one of the last chunks both observe a complete
 * upload, so both would enter the assembler. Every test here asserts the single
 * invariant that matters: at most one assembly, and every node agrees on the
 * single resulting path.
 */
final class ChunkUploaderAssemblyLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        InMemoryLockManager::reset();
    }

    protected function tearDown(): void
    {
        InMemoryLockManager::reset();
    }

    #[Test]
    public function test_concurrent_final_chunks_produce_exactly_one_assembly(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $assembler = new RecordingAssembler($this->tempDir() . '/final');

        // Two independent "servers": separate uploader instances and lock
        // managers, sharing only the datastore and the final destination.
        $nodeB = $this->node($storage, $metadata, $assembler, new InMemoryLockManager());
        $nodeA = $this->node($storage, $metadata, $assembler, new InMemoryLockManager());

        $chunk = $this->finalChunk('payload');

        // Node B lands the last chunk first and is inside the assembler when
        // node A arrives with a duplicate of the same final chunk. This
        // re-entrancy reproduces the interleaving deterministically: without a
        // lock, A sails straight into assemble() while B is still there, and
        // trips over the parts B has already deleted.
        $nodeAError = null;
        $assembler->onAssemble = static function () use ($nodeA, $chunk, &$nodeAError): void {
            try {
                $nodeA->processChunk($chunk);
            } catch (\Throwable $e) {
                $nodeAError = $e;
            }
        };

        $first = $nodeB->processChunk($chunk);

        self::assertNull($nodeAError, 'A second node must not disturb the node that owns assembly.');
        self::assertSame(1, $assembler->calls, 'Only one node may run the assembler.');
        self::assertNotNull($first->finalPath);

        $second = $nodeA->getStatus($chunk->identifier);
        self::assertSame($first->finalPath, $second?->finalPath, 'Every node must agree on the one final path.');
    }

    #[Test]
    public function test_a_node_turned_away_returns_the_winners_published_path_without_assembling(): void
    {
        $storage = new InMemoryChunkStorage();
        $assembler = new RecordingAssembler($this->tempDir() . '/final');
        $metadata = $this->completedUpload();

        $locks = new InMemoryLockManager();
        // Some other node holds the lock and finishes while this one polls.
        $locks->lockExternally(ChunkUploader::ASSEMBLY_LOCK_PREFIX . 'race', 30);

        // The winner completes on the first poll, exactly as a real node would
        // after finishing its own assembly.
        $publishWinner = static function () use ($metadata, $assembler): void {
            $current = $metadata->get('race');
            if ($current !== null) {
                $metadata->save($current->withFinalPath($assembler->path));
            }
        };

        $node = $this->node($storage, $metadata, $assembler, $locks, 5, 50_000, $publishWinner);

        $result = $node->processChunk($this->finalChunk('race'));

        self::assertSame(0, $assembler->calls, 'A node that lost the lock must not assemble.');
        self::assertSame($assembler->path, $result->finalPath);
    }

    #[Test]
    public function test_a_node_takes_over_assembly_when_the_holders_lease_expires(): void
    {
        $storage = new InMemoryChunkStorage();
        $assembler = new RecordingAssembler($this->tempDir() . '/final');
        $metadata = $this->completedUpload();

        $locks = new InMemoryLockManager();
        // The holding node dies without releasing; only its lease can free it.
        $locks->lockExternally(ChunkUploader::ASSEMBLY_LOCK_PREFIX . 'race', 1);

        $expireLease = static function (): void {
            InMemoryLockManager::advance(5);
        };

        $node = $this->node($storage, $metadata, $assembler, $locks, 5, 50_000, $expireLease);

        $result = $node->processChunk($this->finalChunk('race'));

        self::assertSame(1, $assembler->calls, 'An abandoned lease must be reclaimable.');
        self::assertSame($assembler->path, $result->finalPath);
    }

    #[Test]
    public function test_the_lock_is_released_after_a_successful_assembly(): void
    {
        $storage = new InMemoryChunkStorage();
        $assembler = new RecordingAssembler($this->tempDir() . '/final');
        $metadata = new InMemoryMetadataRepository();
        $locks = new InMemoryLockManager();

        $this->node($storage, $metadata, $assembler, $locks)->processChunk($this->finalChunk('payload'));

        self::assertFalse(
            InMemoryLockManager::isLocked(ChunkUploader::ASSEMBLY_LOCK_PREFIX . 'payload'),
            'The lock must not outlive the assembly.',
        );
    }

    #[Test]
    public function test_the_lock_is_released_when_assembly_throws(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $locks = new InMemoryLockManager();
        $assembler = new RecordingAssembler($this->tempDir() . '/final');
        $assembler->failWith = new \RuntimeException('s3 exploded');

        $node = $this->node($storage, $metadata, $assembler, $locks);

        try {
            $node->processChunk($this->finalChunk('payload'));
            self::fail('Expected the assembly failure to surface.');
        } catch (UploadFailedException $e) {
            self::assertStringContainsString('Assembly failed', $e->getMessage());
        }

        self::assertFalse(
            InMemoryLockManager::isLocked(ChunkUploader::ASSEMBLY_LOCK_PREFIX . 'payload'),
            'A failed assembly must still release the lock so a retry can proceed.',
        );
    }

    #[Test]
    public function test_a_failed_assembly_is_retried_successfully_once_the_lock_is_free(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $locks = new InMemoryLockManager();
        $assembler = new RecordingAssembler($this->tempDir() . '/final');
        $assembler->failWith = new \RuntimeException('transient');

        $node = $this->node($storage, $metadata, $assembler, $locks);

        try {
            $node->processChunk($this->finalChunk('payload'));
        } catch (UploadFailedException) {
        }

        $assembler->failWith = null;

        $result = $node->processChunk($this->finalChunk('payload'));

        self::assertSame(2, $assembler->calls);
        self::assertSame($assembler->path, $result->finalPath, 'A retry must be able to finish the upload.');
    }

    #[Test]
    public function test_an_already_finalized_upload_is_never_reassembled(): void
    {
        $storage = new InMemoryChunkStorage();
        $assembler = new RecordingAssembler($this->tempDir() . '/final');
        $metadata = new InMemoryMetadataRepository();
        $locks = new InMemoryLockManager();

        $node = $this->node($storage, $metadata, $assembler, $locks);
        $chunk = $this->finalChunk('payload');
        $node->processChunk($chunk);

        // A client retrying the final chunk after completion must be a no-op.
        $result = $node->processChunk($chunk);

        self::assertSame(1, $assembler->calls);
        self::assertSame($assembler->path, $result->finalPath);
    }

    #[Test]
    public function test_omitting_the_lock_manager_keeps_single_node_behaviour(): void
    {
        $storage = new InMemoryChunkStorage();
        $assembler = new RecordingAssembler($this->tempDir() . '/final');
        $metadata = new InMemoryMetadataRepository();
        $validator = $this->createMock(ChunkValidatorInterface::class);
        $validator->method('validate')->willReturn(true);
        $progress = $this->createMock(ProgressTrackerInterface::class);
        $progress->method('isComplete')->willReturnCallback(
            static fn (UploadState $s): bool => $s->isComplete(),
        );

        $uploader = new ChunkUploader(
            storage: $storage,
            metadata: $metadata,
            progress: $progress,
            assembler: $assembler,
            validator: $validator,
            dispatcher: new NullEventDispatcher(),
        );

        $result = $uploader->processChunk($this->finalChunk('payload'));

        self::assertSame(1, $assembler->calls);
        self::assertSame($assembler->path, $result->finalPath);
    }

    #[Test]
    public function test_an_assembly_failure_without_a_lock_manager_is_still_wrapped(): void
    {
        $storage = new InMemoryChunkStorage();
        $assembler = new RecordingAssembler($this->tempDir() . '/final');
        $assembler->failWith = new \RuntimeException('disk full');
        $metadata = new InMemoryMetadataRepository();
        $validator = $this->createMock(ChunkValidatorInterface::class);
        $validator->method('validate')->willReturn(true);
        $progress = $this->createMock(ProgressTrackerInterface::class);
        $progress->method('isComplete')->willReturnCallback(
            static fn (UploadState $s): bool => $s->isComplete(),
        );

        $uploader = new ChunkUploader(
            storage: $storage,
            metadata: $metadata,
            progress: $progress,
            assembler: $assembler,
            validator: $validator,
            dispatcher: new NullEventDispatcher(),
        );

        $this->expectException(UploadFailedException::class);
        $uploader->processChunk($this->finalChunk('payload'));
    }

    /**
     * Builds one "server": its own uploader, lock manager, and assembler wiring,
     * all sharing the given storage, metadata, and lock table.
     */
    private function node(
        ChunkStorageInterface $storage,
        MetadataRepositoryInterface $metadata,
        RecordingAssembler $assembler,
        LockManagerInterface $locks,
        int $waitSeconds = 0,
        int $pollMicroseconds = 10_000,
        ?callable $onPoll = null,
        int $pollHookAfter = 1,
    ): ChunkUploader {
        $validator = $this->createMock(ChunkValidatorInterface::class);
        $validator->method('validate')->willReturn(true);
        $validator->method('validateWithConfig')->willReturn(true);

        $progress = $this->createMock(ProgressTrackerInterface::class);
        $progress->method('isComplete')->willReturnCallback(
            static fn (UploadState $s): bool => $s->isComplete(),
        );

        if ($onPoll !== null) {
            $invoked = false;
            // The first read happens in processChunk(), well before the poll
            // loop, so hooks are armed only after it.
            $metadata = new PollHookMetadataRepository(
                $metadata,
                static function () use (&$invoked, $onPoll): void {
                    if ($invoked) {
                        return;
                    }
                    $invoked = true;
                    $onPoll();
                },
                $pollHookAfter,
            );
        }

        return new ChunkUploader(
            storage: $storage,
            metadata: $metadata,
            progress: $progress,
            assembler: $assembler,
            validator: $validator,
            dispatcher: new NullEventDispatcher(),
            config: new UploaderConfig(),
            lockManager: $locks,
            assemblyLockTtl: 30,
            assemblyWaitSeconds: $waitSeconds,
            assemblyPollMicroseconds: $pollMicroseconds,
        );
    }

    /**
     * A repository with no recorded progress, so processing the single final
     * chunk drives the upload straight to assembly.
     */
    private function completedUpload(): InMemoryMetadataRepository
    {
        return new InMemoryMetadataRepository();
    }

    private function finalChunk(string $identifier): Chunk
    {
        return new Chunk(
            identifier: $identifier,
            token: 'tok',
            index: 0,
            totalChunks: 1,
            chunkSize: 7,
            totalSize: 7,
            tmpFilePath: $this->temporaryFile('payload'),
            originalFilename: 'payload.txt',
        );
    }
}

/**
 * Assembler double that records how many times it ran, so double assembly is
 * observable, and can be made to fail or re-enter another node mid-flight.
 */
final class RecordingAssembler implements FileAssemblerInterface
{
    public int $calls = 0;

    public ?\Throwable $failWith = null;

    /** @var null|callable(): void */
    public $onAssemble = null;

    public string $path;

    public function __construct(string $destinationDir)
    {
        $destinationDir = rtrim($destinationDir, '/');
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0o777, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException('Unable to create assembler destination ' . $destinationDir);
        }

        $this->path = $destinationDir . '/assembled.bin';
    }

    public function assemble(UploadState $state, ChunkStorageInterface $storage): string
    {
        $this->calls++;

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        if ($this->onAssemble !== null) {
            $hook = $this->onAssemble;
            // One-shot: the re-entrant node must not recurse indefinitely.
            $this->onAssemble = null;
            $hook();
        }

        $body = '';
        for ($i = 0; $i < $state->totalChunks; $i++) {
            $stream = $storage->getChunkStream(new Chunk(
                identifier: $state->identifier,
                token: 'tok',
                index: $i,
                totalChunks: $state->totalChunks,
                chunkSize: 1,
                totalSize: 1,
                tmpFilePath: '',
                originalFilename: '',
            ));
            if (is_resource($stream)) {
                $body .= (string) stream_get_contents($stream);
            }
        }

        file_put_contents($this->path, $body);

        return $this->path;
    }
}

/**
 * Runs a callback the first time the upload state is read, standing in for the
 * other node's activity happening while this one polls.
 */
final class PollHookMetadataRepository implements MetadataRepositoryInterface
{
    private int $reads = 0;

    public function __construct(
        private readonly MetadataRepositoryInterface $inner,
        private readonly \Closure $onGet,
        private readonly int $skip,
    ) {
    }

    public function save(UploadState $state): void
    {
        $this->inner->save($state);
    }

    public function get(string $identifier): ?UploadState
    {
        $this->reads++;

        if ($this->reads > $this->skip) {
            ($this->onGet)();
        }

        return $this->inner->get($identifier);
    }

    public function delete(string $identifier): void
    {
        $this->inner->delete($identifier);
    }

    public function markChunkAsUploaded(string $identifier, int $chunkIndex): UploadState
    {
        return $this->inner->markChunkAsUploaded($identifier, $chunkIndex);
    }

    public function recordPartEtag(string $identifier, int $partNumber, string $etag): UploadState
    {
        return $this->inner->recordPartEtag($identifier, $partNumber, $etag);
    }

    public function cleanExpired(int $ttlSeconds): int
    {
        return $this->inner->cleanExpired($ttlSeconds);
    }
}
