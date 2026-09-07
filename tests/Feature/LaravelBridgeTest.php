<?php

declare(strict_types=1);

// File: tests/Feature/LaravelBridgeTest.php

namespace Resumable\ChunkedUploader\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcherContract;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Resumable\ChunkedUploader\Bridge\Laravel\Commands\CleanupOrphanedChunksCommand;
use Resumable\ChunkedUploader\Bridge\Laravel\Events\LaravelEventDispatcher;
use Resumable\ChunkedUploader\Bridge\Laravel\Facades\ChunkUploader;
use Resumable\ChunkedUploader\Core\GarbageCollector;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class LaravelBridgeTest extends TestCase
{
    #[Test]
    public function test_facade_resolves_the_chunk_uploader_binding(): void
    {
        $accessor = (new ReflectionMethod(ChunkUploader::class, 'getFacadeAccessor'))->invoke(null);

        self::assertSame('chunk-uploader', $accessor);
    }

    #[Test]
    public function test_event_dispatcher_forwards_core_events_into_laravel(): void
    {
        $inner = $this->createMock(LaravelDispatcherContract::class);
        $inner->expects(self::once())->method('dispatch')->willReturnCallback(
            static fn (object $event): object => $event,
        );

        $adapter = new LaravelEventDispatcher($inner);
        $state = new \Resumable\ChunkedUploader\Core\Models\UploadState('forwarded', 1, 1, 'a.txt', [0], true);
        $event = new \Resumable\ChunkedUploader\Core\Events\ChunkUploadedEvent(
            $state,
            new \Resumable\ChunkedUploader\Core\Models\Chunk(
                identifier: 'forwarded',
                token: '',
                index: 0,
                totalChunks: 1,
                chunkSize: 1,
                totalSize: 1,
                tmpFilePath: $this->temporaryFile('X'),
                originalFilename: 'a.txt',
            ),
        );

        self::assertSame($event, $adapter->dispatch($event));
    }

    #[Test]
    public function test_config_file_exposes_the_expected_driver_switches_and_limits(): void
    {
        $path = __DIR__ . '/../../src/Bridge/Laravel/config/chunk-uploader.php';

        exec(sprintf('%s -l %s', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $out, $code);
        self::assertSame(0, $code, 'Published Laravel config must be syntactically valid.');

        $source = file_get_contents($path);
        self::assertIsString($source);

        self::assertStringContainsString("'max_chunk_size' => 5 * 1024 * 1024", $source);
        self::assertStringContainsString("'max_file_size' => 100 * 1024 * 1024", $source);
        self::assertStringContainsString("'max_chunks' => 1000", $source);
        self::assertStringContainsString("'garbage_collection_ttl' => 3600", $source);
        self::assertStringContainsString("'storage' => 'local'", $source);
        self::assertStringContainsString("Supported: 'local' | 's3'", $source);
        self::assertStringContainsString("'metadata' => 'redis'", $source);
        self::assertStringContainsString("Supported: 'redis' | 'pdo'", $source);
        self::assertStringContainsString("'bucket' => env('CHUNK_UPLOADER_S3_BUCKET'", $source);
        self::assertStringContainsString("'prefix' => env('CHUNK_UPLOADER_REDIS_PREFIX'", $source);
        self::assertStringContainsString("'table' => env('CHUNK_UPLOADER_PDO_TABLE'", $source);
    }

    #[Test]
    public function test_cleanup_command_runs_the_collector_and_reports_success(): void
    {
        $collector = new GarbageCollector(new InMemoryChunkStorage(), new InMemoryMetadataRepository());
        $command = new CleanupOrphanedChunksCommand($collector);
        $command->setLaravel($this->makeTestContainer());

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--ttl' => '120']);

        self::assertSame(0, $exit);
        self::assertStringContainsString(
            'Removed 0 orphaned chunks and 0 expired metadata records.',
            $tester->getDisplay(),
        );
    }

    #[Test]
    public function test_cleanup_command_rejects_a_non_positive_ttl(): void
    {
        $collector = new GarbageCollector(new InMemoryChunkStorage(), new InMemoryMetadataRepository());
        $command = new CleanupOrphanedChunksCommand($collector);
        $command->setLaravel($this->makeTestContainer());

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--ttl' => '0']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('The TTL must be a positive number', $tester->getDisplay());
    }

    #[Test]
    public function test_cleanup_command_purges_orphans_when_run_default(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $collector = new GarbageCollector($storage, $metadata);
        $command = new CleanupOrphanedChunksCommand($collector);
        $command->setLaravel($this->makeTestContainer());

        $this->seedOrphan($storage, $metadata);

        $tester = new CommandTester($command);
        $tester->execute(['--ttl' => '30']);

        self::assertFalse($storage->hasChunks('orphan upload'));
        self::assertNull($metadata->get('orphan upload'));
    }

    private function makeTestContainer(): Container
    {
        return new class extends Container {
            public function runningUnitTests(): bool
            {
                return false;
            }
        };
    }

    private function seedOrphan(InMemoryChunkStorage $storage, InMemoryMetadataRepository $metadata): void
    {
        $state = new \Resumable\ChunkedUploader\Core\Models\UploadState(
            identifier: 'orphan upload',
            totalChunks: 1,
            totalSize: 100,
            originalFilename: 'orphan.txt',
            uploadedChunks: [0],
            isCompleted: false,
            finalPath: null,
        );
        $metadata->save($state);
        $metadata->ageState('orphan upload', 7200);

        $chunk = new \Resumable\ChunkedUploader\Core\Models\Chunk(
            identifier: 'orphan upload',
            token: '',
            index: 0,
            totalChunks: 1,
            chunkSize: 100,
            totalSize: 100,
            tmpFilePath: $this->temporaryFile('O'),
            originalFilename: 'orphan.txt',
        );
        $storage->store($chunk);
        $storage->ageChunks('orphan upload', 7200);
    }
}
