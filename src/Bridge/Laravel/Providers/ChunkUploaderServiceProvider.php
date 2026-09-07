<?php

declare(strict_types=1);

// File: src/Bridge/Laravel/Providers/ChunkUploaderServiceProvider.php

namespace Resumable\ChunkedUploader\Bridge\Laravel\Providers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcher;
use Illuminate\Support\ServiceProvider;
use Resumable\ChunkedUploader\Bridge\Laravel\Commands\CleanupOrphanedChunksCommand;
use Resumable\ChunkedUploader\Bridge\Laravel\Events\LaravelEventDispatcher;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\UploadManagerInterface;
use Resumable\ChunkedUploader\Core\Contracts\VirusScannerInterface;
use Resumable\ChunkedUploader\Core\Drivers\Metadata\PdoMetadataRepository;
use Resumable\ChunkedUploader\Core\Drivers\Metadata\RedisMetadataRepository;
use Resumable\ChunkedUploader\Core\Drivers\Storage\LocalChunkStorage;
use Resumable\ChunkedUploader\Core\Drivers\Storage\S3ChunkStorage;
use Resumable\ChunkedUploader\Core\GarbageCollector;
use Resumable\ChunkedUploader\Core\Security\MagicByteValidator;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\Scanners\NullVirusScanner;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Core\UploadManager;
use Resumable\ChunkedUploader\Core\Validation\ChunkSecurityValidator;
use Resumable\ChunkedUploader\Core\Validation\Rules\ChecksumRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\ExtensionMimeMatchRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\MagicByteRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\MaxChunkSizeRule;
use Resumable\ChunkedUploader\Core\Validation\Rules\MaxTotalSizeRule;
use Resumable\ChunkedUploader\Core\Validation\ValidationPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Laravel service provider that registers the chunked uploader pipeline,
 * publishes its configuration file, and registers the cleanup console command.
 *
 * The provider parses the Laravel config array into the framework-agnostic
 * UploaderConfig DTO and binds every core abstraction to the driver selected in
 * `config/chunk-uploader.php`.
 */
class ChunkUploaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/chunk-uploader.php', 'chunk-uploader');

        $this->registerUploaderConfig();
        $this->registerCore();
        $this->registerStorage();
        $this->registerMetadata();
        $this->registerValidation();
    }

    public function boot(LaravelDispatcher $events): void
    {
        $this->publishes([
            __DIR__ . '/../config/chunk-uploader.php' => config_path('chunk-uploader.php'),
        ], 'chunk-uploader-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CleanupOrphanedChunksCommand::class]);
        }
    }

    private function registerUploaderConfig(): void
    {
        $this->app->singleton(UploaderConfig::class, static function (): UploaderConfig {
            /** @var ConfigRepository $config */
            $config = app('config');

            return new UploaderConfig(
                maxChunkSize: (int) $config->get('chunk-uploader.max_chunk_size'),
                maxFileSize: (int) $config->get('chunk-uploader.max_file_size'),
                maxChunks: (int) $config->get('chunk-uploader.max_chunks'),
                allowedMimeTypes: (array) $config->get('chunk-uploader.allowed_mime_types'),
                spoolDirectory: (string) $config->get('chunk-uploader.spool_directory'),
                garbageCollectionTtl: (int) $config->get('chunk-uploader.garbage_collection_ttl'),
            );
        });
    }

    private function registerCore(): void
    {
        $this->app->singleton(PathSanitizer::class);
        $this->app->singleton(MagicByteValidator::class);

        $this->app->singleton(UploadTokenService::class, static function (): UploadTokenService {
            /** @var ConfigRepository $config */
            $config = app('config');
            $secret = (string) $config->get('chunk-uploader.token_secret');
            if ($secret === '') {
                $secret = (string) $config->get('app.key');
            }

            return new UploadTokenService($secret);
        });

        $this->app->singleton(FileAssemblerInterface::class, static function (): FileAssemblerInterface {
            /** @var ConfigRepository $config */
            $config = app('config');
            $base = (string) $config->get('chunk-uploader.spool_directory') . '/final';

            return new StreamAssembler($base);
        });

        $this->app->singleton(EventDispatcherInterface::class, LaravelEventDispatcher::class);
        $this->app->singleton(VirusScannerInterface::class, NullVirusScanner::class);

        $this->app->singleton(UploadManager::class, static function ($app): UploadManager {
            return new UploadManager(
                storage: $app->make(ChunkStorageInterface::class),
                metadata: $app->make(MetadataRepositoryInterface::class),
                progress: $app->make(ProgressTrackerInterface::class),
                assembler: $app->make(FileAssemblerInterface::class),
                validator: $app->make(ChunkValidatorInterface::class),
                dispatcher: $app->make(EventDispatcherInterface::class),
                logger: $app->bound(\Psr\Log\LoggerInterface::class)
                    ? $app->make(\Psr\Log\LoggerInterface::class)
                    : null,
            );
        });

        $this->app->alias(UploadManager::class, UploadManagerInterface::class);
        $this->app->alias(UploadManager::class, 'chunk-uploader');

        $this->app->singleton(GarbageCollector::class, static function ($app): GarbageCollector {
            return new GarbageCollector(
                storage: $app->make(ChunkStorageInterface::class),
                metadata: $app->make(MetadataRepositoryInterface::class),
                logger: $app->bound(\Psr\Log\LoggerInterface::class) ? $app->make(\Psr\Log\LoggerInterface::class) : null,
            );
        });
    }

    private function registerStorage(): void
    {
        $this->app->singleton(ChunkStorageInterface::class, static function ($app): ChunkStorageInterface {
            /** @var ConfigRepository $config */
            $config = app('config');
            $driver = (string) $config->get('chunk-uploader.storage', 'local');

            if ($driver === 's3') {
                return new S3ChunkStorage(
                    client: new \Aws\S3\S3Client((array) $config->get('chunk-uploader.s3.config')),
                    bucket: (string) $config->get('chunk-uploader.s3.bucket'),
                    basePrefix: (string) $config->get('chunk-uploader.s3.prefix'),
                    sanitizer: $app->make(PathSanitizer::class),
                );
            }

            return new LocalChunkStorage(
                baseDir: (string) $config->get('chunk-uploader.local.base_directory'),
                sanitizer: $app->make(PathSanitizer::class),
            );
        });
    }

    private function registerMetadata(): void
    {
        $this->app->singleton(MetadataRepositoryInterface::class, static function ($app): MetadataRepositoryInterface {
            /** @var ConfigRepository $config */
            $config = app('config');
            $driver = (string) $config->get('chunk-uploader.metadata', 'redis');

            if ($driver === 'pdo') {
                $connection = (string) $config->get(
                    'chunk-uploader.pdo.connection',
                    config('database.default', 'mysql'),
                );
                $pdo = DB::connection($connection)->getPdo();
                $repository = new PdoMetadataRepository(
                    pdo: $pdo,
                    tableName: (string) $config->get('chunk-uploader.pdo.table'),
                );
                $repository->ensureSchema();

                return $repository;
            }

            $client = $config->get('chunk-uploader.redis.client', 'phpredis') === 'predis'
                ? Redis::connection($config->get('chunk-uploader.redis.connection'))->client()
                : Redis::connection($config->get('chunk-uploader.redis.connection'))->client();

            return new RedisMetadataRepository(
                redis: $client,
                keyPrefix: (string) $config->get('chunk-uploader.redis.prefix'),
                ttl: (int) $config->get('chunk-uploader.redis.ttl') ?: null,
            );
        });

        $this->app->singleton(ProgressTrackerInterface::class, static function ($app): ProgressTrackerInterface {
            $repository = $app->make(MetadataRepositoryInterface::class);
            if (!$repository instanceof ProgressTrackerInterface) {
                throw new \RuntimeException(
                    'Configured metadata repository must also implement ProgressTrackerInterface.',
                );
            }

            return $repository;
        });
    }

    private function registerValidation(): void
    {
        $this->app->singleton(ChunkValidatorInterface::class, static function ($app): ChunkValidatorInterface {
            /** @var ConfigRepository $config */
            $config = app('config');

            $pipeline = new ValidationPipeline([
                new MaxChunkSizeRule((int) $config->get('chunk-uploader.max_chunk_size')),
                new MaxTotalSizeRule((int) $config->get('chunk-uploader.max_file_size')),
                new MagicByteRule(
                    $app->make(MagicByteValidator::class),
                    (array) $config->get('chunk-uploader.allowed_mime_types'),
                ),
                new ExtensionMimeMatchRule($app->make(MagicByteValidator::class)),
                new ChecksumRule(),
            ]);

            return new ChunkSecurityValidator(
                sanitizer: $app->make(PathSanitizer::class),
                pipeline: $pipeline,
                tokenService: $app->make(UploadTokenService::class),
                scanner: $app->make(VirusScannerInterface::class),
            );
        });
    }
}
