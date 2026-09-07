<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/Resources/config/services.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\Resources\config;

use Resumable\ChunkedUploader\Bridge\Symfony\Command\CleanupOrphanedChunksCommand;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\MetadataDriverFactory;
use Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection\StorageDriverFactory;
use Resumable\ChunkedUploader\Bridge\Symfony\Events\SymfonyEventDispatcher;
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
        ->arg('$garbageCollectionTtl', '%chunk_uploader.garbage_collection_ttl%');

    $services->set(PathSanitizer::class);

    $services->set(MagicByteValidator::class)
        ->arg('$config', service(UploaderConfig::class));

    $services->set(UploadTokenService::class)
        ->arg('$secret', '%chunk_uploader.token_secret%');

    $services->set(FileAssemblerInterface::class, StreamAssembler::class)
        ->arg('$finalBaseDir', '%chunk_uploader.spool_directory%/final');

    $services->set(SymfonyEventDispatcherContract::class, EventDispatcher::class);
    $services->set(EventDispatcherInterface::class, SymfonyEventDispatcher::class);

    $services->set(VirusScannerInterface::class, NullVirusScanner::class);

    $services->set(MaxChunkSizeRule::class)
        ->arg('$maxBytes', '%chunk_uploader.max_chunk_size%');
    $services->set(MaxTotalSizeRule::class)
        ->arg('$maxBytes', '%chunk_uploader.max_file_size%');
    $services->set(MagicByteRule::class)
        ->arg('$allowedMimes', '%chunk_uploader.allowed_mime_types%');
    $services->set(ExtensionMimeMatchRule::class);
    $services->set(ChecksumRule::class);

    $services->set(ValidationPipeline::class)
        ->args([
            service(MaxChunkSizeRule::class),
            service(MaxTotalSizeRule::class),
            service(MagicByteRule::class),
            service(ExtensionMimeMatchRule::class),
            service(ChecksumRule::class),
        ]);

    $services->set(ChunkValidatorInterface::class, ChunkSecurityValidator::class);

    $services->set(StorageDriverFactory::class)
        ->arg('$driver', '%chunk_uploader.storage%')
        ->arg('$localBaseDirectory', '%chunk_uploader.local.base_directory%')
        ->arg('$s3Bucket', '%chunk_uploader.s3.bucket%')
        ->arg('$s3Prefix', '%chunk_uploader.s3.prefix%')
        ->arg('$s3Config', '%chunk_uploader.s3.config%');

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

    $services->set(UploadManager::class);

    $services->alias(UploadManagerInterface::class, UploadManager::class);
    $services->alias('chunk-uploader', UploadManager::class);

    $services->set(GarbageCollector::class);

    $services->set(CleanupOrphanedChunksCommand::class)
        ->arg('$defaultTtl', '%chunk_uploader.garbage_collection_ttl%');
};
