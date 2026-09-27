<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/Resources/config/services.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\Resources\config;

use Resumable\ChunkedUploader\Bridge\Symfony\Command\CleanupOrphanedChunksCommand;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\AssemblerDriverFactory;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\MetadataDriverFactory;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\StorageDriverFactory;
use Resumable\ChunkedUploader\Bridge\Symfony\Events\SymfonyEventDispatcher;
use Resumable\ChunkedUploader\Core\ChunkUploader;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Configuration\ChunkedUploadConfigResolver;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkUploaderInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\LockManagerInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\GarbageCollector;
use Resumable\ChunkedUploader\Core\Security\MagicByteValidator;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Core\Validation\ChunkSecurityValidator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherContract;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(UploaderConfig::class)
        ->arg('$maxChunkSize', '%chunk_uploader.max_chunk_size%')
        ->arg('$maxFileSize', '%chunk_uploader.max_file_size%')
        ->arg('$maxChunks', '%chunk_uploader.max_chunks%')
        ->arg('$allowedMimeTypes', '%chunk_uploader.allowed_mime_types%')
        ->arg('$spoolDirectory', '%chunk_uploader.spool_directory%')
        ->arg('$garbageCollectionTtl', '%chunk_uploader.garbage_collection_ttl%')
        ->arg('$tokenSalt', '%chunk_uploader.token_salt%');

    $services->set(PathSanitizer::class);
    $services->set(ChunkedUploadConfigResolver::class);

    $services->set(MagicByteValidator::class)
        ->arg('$config', service(UploaderConfig::class));

    $services->set(UploadTokenService::class)
        ->arg('$secret', '%chunk_uploader.token_secret%');

    // The assembler is chosen from the same `storage` parameter as the storage
    // driver: local storage assembles by streaming, S3 by completing a multipart
    // upload. See AssemblerDriverFactory for why the two cannot be mixed.
    $services->set(AssemblerDriverFactory::class)
        ->arg('$driver', '%chunk_uploader.storage%')
        ->arg('$finalBaseDir', '%chunk_uploader.spool_directory%/final')
        ->arg('$s3Bucket', '%chunk_uploader.s3.bucket%')
        ->arg('$s3Prefix', '%chunk_uploader.s3.prefix%')
        ->arg('$s3Config', '%chunk_uploader.s3.config%')
        ->arg('$s3FinalPrefix', '%chunk_uploader.s3.final_prefix%');

    $services->set(FileAssemblerInterface::class)
        ->factory([service(AssemblerDriverFactory::class), 'create']);

    $services->set(SymfonyEventDispatcherContract::class, EventDispatcher::class);
    $services->set(EventDispatcherInterface::class, SymfonyEventDispatcher::class);

    // The validation rules themselves are no longer registered here:
    // ValidationPipelineFactory builds the list because the digest check is
    // conditional. Registering them individually would imply a wiring that no
    // longer exists, and Symfony drops unreferenced private services anyway.

    $services->set(ChunkValidatorInterface::class, ChunkSecurityValidator::class)
        ->arg('$tokenSalt', '%chunk_uploader.token_salt%')
        ->arg('$config', service(UploaderConfig::class));

    $services->set(StorageDriverFactory::class)
        ->arg('$driver', '%chunk_uploader.storage%')
        ->arg('$localBaseDirectory', '%chunk_uploader.local.base_directory%')
        ->arg('$s3Bucket', '%chunk_uploader.s3.bucket%')
        ->arg('$s3Prefix', '%chunk_uploader.s3.prefix%')
        ->arg('$s3Config', '%chunk_uploader.s3.config%')
        ->arg('$s3FinalPrefix', '%chunk_uploader.s3.final_prefix%');

    $services->set(ChunkStorageInterface::class)
        ->factory([service(StorageDriverFactory::class), 'create']);

    $services->set(MetadataDriverFactory::class)
        ->arg('$driver', '%chunk_uploader.metadata%')
        ->arg('$redisClient', null)
        ->arg('$redisPrefix', '%chunk_uploader.redis.prefix%')
        ->arg('$redisTtl', '%chunk_uploader.redis.ttl%')
        ->arg('$pdo', null)
        ->arg('$pdoTable', '%chunk_uploader.pdo.table%');

    $services->set(MetadataRepositoryInterface::class)
        ->factory([service(MetadataDriverFactory::class), 'create']);

    $services->set(ProgressTrackerInterface::class)
        ->factory([service(MetadataDriverFactory::class), 'createTracker']);

    // Built from the same factory and therefore the same Redis/PDO connection
    // as the metadata repository, so the assembly lock and the upload state it
    // protects cannot end up in two different datastores.
    $services->set(LockManagerInterface::class)
        ->factory([service(MetadataDriverFactory::class), 'createLockManager']);

    $services->set(ChunkUploader::class)
        ->arg('$maxChunkAttempts', '%chunk_uploader.rate_limiting.max_attempts%')
        ->arg('$rateLimitWindow', '%chunk_uploader.rate_limiting.decay_seconds%')
        ->arg('$rateLimitKey', '%chunk_uploader.rate_limiting.key%')
        ->arg('$lockManager', service(LockManagerInterface::class))
        ->arg('$assemblyLockTtl', '%chunk_uploader.assembly_lock.ttl%')
        ->arg('$assemblyWaitSeconds', '%chunk_uploader.assembly_lock.wait_seconds%');

    $services->alias(ChunkUploaderInterface::class, ChunkUploader::class);
    $services->alias('chunk-uploader', ChunkUploader::class);

    $services->set(GarbageCollector::class);

    $services->set(CleanupOrphanedChunksCommand::class)
        ->arg('$defaultTtl', '%chunk_uploader.garbage_collection_ttl%');
};
