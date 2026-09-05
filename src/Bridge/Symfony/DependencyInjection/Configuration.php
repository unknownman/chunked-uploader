<?php

declare(strict_types=1);

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
            ->end();

        return $treeBuilder;
    }
}