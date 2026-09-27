<?php

declare(strict_types=1);

// File: tests/Feature/LaravelBridgeTest.php

namespace Resumable\ChunkedUploader\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcherContract;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Resumable\ChunkedUploader\Bridge\Laravel\Commands\CleanupOrphanedChunksCommand;
use Resumable\ChunkedUploader\Bridge\Laravel\Providers\ChunkUploaderServiceProvider;
use Resumable\ChunkedUploader\Bridge\Laravel\Events\LaravelEventDispatcher;
use Resumable\ChunkedUploader\Bridge\Laravel\Facades\ChunkUploader;
use Resumable\ChunkedUploader\Core\GarbageCollector;
use Resumable\ChunkedUploader\Core\Validation\Rules\ChecksumRule;
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
        self::assertStringContainsString("'token_salt' => env('CHUNK_UPLOADER_TOKEN_SALT'", $source);
        self::assertStringContainsString("'virus_scanning' => [", $source);
        self::assertStringContainsString("'enabled' => env('CHUNK_UPLOADER_VIRUS_SCANNING'", $source);
        self::assertStringContainsString("'host' => env('CHUNK_UPLOADER_CLAMAV_HOST'", $source);
        self::assertStringContainsString("'port' => (int) env('CHUNK_UPLOADER_CLAMAV_PORT'", $source);
        self::assertStringContainsString("'rate_limiting' => [", $source);
        self::assertStringContainsString("'max_attempts' => (int) env('CHUNK_UPLOADER_RATE_LIMIT_MAX'", $source);
        self::assertStringContainsString("'decay_seconds' => (int) env('CHUNK_UPLOADER_RATE_LIMIT_WINDOW'", $source);
        self::assertStringContainsString("'key' => env('CHUNK_UPLOADER_RATE_LIMIT_KEY'", $source);
        self::assertStringContainsString("'assembly_lock' => [", $source);
        self::assertStringContainsString("'ttl' => (int) env('CHUNK_UPLOADER_ASSEMBLY_LOCK_TTL'", $source);
        self::assertStringContainsString("'wait_seconds' => (int) env('CHUNK_UPLOADER_ASSEMBLY_LOCK_WAIT'", $source);
        self::assertStringContainsString("'checksum_verify' => env('CHUNK_UPLOADER_CHECKSUM_VERIFY'", $source);
    }

    #[Test]
    public function test_the_local_digest_check_runs_in_local_mode(): void
    {
        self::assertInstanceOf(ChecksumRule::class, $this->checksumRuleFor('local'));
    }

    /**
     * With S3 verifying the part it actually received, hashing the temp file in
     * PHP as well would read and hash every chunk a second time.
     */
    #[Test]
    public function test_the_local_digest_check_is_dropped_in_storage_mode(): void
    {
        self::assertNull($this->checksumRuleFor('storage'));
    }

    /**
     * The default must stay 'local' so an existing deployment keeps the
     * verification it already had after upgrading.
     */
    #[Test]
    public function test_the_digest_check_defaults_to_local_when_unconfigured(): void
    {
        self::assertInstanceOf(ChecksumRule::class, $this->checksumRuleFor(null));
    }

    private function checksumRuleFor(?string $mode): ?ChecksumRule
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturnCallback(
            // A null $mode means the key is absent, so the provider's own default
            // has to come through rather than an explicit null.
            static fn (string $key, $default = null) => $key === 'chunk-uploader.checksum_verify' && $mode !== null ? $mode : $default,
        );

        $provider = new ChunkUploaderServiceProvider(new Container());
        $method = new ReflectionMethod($provider, 'checksumRule');

        return $method->invoke($provider, $config);
    }

    #[Test]
    public function test_provider_registers_an_assembly_lock_following_the_metadata_driver(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Bridge/Laravel/Providers/ChunkUploaderServiceProvider.php');
        self::assertIsString($source);

        // The lock must not become a second backend to provision: it is selected
        // from the same driver switch as the metadata repository.
        self::assertStringContainsString('$this->registerLockManager();', $source);
        self::assertStringContainsString('$this->app->singleton(LockManagerInterface::class', $source);
        self::assertStringContainsString('new PdoLockManager(', $source);
        self::assertStringContainsString('new RedisLockManager(', $source);
        self::assertStringContainsString("'chunk-uploader.pdo.table') . '_locks'", $source);
        self::assertStringContainsString("'chunk-uploader.redis.prefix') . 'lock:'", $source);
    }

    #[Test]
    public function test_provider_defaults_telemetry_to_a_silent_tracker(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Bridge/Laravel/Providers/ChunkUploaderServiceProvider.php');
        self::assertIsString($source);

        self::assertStringContainsString('$this->registerMetrics();', $source);
        self::assertStringContainsString('NullMetricsTracker::class', $source);
        self::assertStringContainsString('metrics: $app->make(MetricsTrackerInterface::class)', $source);
    }

    #[Test]
    public function test_an_application_tracker_bound_before_the_provider_wins(): void
    {
        $app = $this->appWithConfig();
        $mine = new \Resumable\ChunkedUploader\Tests\Support\RecordingMetricsTracker();

        // Bound first, the way a package that owns telemetry would.
        $app->singleton(\Resumable\ChunkedUploader\Core\Contracts\MetricsTrackerInterface::class, static fn () => $mine);

        $provider = new \Resumable\ChunkedUploader\Bridge\Laravel\Providers\ChunkUploaderServiceProvider($app);
        $provider->register();

        self::assertSame(
            $mine,
            $app->make(\Resumable\ChunkedUploader\Core\Contracts\MetricsTrackerInterface::class),
            'The package must not overwrite a tracker the application already bound.',
        );
    }

    #[Test]
    public function test_a_silent_tracker_is_bound_when_the_application_has_not_chosen_one(): void
    {
        $app = $this->appWithConfig();
        $provider = new \Resumable\ChunkedUploader\Bridge\Laravel\Providers\ChunkUploaderServiceProvider($app);
        $provider->register();

        self::assertInstanceOf(
            \Resumable\ChunkedUploader\Core\Drivers\Metrics\NullMetricsTracker::class,
            $app->make(\Resumable\ChunkedUploader\Core\Contracts\MetricsTrackerInterface::class),
        );
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

    /**
     * A bare container with just enough of a config repository for the provider's
     * register() pass to run.
     */
    private function appWithConfig(): \Illuminate\Container\Container
    {
        // Reports itself as having cached configuration, which makes
        // ServiceProvider::mergeConfigFrom() a no-op. That is deliberate: the
        // package config file calls env(), and env() is only functional inside a
        // full Laravel install because the library it needs ships with the
        // framework rather than with illuminate/support. These tests are about
        // which services get bound, not about parsing that config file, so the
        // merge is skipped rather than dragging in a framework-only dependency.
        $app = new class () extends \Illuminate\Container\Container implements \Illuminate\Contracts\Foundation\CachesConfiguration {
            public function configurationIsCached(): bool
            {
                return true;
            }

            public function getCachedConfigPath(): string
            {
                return $this->tempDirForTests() . '/config.php';
            }

            public function getCachedServicesPath(): string
            {
                return $this->tempDirForTests() . '/services.php';
            }

            private function tempDirForTests(): string
            {
                return sys_get_temp_dir() . '/chunked-uploader-laravel-bridge';
            }
        };

        // A stand-in for Illuminate\Config\Repository, which is not a dev
        // dependency here. The provider only ever calls get(), and its own type
        // declaration for the repository is a docblock, so this is enough to let
        // register() complete.
        // The provider reads configuration through the global app() helper, not
        // through an injected container, so this instance has to be the global
        // one for register() to see the repository.
        \Illuminate\Container\Container::setInstance($app);

        $app->instance('config', new class () {
            /** @var array<string, mixed> */
            private array $values = [
                'chunk-uploader.assembly_lock.ttl' => 60,
                'chunk-uploader.assembly_lock.wait_seconds' => 10,
            ];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            /**
             * ServiceProvider::register() republishes package config on boot.
             *
             * @param array<string, mixed> $config
             */
            public function set(array $config): void
            {
            }
        });

        return $app;
    }
}
