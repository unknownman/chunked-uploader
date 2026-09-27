<?php

declare(strict_types=1);

// File: tests/Feature/SymfonyBridgeTest.php

namespace Resumable\ChunkedUploader\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Bridge\Symfony\Command\CleanupOrphanedChunksCommand;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\ChunkUploaderExtension;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\Configuration;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\MetadataDriverFactory;
use Resumable\ChunkedUploader\Core\Contracts\LockManagerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetricsTrackerInterface;
use Resumable\ChunkedUploader\Core\Drivers\Metrics\NullMetricsTracker;
use Resumable\ChunkedUploader\Core\Drivers\Locking\PdoLockManager;
use Resumable\ChunkedUploader\Core\Drivers\Locking\RedisLockManager;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\AssemblerDriverFactory;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\StorageDriverFactory;
use Resumable\ChunkedUploader\Bridge\Symfony\Events\SymfonyEventDispatcher;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\RateLimiterInterface;
use Resumable\ChunkedUploader\Core\Contracts\VirusScannerInterface;
use Resumable\ChunkedUploader\Core\Drivers\Metadata\PdoMetadataRepository;
use Resumable\ChunkedUploader\Core\Drivers\Storage\LocalChunkStorage;
use Resumable\ChunkedUploader\Core\GarbageCollector;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Validation\Rules\ChecksumRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\ExtensionMimeMatchRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\MagicByteRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\MaxChunkSizeRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\MaxTotalSizeRule;
use Resumable\ChunkedUploader\Core\Validation\ValidationPipeline;
use Resumable\ChunkedUploader\Core\Security\RateLimiting\RedisRateLimiter;
use Resumable\ChunkedUploader\Core\Security\Scanners\ClamAvScanner;
use Resumable\ChunkedUploader\Core\Security\Scanners\NullVirusScanner;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Core\Assembler\S3MultipartAssembler;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Tests\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SymfonyBridgeTest extends TestCase
{
    #[Test]
    public function test_configuration_tree_exposes_sane_defaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        self::assertSame(5 * 1024 * 1024, $config['max_chunk_size']);
        self::assertSame(100 * 1024 * 1024, $config['max_file_size']);
        self::assertSame(1000, $config['max_chunks']);
        self::assertSame([], $config['allowed_mime_types']);
        self::assertSame(3600, $config['garbage_collection_ttl']);
        self::assertSame('local', $config['storage']);
        self::assertSame('redis', $config['metadata']);
        self::assertSame('local', $config['checksum_verify']);
    }

    #[Test]
    public function test_configuration_tree_rejects_an_unknown_storage_driver(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [
            ['storage' => 'ftp'],
        ]);
    }

    #[Test]
    public function test_configuration_tree_accepts_full_user_overrides(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'storage' => 's3',
            's3' => ['bucket' => 'uploads', 'prefix' => 'tmp/'],
            'metadata' => 'pdo',
            'pdo' => ['table' => 'states', 'connection' => 'primary'],
        ]]);

        self::assertSame('s3', $config['storage']);
        self::assertSame('uploads', $config['s3']['bucket']);
        self::assertSame('pdo', $config['metadata']);
        self::assertSame('states', $config['pdo']['table']);
    }

    #[Test]
    public function test_extension_loads_and_registers_the_full_service_graph(): void
    {
        $container = new ContainerBuilder();
        $extension = new ChunkUploaderExtension();
        $extension->load([
            [
                'spool_directory' => $this->tempDir() . '/spool',
                'local' => ['base_directory' => $this->tempDir() . '/chunks'],
                'garbage_collection_ttl' => 7200,
                'token_secret' => 'secret-value',
            ],
        ], $container);

        self::assertSame(7200, $container->getParameter('chunk_uploader.garbage_collection_ttl'));
        self::assertSame('secret-value', $container->getParameter('chunk_uploader.token_secret'));
        self::assertTrue($container->hasDefinition(\Resumable\ChunkedUploader\Core\ChunkUploader::class));
        self::assertTrue($container->has(\Resumable\ChunkedUploader\Core\Contracts\ChunkUploaderInterface::class));
        self::assertTrue($container->hasDefinition(ChunkStorageInterface::class));
        self::assertTrue($container->hasDefinition(MetadataRepositoryInterface::class));
        self::assertTrue($container->hasDefinition(EventDispatcherInterface::class));
        self::assertTrue($container->hasDefinition(CleanupOrphanedChunksCommand::class));
    }

    #[Test]
    public function test_extension_registers_a_null_scanner_by_default(): void
    {
        $container = new ContainerBuilder();
        (new ChunkUploaderExtension())->load([], $container);

        self::assertSame(
            NullVirusScanner::class,
            $container->getDefinition(VirusScannerInterface::class)->getClass(),
        );
        self::assertFalse($container->hasDefinition(RateLimiterInterface::class));
        self::assertSame('', $container->getParameter('chunk_uploader.token_salt'));
    }

    #[Test]
    public function test_extension_wires_clamav_and_rate_limiting_when_enabled(): void
    {
        $container = new ContainerBuilder();
        (new ChunkUploaderExtension())->load([[
            'virus_scanning' => ['enabled' => true, 'host' => '10.0.0.5', 'port' => 3311],
            'rate_limiting' => ['enabled' => true, 'max_attempts' => 10, 'decay_seconds' => 30],
            'redis' => ['connection_service' => 'app.redis'],
        ]], $container);

        self::assertSame(ClamAvScanner::class, $container->getDefinition(VirusScannerInterface::class)->getClass());
        $scannerArgs = $container->getDefinition(VirusScannerInterface::class)->getArguments();
        self::assertSame('tcp://10.0.0.5:3311', $scannerArgs['$endpoint']);

        self::assertTrue($container->hasDefinition(RateLimiterInterface::class));
        self::assertSame(RedisRateLimiter::class, $container->getDefinition(RateLimiterInterface::class)->getClass());
        $limiterArgs = $container->getDefinition(RateLimiterInterface::class)->getArguments();
        self::assertInstanceOf(Reference::class, $limiterArgs['$redis']);
        self::assertSame('app.redis', (string) $limiterArgs['$redis']);
        self::assertSame('rate-limit:', $limiterArgs['$prefix']);
    }

    #[Test]
    public function test_enabling_rate_limiting_requires_a_redis_connection_service(): void
    {
        $container = new ContainerBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connection_service');
        (new ChunkUploaderExtension())->load([
            ['rate_limiting' => ['enabled' => true]],
        ], $container);
    }

    #[Test]
    public function test_storage_driver_factory_builds_a_local_driver(): void
    {
        $base = $this->tempDir() . '/local';
        $factory = new StorageDriverFactory(
            driver: 'local',
            localBaseDirectory: $base,
            s3Bucket: '',
            s3Prefix: 'chunks/',
            s3Config: ['version' => 'latest', 'region' => 'us-east-1', 'credentials' => ['key' => '', 'secret' => '']],
            sanitizer: new PathSanitizer(),
            metadata: new InMemoryMetadataRepository(),
        );

        $storage = $factory->create();

        self::assertInstanceOf(LocalChunkStorage::class, $storage);
        self::assertInstanceOf(ChunkStorageInterface::class, $storage);
    }

    #[Test]
    public function test_assembler_driver_factory_selects_the_matching_assembler_per_driver(): void
    {
        $s3Config = ['version' => 'latest', 'region' => 'us-east-1', 'credentials' => ['key' => '', 'secret' => '']];

        // The pairing is not cosmetic: multipart parts cannot be read back as
        // objects, so the S3 driver would fail on the first chunk if paired with
        // the streaming assembler.
        $s3Assembler = (new AssemblerDriverFactory(
            driver: 's3',
            finalBaseDir: $this->tempDir() . '/final',
            s3Bucket: 'bucket',
            s3Prefix: 'chunks/',
            s3Config: $s3Config,
            sanitizer: new PathSanitizer(),
        ))->create();
        self::assertInstanceOf(S3MultipartAssembler::class, $s3Assembler);

        $localAssembler = (new AssemblerDriverFactory(
            driver: 'local',
            finalBaseDir: $this->tempDir() . '/final',
            s3Bucket: 'bucket',
            s3Prefix: 'chunks/',
            s3Config: $s3Config,
            sanitizer: new PathSanitizer(),
        ))->create();
        self::assertInstanceOf(StreamAssembler::class, $localAssembler);
    }

    #[Test]
    public function test_metadata_driver_factory_builds_a_pdo_repository_and_tracker(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new MetadataDriverFactory(
            driver: 'pdo',
            redisClient: null,
            redisPrefix: 'chunked-uploader:',
            redisTtl: 0,
            pdo: $pdo,
            pdoTable: 'chunked_upload_states',
        );

        $repository = $factory->create();
        $tracker = $factory->createTracker();

        self::assertInstanceOf(PdoMetadataRepository::class, $repository);
        self::assertInstanceOf(PdoMetadataRepository::class, $tracker);
        self::assertInstanceOf(ProgressTrackerInterface::class, $tracker);

        $state = new \Resumable\ChunkedUploader\Core\Models\UploadState('track', 2, 20, 't.bin', [0], false);
        self::assertSame(50.0, $tracker->getPercentage($state));
    }

    #[Test]
    public function test_extension_registers_the_assembly_lock_and_its_parameters(): void
    {
        $container = new ContainerBuilder();
        (new ChunkUploaderExtension())->load([
            [
                'spool_directory' => $this->tempDir() . '/spool',
                'local' => ['base_directory' => $this->tempDir() . '/chunks'],
                'assembly_lock' => ['ttl' => 45, 'wait_seconds' => 3],
            ],
        ], $container);

        self::assertSame(45, $container->getParameter('chunk_uploader.assembly_lock.ttl'));
        self::assertSame(3, $container->getParameter('chunk_uploader.assembly_lock.wait_seconds'));
        self::assertTrue($container->hasDefinition(LockManagerInterface::class));
        self::assertTrue(
            $container->getDefinition(\Resumable\ChunkedUploader\Core\ChunkUploader::class)
                ->getArgument('$lockManager') instanceof \Symfony\Component\DependencyInjection\Reference,
            'The uploader must receive the lock manager, not a null default.',
        );
    }

    #[Test]
    public function test_telemetry_defaults_to_a_silent_tracker_and_is_overridable(): void
    {
        $container = new ContainerBuilder();
        (new ChunkUploaderExtension())->load([[]], $container);

        self::assertTrue($container->hasDefinition(MetricsTrackerInterface::class));
        self::assertSame(
            NullMetricsTracker::class,
            $container->getDefinition(MetricsTrackerInterface::class)->getClass(),
            'A deployment that wants no telemetry must not have to configure its way out of it.',
        );
        self::assertInstanceOf(
            \Symfony\Component\DependencyInjection\Reference::class,
            $container->getDefinition(\Resumable\ChunkedUploader\Core\ChunkUploader::class)
                ->getArgument('$metrics'),
            'The uploader must resolve the tracker from the container so a binding can replace it.',
        );
    }

    #[Test]
    public function test_an_application_tracker_replaces_the_silent_default(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->tempDir());
        $container->setParameter('kernel.cache_dir', $this->tempDir() . '/cache');
        (new ChunkUploaderExtension())->load([[]], $container);

        $mine = new class () implements MetricsTrackerInterface {
            public function incrementChunkUploaded(int $bytes): void
            {
            }

            public function incrementChecksumMismatch(string $driver, string $reason): void
            {
            }

            public function incrementLockCollision(string $key): void
            {
            }

            public function incrementLockLeaseExpired(string $key): void
            {
            }

            public function recordAssemblyTime(string $identifier, float $durationSeconds, string $driver): void
            {
            }
        };

        // Declaring the same id is the whole override story: no config flag in
        // this package to discover, and nothing to unset.
        $container->setDefinition(
            MetricsTrackerInterface::class,
            (new \Symfony\Component\DependencyInjection\Definition($mine::class))->setPublic(true),
        );
        $container->compile();

        self::assertInstanceOf($mine::class, $container->get(MetricsTrackerInterface::class));
    }

    #[Test]
    public function test_metadata_driver_factory_builds_a_pdo_lock_manager_on_the_same_connection(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new MetadataDriverFactory(
            driver: 'pdo',
            redisClient: null,
            redisPrefix: 'chunked-uploader:',
            redisTtl: 0,
            pdo: $pdo,
            pdoTable: 'chunked_upload_states',
        );

        $locks = $factory->createLockManager();

        self::assertInstanceOf(PdoLockManager::class, $locks);
        // ensureSchema() ran during construction, so the lock table is ready and
        // the very first upload is not blocked on a missing table.
        self::assertTrue($locks->acquire('assembly:lock:x', 30));
        self::assertFalse($factory->createLockManager()->acquire('assembly:lock:x', 30));
    }

    #[Test]
    public function test_metadata_driver_factory_builds_a_redis_lock_manager(): void
    {
        $factory = new MetadataDriverFactory(
            driver: 'redis',
            redisClient: new \Resumable\ChunkedUploader\Tests\Support\FakeRedis(),
            redisPrefix: 'chunked-uploader:',
            redisTtl: 0,
            pdo: null,
            pdoTable: 'chunked_upload_states',
        );

        self::assertInstanceOf(RedisLockManager::class, $factory->createLockManager());
    }

    #[Test]
    public function test_lock_manager_requires_the_same_connection_as_its_metadata_driver(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MetadataDriverFactory(
            driver: 'redis',
            redisClient: null,
            redisPrefix: 'chunked-uploader:',
            redisTtl: 0,
            pdo: null,
            pdoTable: 'chunked_upload_states',
        ))->createLockManager();
    }

    #[Test]
    public function test_metadata_driver_factory_requires_a_client_when_redis_is_selected(): void
    {
        $factory = new MetadataDriverFactory(
            driver: 'redis',
            redisClient: null,
            redisPrefix: 'chunked-uploader:',
            redisTtl: 0,
            pdo: null,
            pdoTable: 'chunked_upload_states',
        );

        $this->expectException(\RuntimeException::class);
        $factory->create();
    }

    #[Test]
    public function test_metadata_driver_factory_requires_a_pdo_when_pdo_is_selected(): void
    {
        $factory = new MetadataDriverFactory(
            driver: 'pdo',
            redisClient: null,
            redisPrefix: 'chunked-uploader:',
            redisTtl: 0,
            pdo: null,
            pdoTable: 'chunked_upload_states',
        );

        $this->expectException(\RuntimeException::class);
        $factory->create();
    }

    #[Test]
    public function test_symfony_event_dispatcher_adapts_core_events(): void
    {
        $inner = new EventDispatcher();
        $adapter = new SymfonyEventDispatcher($inner);

        $state = new \Resumable\ChunkedUploader\Core\Models\UploadState('ident', 1, 1, 'a.txt', [0], true);
        $event = new \Resumable\ChunkedUploader\Core\Events\FileAssembledEvent($state, '/final/a.txt');

        $result = $adapter->dispatch($event);

        self::assertSame($event, $result);
    }

    #[Test]
    public function test_cleanup_command_reports_removed_artifacts(): void
    {
        $collector = new GarbageCollector(new InMemoryChunkStorage(), new InMemoryMetadataRepository());
        $command = new CleanupOrphanedChunksCommand($collector, 60);
        $tester = new CommandTester($command);

        $exit = $tester->execute(['--ttl' => '120']);
        $output = $tester->getDisplay();

        self::assertSame(0, $exit);
        self::assertStringContainsString('0 orphaned chunks and 0 expired metadata records', $output);
    }

    #[Test]
    public function test_cleanup_command_rejects_a_non_positive_ttl(): void
    {
        $collector = new GarbageCollector(new InMemoryChunkStorage(), new InMemoryMetadataRepository());
        $command = new CleanupOrphanedChunksCommand($collector, 60);
        $tester = new CommandTester($command);

        $exit = $tester->execute(['--ttl' => '0']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('The TTL must be a positive number', $tester->getDisplay());
    }

    #[Test]
    public function test_cleanup_command_uses_the_configured_default_ttl(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $collector = new GarbageCollector($storage, $metadata);
        $command = new CleanupOrphanedChunksCommand($collector, 60);
        $tester = new CommandTester($command);

        $this->seedOrphan($storage, $metadata);

        $tester->execute([]);

        self::assertFalse($storage->hasChunks('orphaned upload'));
    }

    private function seedOrphan(InMemoryChunkStorage $storage, InMemoryMetadataRepository $metadata): void
    {
        $state = new \Resumable\ChunkedUploader\Core\Models\UploadState(
            identifier: 'orphaned upload',
            totalChunks: 2,
            totalSize: 200,
            originalFilename: 'orphan.bin',
            uploadedChunks: [0],
            isCompleted: false,
            finalPath: null,
        );
        $metadata->save($state);
        $metadata->ageState('orphaned upload', 7200);

        $chunk = new \Resumable\ChunkedUploader\Core\Models\Chunk(
            identifier: 'orphaned upload',
            token: '',
            index: 0,
            totalChunks: 2,
            chunkSize: 100,
            totalSize: 200,
            tmpFilePath: $this->temporaryFile('X'),
            originalFilename: 'orphan.bin',
        );
        $storage->store($chunk);
        $storage->ageChunks('orphaned upload', 7200);
    }

    #[Test]
    public function test_the_local_digest_check_runs_in_local_mode(): void
    {
        $pipeline = $this->validationPipeline(['checksum_verify' => 'local']);

        self::assertContains(ChecksumRule::class, $this->ruleClasses($pipeline));
    }

    /**
     * With the storage backend verifying chunks, hashing the temp file in PHP
     * too would mean reading and hashing every chunk a second time.
     */
    #[Test]
    public function test_the_local_digest_check_is_dropped_in_storage_mode(): void
    {
        $pipeline = $this->validationPipeline(['checksum_verify' => 'storage']);

        self::assertNotContains(ChecksumRule::class, $this->ruleClasses($pipeline));
    }

    #[Test]
    public function test_dropping_the_digest_check_leaves_the_other_rules_in_place(): void
    {
        $pipeline = $this->validationPipeline(['checksum_verify' => 'storage']);

        // Only the digest rule is conditional; size and content checks must not
        // be collateral damage of turning verification over to the backend.
        self::assertSame([
            MaxChunkSizeRule::class,
            MaxTotalSizeRule::class,
            MagicByteRule::class,
            ExtensionMimeMatchRule::class,
        ], $this->ruleClasses($pipeline));
    }

    #[Test]
    public function test_an_unknown_checksum_verify_mode_is_rejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            'checksum_verify' => 'sideways',
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validationPipeline(array $config): ValidationPipeline
    {
        $container = new ContainerBuilder();
        $extension = new ChunkUploaderExtension();
        $extension->load([
            [
                // Overridden so the tree's %kernel.project_dir% defaults are not
                // referenced; a bare ContainerBuilder has no kernel.
                'spool_directory' => $this->tempDir() . '/spool',
                'local' => ['base_directory' => $this->tempDir() . '/chunks'],
                ...$config,
            ],
        ], $container);

        // The bundle is loaded inside a compiled container in a real
        // application; instantiating services without compiling trips over the
        // named-argument defers the PHP-DSL uses for autowired definitions.
        // Compilation also inlines private services nothing references, so the
        // pipeline is made public to be reachable from the test.
        $container->getDefinition(ValidationPipeline::class)->setPublic(true);
        $container->compile();

        return $container->get(ValidationPipeline::class);
    }

    /**
     * @return list<class-string>
     */
    private function ruleClasses(ValidationPipeline $pipeline): array
    {
        $rules = (new \ReflectionProperty(ValidationPipeline::class, 'rules'))->getValue($pipeline);

        return array_map(static fn (object $rule): string => $rule::class, $rules);
    }
}
