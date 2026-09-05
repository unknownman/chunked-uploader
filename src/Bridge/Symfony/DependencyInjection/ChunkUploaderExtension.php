<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

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

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setParameter('chunk_uploader.max_chunk_size', $config['max_chunk_size']);
        $container->setParameter('chunk_uploader.max_file_size', $config['max_file_size']);
        $container->setParameter('chunk_uploader.max_chunks', $config['max_chunks']);
        $container->setParameter('chunk_uploader.allowed_mime_types', $config['allowed_mime_types']);
        $container->setParameter('chunk_uploader.spool_directory', $config['spool_directory']);
        $container->setParameter('chunk_uploader.garbage_collection_ttl', $config['garbage_collection_ttl']);
    }
}