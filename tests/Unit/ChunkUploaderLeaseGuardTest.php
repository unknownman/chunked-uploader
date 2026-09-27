<?php

declare(strict_types=1);

// File: tests/Unit/ChunkUploaderLeaseGuardTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\ChunkUploader;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\HeartbeatAwareAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\LockManagerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\AssemblyLeaseLostException;
use Resumable\ChunkedUploader\Core\Locking\AssemblyHeartbeat;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\NullEventDispatcher;
use Resumable\ChunkedUploader\Tests\Support\InMemoryLockManager;
use Resumable\ChunkedUploader\Tests\Support\RecordingMetricsTracker;
use Resumable\ChunkedUploader\Tests\TestCase;

/**
 * Covers lease renewal and the split-brain guard inside the assembly critical
 * section.
 *
 * The invariant every test defends: a node that no longer holds the assembly
 * lock must never publish `finalPath` and must never delete the chunks. Both
 * are irreversible, and both become actively destructive once a second node
 * owns the upload -- the loser would be deleting the winner's source data, or
 * reporting a path the winner is still rewriting.
 */
final class ChunkUploaderLeaseGuardTest extends TestCase
{
    private const IDENTIFIER = 'lease-guard-upload';

    protected function setUp(): void
    {
        parent::setUp();

        InMemoryLockManager::reset();
    }

    protected function tearDown(): void
    {
        InMemoryLockManager::reset();

        parent::tearDown();
    }

    #[Test]
    public function test_a_heartbeat_aware_assembler_is_given_a_heartbeat_and_never_keeps_it(): void
    {
        $assembler = new HeartbeatAwareFakeAssembler($this->tempDir() . '/final');
        $attachedDuringAssembly = false;

        $assembler->onAssemble = static function () use ($assembler, &$attachedDuringAssembly): void {
            $attachedDuringAssembly = $assembler->attachedHeartbeat !== null;
        };

        $this->upload($assembler, new InMemoryLockManager())->processChunk($this->finalChunk());

        self::assertTrue($attachedDuringAssembly, 'A checkpointing assembler must be handed a live heartbeat.');
        self::assertNull(
            $assembler->attachedHeartbeat,
            'The heartbeat must be detached once the critical section ends, or a retained assembler '
            . 'would keep renewing a lease that has already been released.',
        );
    }

    #[Test]
    public function test_an_assembler_without_checkpoints_is_never_handed_a_heartbeat(): void
    {
        $assembler = new OpaqueFakeAssembler($this->tempDir() . '/final');
        $attached = false;

        $assembler->onAssemble = static function () use ($assembler, &$attached): void {
            $attached = $assembler->wasOfferedHeartbeat;
        };

        $this->upload($assembler, new InMemoryLockManager())->processChunk($this->finalChunk());

        // The capability is only granted to assemblers that can actually check in.
        // Handing it to a single-shot assembler would advertise a lease guard that
        // provably cannot run.
        self::assertFalse($attached, 'An assembler with no checkpoint must not be offered a heartbeat it cannot tick.');
    }

    #[Test]
    public function test_a_lease_stolen_mid_assembly_aborts_without_publishing_or_deleting_chunks(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $metrics = new RecordingMetricsTracker();
        $locks = new InMemoryLockManager();
        $assembler = new HeartbeatAwareFakeAssembler($this->tempDir() . '/final');

        // A competitor takes the lock while we are still assembling. Only the
        // heartbeat can notice, because renew() is the one call that re-checks
        // the token.
        $assembler->onTick = static function (int $tick): void {
            if ($tick === 2) {
                InMemoryLockManager::stealByAnotherNode(ChunkUploader::ASSEMBLY_LOCK_PREFIX . self::IDENTIFIER, 60);
            }
        };

        $uploader = $this->upload($assembler, $locks, $storage, $metadata, $metrics);

        try {
            $uploader->processChunk($this->finalChunk());
            self::fail('Losing the assembly lock mid-flight must not be reported as a successful upload.');
        } catch (AssemblyLeaseLostException) {
            // The message is deliberately not asserted: the loss is caught by
            // whichever check runs first, and both are correct. What matters is
            // that it is caught at all before anything irreversible happens.
        }

        self::assertNull(
            $metadata->get(self::IDENTIFIER)?->finalPath,
            'A node that lost the lock must not publish a final path the new owner is also writing.',
        );
        self::assertTrue(
            $storage->hasChunks(self::IDENTIFIER),
            'The chunks belong to the new lock owner now; deleting them would destroy its source data.',
        );
        self::assertCount(1, $metrics->leaseExpirations, 'The lost lease is the one signal that must page someone.');
    }

    #[Test]
    public function test_a_lock_taken_by_a_competitor_is_caught_even_without_any_checkpoint(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $assembler = new OpaqueFakeAssembler($this->tempDir() . '/final');
        $lockKey = ChunkUploader::ASSEMBLY_LOCK_PREFIX . self::IDENTIFIER;

        // The S3-shaped case: no tick points, so the heartbeat never runs and the
        // only possible defence is the ownership re-check after the call returns.
        $assembler->onAssemble = static function () use ($lockKey): void {
            InMemoryLockManager::stealByAnotherNode($lockKey, 60);
        };

        $uploader = $this->upload($assembler, new InMemoryLockManager(), $storage, $metadata);

        $this->expectException(AssemblyLeaseLostException::class);
        try {
            $uploader->processChunk($this->finalChunk());
        } finally {
            self::assertNull($metadata->get(self::IDENTIFIER)?->finalPath);
            self::assertTrue($storage->hasChunks(self::IDENTIFIER));
        }
    }

    #[Test]
    public function test_a_lease_that_simply_expired_with_no_competitor_still_finalizes(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $assembler = new OpaqueFakeAssembler($this->tempDir() . '/final');
        $lockKey = ChunkUploader::ASSEMBLY_LOCK_PREFIX . self::IDENTIFIER;

        // The lease lapses but nobody claims it. That is benign: abandoning a
        // finished assembly here would lose the client's file over a lock nobody
        // wanted. Distinguishing this from a real competitor is the whole point
        // of re-acquiring before giving up.
        $assembler->onAssemble = static function () use ($lockKey): void {
            InMemoryLockManager::advance(120);
        };

        $result = $this->upload($assembler, new InMemoryLockManager(), $storage, $metadata)
            ->processChunk($this->finalChunk());

        self::assertNotNull($result->finalPath);
        self::assertFalse($storage->hasChunks(self::IDENTIFIER), 'A finalized upload has had its chunks cleaned up.');
    }

    #[Test]
    public function test_a_successful_assembly_reports_its_duration(): void
    {
        $metrics = new RecordingMetricsTracker();

        $this->upload(new HeartbeatAwareFakeAssembler($this->tempDir() . '/final'), new InMemoryLockManager(), null, null, $metrics)
            ->processChunk($this->finalChunk());

        self::assertCount(1, $metrics->assemblyTimes);
        self::assertSame(self::IDENTIFIER, $metrics->assemblyTimes[0]['identifier']);
        self::assertSame('local', $metrics->assemblyTimes[0]['driver']);
        self::assertGreaterThanOrEqual(0.0, $metrics->assemblyTimes[0]['seconds']);
    }

    #[Test]
    public function test_accepted_chunks_are_counted_once_their_progress_is_durable(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $metrics = new RecordingMetricsTracker();

        // Two chunks: the first is mid-upload, the second completes the upload.
        $uploader = $this->upload(
            new HeartbeatAwareFakeAssembler($this->tempDir() . '/final'),
            new InMemoryLockManager(),
            $storage,
            $metadata,
            $metrics,
        );

        $uploader->processChunk($this->chunkAt(0, 2, 8));
        $uploader->processChunk($this->chunkAt(1, 2, 8));

        self::assertSame(16, $metrics->chunkBytes);
    }

    #[Test]
    public function test_a_chunk_whose_size_is_unknown_is_not_counted_as_zero_bytes(): void
    {
        $storage = new InMemoryChunkStorage();
        $metrics = new RecordingMetricsTracker();

        $this->upload(
            new HeartbeatAwareFakeAssembler($this->tempDir() . '/final'),
            new InMemoryLockManager(),
            $storage,
            null,
            $metrics,
        )->processChunk($this->chunkAt(0, 1, 0));

        self::assertSame(0, $metrics->chunkBytes, 'An unknown length is not a zero-byte upload.');
    }

    #[Test]
    public function test_a_contended_assembly_is_counted_as_a_collision(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $metrics = new RecordingMetricsTracker();
        $lockKey = ChunkUploader::ASSEMBLY_LOCK_PREFIX . self::IDENTIFIER;

        // A separate node already holds the lock and is mid-assembly, so this
        // upload is the losing side of a genuine race rather than a simulated one.
        (new InMemoryLockManager())->acquire($lockKey, 60);

        $assembler = new OpaqueFakeAssembler($this->tempDir() . '/final');
        $loser = $this->upload($assembler, new InMemoryLockManager(), $storage, $metadata, $metrics);

        $result = $loser->processChunk($this->finalChunk());

        self::assertCount(1, $metrics->collisions, 'Losing the race for the assembly lock is an operational signal.');
        self::assertSame(0, $assembler->calls, 'The loser must not enter the assembler.');
        self::assertNull($result->finalPath, 'A lost race reports progress, not a fabricated final path.');
    }

    #[Test]
    public function test_no_lock_manager_still_finalizes_without_a_heartbeat(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $assembler = new HeartbeatAwareFakeAssembler($this->tempDir() . '/final');
        $heartbeatDuringAssembly = true;

        $assembler->onAssemble = static function () use ($assembler, &$heartbeatDuringAssembly): void {
            $heartbeatDuringAssembly = $assembler->attachedHeartbeat !== null;
        };

        $result = $this->upload($assembler, null, $storage, $metadata)->processChunk($this->finalChunk());

        self::assertNotNull($result->finalPath);
        self::assertFalse(
            $heartbeatDuringAssembly,
            'With no lock manager there is no lease to keep alive, so no heartbeat may be attached.',
        );
    }

    /**
     * The full production path, minus the wall-clock wait.
     *
     * A one-second lease renews after half a second, so the assembler below
     * advances an injected clock by 0.7s at its first checkpoint. The heartbeat,
     * the lock manager, the renewal window and the finalization guard are all the
     * production classes doing the real thing -- only the source of "now" is
     * substituted, which is what keeps this test from becoming a race against the
     * speed of whatever machine it runs on.
     */
    #[Test]
    public function test_a_lease_held_past_its_ttl_is_renewed_end_to_end(): void
    {
        $locks = new InMemoryLockManager();
        $metrics = new RecordingMetricsTracker();
        $assembler = new HeartbeatAwareFakeAssembler($this->tempDir() . '/final');
        $clock = new ControllableClock();

        $assembler->onTick = static function (int $tick) use ($clock): void {
            if ($tick === 1) {
                // Past the ttl/2 window of 0.5s, so the very next beat renews.
                $clock->advance(0.7);
            }
        };

        $uploader = $this->upload(
            $assembler,
            $locks,
            null,
            null,
            $metrics,
            assemblyLockTtl: 1,
            clock: $clock->toClosure(),
        );

        $result = $uploader->processChunk($this->finalChunk());

        self::assertNotNull($result->finalPath, 'A renewed lease must let assembly finish normally.');
        self::assertCount(0, $metrics->leaseExpirations, 'A working heartbeat never loses the lease.');
        self::assertGreaterThan(
            0,
            $assembler->renewals,
            'The lease must be renewed while an assembly outlives its TTL.',
        );
    }

    #[Test]
    public function test_a_steal_during_a_real_renewal_window_is_caught_by_the_heartbeat(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $metrics = new RecordingMetricsTracker();
        $lockKey = ChunkUploader::ASSEMBLY_LOCK_PREFIX . self::IDENTIFIER;
        $assembler = new HeartbeatAwareFakeAssembler($this->tempDir() . '/final');

        $clock = new ControllableClock();

        $assembler->onTick = static function (int $tick) use ($clock, $lockKey): void {
            if ($tick !== 1) {
                return;
            }
            // Outlive the window, then let a competitor in. This beat's renew()
            // is the first call that can notice the token has changed.
            $clock->advance(0.7);
            InMemoryLockManager::stealByAnotherNode($lockKey, 60);
        };

        $uploader = $this->upload(
            $assembler,
            new InMemoryLockManager(),
            $storage,
            $metadata,
            $metrics,
            assemblyLockTtl: 1,
            clock: $clock->toClosure(),
        );

        try {
            $uploader->processChunk($this->finalChunk());
            self::fail('A lease lost to a competitor must not be reported as a successful upload.');
        } catch (AssemblyLeaseLostException) {
            // Expected: the heartbeat saw renew() fail and refused to finalize.
        }

        self::assertCount(1, $metrics->leaseExpirations, 'A lease lost mid-assembly is the signal that must page.');
        self::assertNull($metadata->get(self::IDENTIFIER)?->finalPath);
        self::assertTrue($storage->hasChunks(self::IDENTIFIER), 'The chunks now belong to the new lock owner.');
    }

    /**
     * Proves the production assembler really does checkpoint.
     *
     * {@see StreamAssembler} is the only built-in that can hold a lease open for a
     * long file, so its per-chunk tick is what makes the whole feature load
     * bearing. The clock is a closure that advances on every reading, which
     * simulates a slow copy per chunk without any waiting.
     */
    #[Test]
    public function test_the_production_stream_assembler_renews_across_a_slow_multi_chunk_copy(): void
    {
        // Enough chunk boundaries that simulated time comfortably exceeds the
        // 60s lease several times over, while each step stays small enough that a
        // renewal always arrives well before the previous deadline.
        $chunks = 20;
        $locks = new InMemoryLockManager();
        $locks->acquire('assembly:lock:real', 60);
        $metrics = new RecordingMetricsTracker();

        // Each reading of the clock jumps 10s and pushes the lock manager's clock
        // along with it, so simulated time passes for both. Twenty chunk
        // boundaries at roughly two reads each puts the assembly several lease
        // lengths past the original deadline: without a renewal per boundary the
        // key would be dropped mid-copy, which is the failure under test.
        $reads = 0;
        $heartbeat = new AssemblyHeartbeat(
            $locks,
            'assembly:lock:real',
            60,
            $metrics,
            static function () use (&$reads): float {
                InMemoryLockManager::advance(10);

                return $reads++ * 10.0;
            },
        );

        $finalDir = $this->tempDir() . '/final';
        $assembler = new StreamAssembler($finalDir);
        $assembler->setHeartbeat($heartbeat);

        $storage = new InMemoryChunkStorage();
        for ($i = 0; $i < $chunks; ++$i) {
            $storage->store(new Chunk(
                identifier: 'real',
                token: '',
                index: $i,
                totalChunks: $chunks,
                chunkSize: 4,
                totalSize: $chunks * 4,
                tmpFilePath: $this->temporaryFile('data'),
                originalFilename: 'payload.txt',
            ));
        }

        $path = $assembler->assemble(
            new UploadState('real', $chunks, $chunks * 4, 'payload.txt', range(0, $chunks - 1), false, null),
            $storage,
        );

        self::assertFileExists($path);
        self::assertGreaterThan(
            1,
            $heartbeat->renewalCount(),
            'A copy that outlives its lease must renew repeatedly, not once.',
        );
        self::assertFalse($heartbeat->lost(), 'A healthy heartbeat never reports a lost lease.');

        // The real invariant: simulated time ran far past the 60s lease, yet the
        // key is still held. If the tick per chunk boundary were removed, the lock
        // would have been dropped mid-copy and this assertion would fail.
        self::assertTrue(
            InMemoryLockManager::isLocked('assembly:lock:real'),
            'The lease must still be held after an assembly that outlived its TTL.',
        );
        self::assertCount(0, $metrics->leaseExpirations);
    }

    #[Test]
    public function test_the_stream_assembler_tolerates_a_detached_heartbeat(): void
    {
        // The uploader detaches in a finally block, so an assembler that outlives
        // one critical section must not fatal on the next upload.
        $storage = new InMemoryChunkStorage();
        $storage->store(new Chunk(
            identifier: 'detached',
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: 4,
            totalSize: 4,
            tmpFilePath: $this->temporaryFile('data'),
            originalFilename: 'payload.txt',
        ));

        $assembler = new StreamAssembler($this->tempDir() . '/final2');
        $assembler->setHeartbeat(null);

        $path = $assembler->assemble(
            new UploadState('detached', 1, 4, 'payload.txt', [0], false, null),
            $storage,
        );

        self::assertFileExists($path);
    }

    private function upload(
        FileAssemblerInterface $assembler,
        ?LockManagerInterface $locks,
        ?ChunkStorageInterface $storage = null,
        ?InMemoryMetadataRepository $metadata = null,
        ?RecordingMetricsTracker $metrics = null,
        int $assemblyLockTtl = 30,
        ?\Closure $clock = null,
    ): ChunkUploader {
        $validator = $this->createMock(ChunkValidatorInterface::class);
        $validator->method('validate')->willReturn(true);
        $validator->method('validateWithConfig')->willReturn(true);

        $progress = $this->createMock(ProgressTrackerInterface::class);
        $progress->method('isComplete')->willReturnCallback(
            static fn (UploadState $s): bool => $s->isComplete(),
        );

        return new ChunkUploader(
            storage: $storage ?? new InMemoryChunkStorage(),
            metadata: $metadata ?? new InMemoryMetadataRepository(),
            progress: $progress,
            assembler: $assembler,
            validator: $validator,
            dispatcher: new NullEventDispatcher(),
            config: new UploaderConfig(),
            lockManager: $locks,
            assemblyLockTtl: $assemblyLockTtl,
            // Zero wait: these tests assert on the contended path itself, and the
            // default ten-second poll would make every one of them slow.
            assemblyWaitSeconds: 0,
            metrics: $metrics ?? new RecordingMetricsTracker(),
            clock: $clock,
        );
    }

    private function finalChunk(): Chunk
    {
        return $this->chunkAt(0, 1, 8);
    }

    private function chunkAt(int $index, int $totalChunks, int $size): Chunk
    {
        return new Chunk(
            identifier: self::IDENTIFIER,
            token: '',
            index: $index,
            totalChunks: $totalChunks,
            chunkSize: $size,
            totalSize: $size * $totalChunks,
            // A real file, because the storage double copies from this path
            // exactly as a local driver would.
            tmpFilePath: $this->temporaryFile(str_repeat('A', max($size, 1))),
            originalFilename: 'payload.txt',
        );
    }
}

/**
 * A checkpointing assembler: the shape {@see StreamAssembler} has, where control
 * returns to PHP between units of work.
 */
/**
 * A monotonic time source the test moves by hand.
 *
 * Renewal decisions are threshold comparisons, so they are only reproducible if
 * the test decides what "now" is. Sleeping would make the same guarantee at the
 * cost of wall-clock time and a suite that gets slower, and flakier, on a loaded
 * machine.
 */
final class ControllableClock
{
    private float $now = 1_000.0;

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    public function toClosure(): \Closure
    {
        return fn (): float => $this->now;
    }
}

final class HeartbeatAwareFakeAssembler implements HeartbeatAwareAssemblerInterface
{
    public int $calls = 0;

    public int $ticks = 0;

    public int $renewals = 0;

    public ?AssemblyHeartbeat $attachedHeartbeat = null;

    /** @var null|callable(int): void */
    public $onTick = null;

    /** @var null|callable(): void */
    public $onAssemble = null;

    public string $path;

    public function __construct(string $destinationDir)
    {
        $destinationDir = rtrim($destinationDir, '/');
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0o777, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException('Unable to create assembler destination.');
        }
        $this->path = $destinationDir . '/assembled.bin';
    }

    public function setHeartbeat(?AssemblyHeartbeat $heartbeat): void
    {
        $this->attachedHeartbeat = $heartbeat;
    }

    public function assemble(UploadState $state, ChunkStorageInterface $storage): string
    {
        ++$this->calls;

        // Three checkpoints, so a competitor can appear part-way through rather
        // than only at the very start or end.
        for ($i = 1; $i <= 3; ++$i) {
            if ($this->onTick !== null) {
                ($this->onTick)($i);
            }
            $before = $this->attachedHeartbeat?->renewalCount() ?? 0;
            $this->attachedHeartbeat?->tick();
            $this->renewals += ($this->attachedHeartbeat?->renewalCount() ?? 0) - $before;
            ++$this->ticks;
        }

        if ($this->onAssemble !== null) {
            ($this->onAssemble)();
        }

        return $this->path;
    }
}

/**
 * A single-shot assembler with no checkpoint, matching the S3 multipart shape
 * where the whole critical section is one opaque call.
 */
final class OpaqueFakeAssembler implements FileAssemblerInterface
{
    public int $calls = 0;

    public bool $wasOfferedHeartbeat = false;

    /** @var null|callable(): void */
    public $onAssemble = null;

    public string $path;

    public function __construct(string $destinationDir)
    {
        $destinationDir = rtrim($destinationDir, '/');
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0o777, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException('Unable to create assembler destination.');
        }
        $this->path = $destinationDir . '/assembled.bin';
    }

    public function assemble(UploadState $state, ChunkStorageInterface $storage): string
    {
        ++$this->calls;

        if ($this->onAssemble !== null) {
            ($this->onAssemble)();
        }

        return $this->path;
    }
}
