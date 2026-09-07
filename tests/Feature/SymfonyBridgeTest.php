<?php

declare(strict_types=1);

// File: tests/Feature/SymfonyBridgeTest.php

namespace Resumable\ChunkedUploader\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Bridge\Symfony\Command\CleanupOrphanedChunksCommand;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\ChunkUploaderExtension;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\Configuration;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\MetadataDriverFactory;
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
use Resumable\ChunkedUploader\Core\Security\RateLimiting\RedisRateLimiter;
use Resumable\ChunkedUploader\Core\Security\Scanners\ClamAvScanner;
use Resumable\ChunkedUploader\Core\Security\Scanners\NullVirusScanner;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
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
        self::assertTrue($container->hasDefinition(\Resumable\ChunkedUploader\Core\UploadManager::class));
        self::assertTrue($container->has(\Resumable\ChunkedUploader\Core\Contracts\UploadManagerInterface::class));
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
        );

        $storage = $factory->create();

        self::assertInstanceOf(LocalChunkStorage::class, $storage);
        self::assertInstanceOf(ChunkStorageInterface::class, $storage);
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
}
