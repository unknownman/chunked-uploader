<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/DependencyInjection/Configuration.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Declares and validates the bundle's configuration tree for Symfony.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('chunk_uploader');

        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('max_chunk_size')->defaultValue(5 * 1024 * 1024)->end()
                ->integerNode('max_file_size')->defaultValue(100 * 1024 * 1024)->end()
                ->integerNode('max_chunks')->defaultValue(1000)->end()
                ->arrayNode('allowed_mime_types')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('spool_directory')->defaultValue('%kernel.project_dir%/var/chunked-uploader')->end()
                ->integerNode('garbage_collection_ttl')->defaultValue(3600)->end()
                ->scalarNode('token_secret')->defaultValue('')->end()
                ->scalarNode('token_salt')->defaultValue('')->end()
                ->arrayNode('virus_scanning')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('host')->defaultValue('127.0.0.1')->end()
                        ->integerNode('port')->defaultValue(3310)->end()
                    ->end()
                ->end()
                ->arrayNode('rate_limiting')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->integerNode('max_attempts')->defaultValue(100)->end()
                        ->integerNode('decay_seconds')->defaultValue(60)->end()
                        ->scalarNode('key')->defaultValue('chunked-uploader:chunks')->end()
                    ->end()
                ->end()
                ->arrayNode('assembly_lock')
                    ->addDefaultsIfNotSet()
                    ->info('Serializes final file assembly so concurrent final chunks across nodes produce exactly one final object. TTL must exceed the slowest expected assembly.')
                    ->children()
                        ->integerNode('ttl')->min(1)->defaultValue(60)->end()
                        ->integerNode('wait_seconds')->min(0)->defaultValue(10)->end()
                    ->end()
                ->end()
                // Where a client-supplied digest is checked. 'local' re-hashes the
                // temp file in PHP; 'storage' forwards the digest to the backend so it
                // verifies the bytes it actually received. The latter is the only
                // check that can catch corruption introduced on the wire, and avoids
                // hashing every chunk twice when paired with the S3 driver.
                ->enumNode('checksum_verify')->values(['local', 'storage'])->defaultValue('local')->end()
                ->enumNode('storage')->values(['local', 's3'])->defaultValue('local')->end()
                ->arrayNode('local')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('base_directory')
                            ->defaultValue('%kernel.project_dir%/var/chunked-uploader/chunks')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('s3')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('bucket')->defaultValue('')->end()
                        ->scalarNode('prefix')->defaultValue('chunks/')->end()
                        // Key prefix for the object S3 assembles from the uploaded
                        // parts, relative to each upload's namespace.
                        ->scalarNode('final_prefix')->defaultValue('uploads/')->end()
                        ->arrayNode('config')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('version')->defaultValue('latest')->end()
                                ->scalarNode('region')->defaultValue('us-east-1')->end()
                                ->scalarNode('key')->defaultValue('')->end()
                                ->scalarNode('secret')->defaultValue('')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->enumNode('metadata')->values(['redis', 'pdo'])->defaultValue('redis')->end()
                ->arrayNode('redis')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('client')->defaultValue('phpredis')->end()
                        ->scalarNode('prefix')->defaultValue('chunked-uploader:')->end()
                        ->integerNode('ttl')->defaultValue(0)->end()
                        ->scalarNode('connection_service')->defaultValue('')->end()
                    ->end()
                ->end()
                ->arrayNode('pdo')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table')->defaultValue('chunked_upload_states')->end()
                        ->scalarNode('connection')->defaultValue('default')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
