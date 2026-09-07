<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/DependencyInjection/ChunkUploaderExtension.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Loads the bundle's container configuration.
 *
 * Reads `chunk_uploader` YAML config keys, validates them via the package
 * Configuration, and instantiates the framework-agnostic UploaderConfig DTO
 * before wiring the Core abstractions into the Symfony container.
 */
class ChunkUploaderExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');

        $parameters = [
            'chunk_uploader.max_chunk_size' => $config['max_chunk_size'],
            'chunk_uploader.max_file_size' => $config['max_file_size'],
            'chunk_uploader.max_chunks' => $config['max_chunks'],
            'chunk_uploader.allowed_mime_types' => $config['allowed_mime_types'],
            'chunk_uploader.spool_directory' => $config['spool_directory'],
            'chunk_uploader.garbage_collection_ttl' => $config['garbage_collection_ttl'],
            'chunk_uploader.token_secret' => $config['token_secret'],
            'chunk_uploader.storage' => $config['storage'],
            'chunk_uploader.local.base_directory' => $config['local']['base_directory'],
            'chunk_uploader.s3.bucket' => $config['s3']['bucket'],
            'chunk_uploader.s3.prefix' => $config['s3']['prefix'],
            'chunk_uploader.s3.config' => [
                'version' => $config['s3']['config']['version'],
                'region' => $config['s3']['config']['region'],
                'credentials' => [
                    'key' => $config['s3']['config']['key'],
                    'secret' => $config['s3']['config']['secret'],
                ],
            ],
            'chunk_uploader.metadata' => $config['metadata'],
            'chunk_uploader.redis.client' => $config['redis']['client'],
            'chunk_uploader.redis.prefix' => $config['redis']['prefix'],
            'chunk_uploader.redis.ttl' => $config['redis']['ttl'],
            'chunk_uploader.pdo.table' => $config['pdo']['table'],
            'chunk_uploader.pdo.connection' => $config['pdo']['connection'],
        ];

        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
        }
    }
}
