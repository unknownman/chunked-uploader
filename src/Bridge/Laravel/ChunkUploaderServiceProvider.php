<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Bridge\Laravel;

use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcher;
use Illuminate\Support\ServiceProvider;
use Resumable\ChunkedUploader\Bridge\Laravel\Events\LaravelEventDispatcher;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\ChunkUploader;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Contracts\AssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\StorageInterface;
use Resumable\ChunkedUploader\Core\Security\MagicByteValidator;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;

/**
 * Laravel service provider that registers the chunked uploader pipeline and
 * publishes its configuration file.
 *
 * The provider is an Adapter: it parses the Laravel config array into the
 * framework-agnostic UploaderConfig DTO and binds every core abstraction to the
 * drivers selected in `config/chunk-uploader.php`.
 */
class ChunkUploaderServiceProvider extends ServiceProvider
{
    /**
     * Registers the core abstractions and concrete drivers into the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/chunk-uploader.php', 'chunk-uploader');

        $this->app->singleton(UploaderConfig::class, static fn (): UploaderConfig => new UploaderConfig(
            maxChunkSize: (int) config('chunk-uploader.max_chunk_size'),
            maxFileSize: (int) config('chunk-uploader.max_file_size'),
            maxChunks: (int) config('chunk-uploader.max_chunks'),
            allowedMimeTypes: (array) config('chunk-uploader.allowed_mime_types'),
            spoolDirectory: (string) config('chunk-uploader.spool_directory'),
            garbageCollectionTtl: (int) config('chunk-uploader.garbage_collection_ttl'),
        ));

        $this->app->singleton(PathSanitizer::class);
        $this->app->singleton(ChunkValidatorInterface::class, MagicByteValidator::class);
        $this->app->singleton(AssemblerInterface::class, StreamAssembler::class);

        $this->app->singleton(EventDispatcherInterface::class, LaravelEventDispatcher::class);

        $this->app->singleton(ChunkUploader::class, static function ($app): ChunkUploader {
            /** @var Resumable\ChunkedUploader\Core\Configuration\UploaderConfig $config */
            $config = $app->make(UploaderConfig::class);
            $storage = $app->make(StorageInterface::class);
            $metadata = $app->make(MetadataRepositoryInterface::class);

            return new ChunkUploader(
                config: $config,
                storage: $storage,
                metadata: $metadata,
                assembler: $app->make(AssemblerInterface::class),
                validator: $app->make(ChunkValidatorInterface::class),
                dispatcher: $app->make(EventDispatcherInterface::class),
                logger: $app->make(\Psr\Log\LoggerInterface::class),
            );
        });

        $this->app->alias(ChunkUploader::class, 'chunk-uploader');
    }

    /**
     * Publishes the package configuration for application-level overrides.
     */
    public function boot(LaravelDispatcher $events): void
    {
        $this->publishes([
            __DIR__ . '/config/chunk-uploader.php' => config_path('chunk-uploader.php'),
        ], 'chunk-uploader-config');
    }
}