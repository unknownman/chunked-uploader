<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/DependencyInjection/Configuration.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Declares and validates the bundle's configuration tree for Symfony.
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * Declares the bundle's configuration tree.
     *
     * Written as named statements rather than one long fluent chain. Symfony's
     * `NodeDefinition::end()` is declared to return a wide nullable union
     * (`NodeParentInterface|NodeBuilder|self|ArrayNodeDefinition|...|null`)
     * because it literally returns `$this->parent`. That is honest about the
     * runtime, but it means any node method called *after* an `end()` in a chain
     * is unresolvable to a static analyser, since the union's only useful member
     * is the empty `NodeParentInterface` marker. Holding each nested section in
     * a local keeps every call anchored to a real builder, which is both
     * statically checkable and easier to read than a 90-line chain.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('chunk_uploader');

        // TreeBuilder defaults its root to an ArrayNodeDefinition, the only node
        // type exposing children(). Both bindings are assertions about that fact
        // rather than about intent: without them the root resolves to its
        // abstract base type and the node methods below are rejected as undefined.
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        /** @var NodeBuilder $children */
        $children = $rootNode->children();

        $children->integerNode('max_chunk_size')->defaultValue(5 * 1024 * 1024)->end();
        $children->integerNode('max_file_size')->defaultValue(100 * 1024 * 1024)->end();
        $children->integerNode('max_chunks')->defaultValue(1000)->end();

        $mimeTypes = $children->arrayNode('allowed_mime_types');
        $mimeTypes->scalarPrototype()->end();
        $mimeTypes->defaultValue([]);

        $children->scalarNode('spool_directory')->defaultValue('%kernel.project_dir%/var/chunked-uploader')->end();
        $children->integerNode('garbage_collection_ttl')->defaultValue(3600)->end();
        $children->scalarNode('token_secret')->defaultValue('')->end();
        $children->scalarNode('token_salt')->defaultValue('')->end();

        $virusScanning = $children->arrayNode('virus_scanning');
        $virusScanning->addDefaultsIfNotSet();
        /** @var NodeBuilder $virusChildren */
        $virusChildren = $virusScanning->children();
        $virusChildren->booleanNode('enabled')->defaultFalse()->end();
        $virusChildren->scalarNode('host')->defaultValue('127.0.0.1')->end();
        $virusChildren->integerNode('port')->defaultValue(3310)->end();

        $rateLimiting = $children->arrayNode('rate_limiting');
        $rateLimiting->addDefaultsIfNotSet();
        /** @var NodeBuilder $rateLimitChildren */
        $rateLimitChildren = $rateLimiting->children();
        $rateLimitChildren->booleanNode('enabled')->defaultFalse()->end();
        $rateLimitChildren->integerNode('max_attempts')->defaultValue(100)->end();
        $rateLimitChildren->integerNode('decay_seconds')->defaultValue(60)->end();
        $rateLimitChildren->scalarNode('key')->defaultValue('chunked-uploader:chunks')->end();

        $assemblyLock = $children->arrayNode('assembly_lock');
        $assemblyLock->addDefaultsIfNotSet();
        $assemblyLock->info(
            'Serializes final file assembly so concurrent final chunks across nodes produce exactly one final '
            . 'object. The TTL is renewed every ttl/2 while an assembler can checkpoint, and is re-asserted after '
            . 'assembly, so a value above the slowest expected assembly is a safety margin rather than a hard limit.',
        );
        /** @var NodeBuilder $assemblyLockChildren */
        $assemblyLockChildren = $assemblyLock->children();
        $assemblyLockChildren->integerNode('ttl')->min(1)->defaultValue(60)->end();
        $assemblyLockChildren->integerNode('wait_seconds')->min(0)->defaultValue(10)->end();

        // Where a client-supplied digest is checked. 'local' re-hashes the
        // temp file in PHP; 'storage' forwards the digest to the backend so it
        // verifies the bytes it actually received. The latter is the only
        // check that can catch corruption introduced on the wire, and avoids
        // hashing every chunk twice when paired with the S3 driver.
        $children->enumNode('checksum_verify')->values(['local', 'storage'])->defaultValue('local')->end();
        $children->enumNode('storage')->values(['local', 's3'])->defaultValue('local')->end();

        $local = $children->arrayNode('local');
        $local->addDefaultsIfNotSet();
        /** @var NodeBuilder $localChildren */
        $localChildren = $local->children();
        $localChildren->scalarNode('base_directory')
            ->defaultValue('%kernel.project_dir%/var/chunked-uploader/chunks')
            ->end();

        $s3 = $children->arrayNode('s3');
        $s3->addDefaultsIfNotSet();
        /** @var NodeBuilder $s3Children */
        $s3Children = $s3->children();
        $s3Children->scalarNode('bucket')->defaultValue('')->end();
        $s3Children->scalarNode('prefix')->defaultValue('chunks/')->end();
        // Key prefix for the object S3 assembles from the uploaded
        // parts, relative to each upload's namespace.
        $s3Children->scalarNode('final_prefix')->defaultValue('uploads/')->end();

        $s3Config = $s3Children->arrayNode('config');
        $s3Config->addDefaultsIfNotSet();
        /** @var NodeBuilder $s3ConfigChildren */
        $s3ConfigChildren = $s3Config->children();
        $s3ConfigChildren->scalarNode('version')->defaultValue('latest')->end();
        $s3ConfigChildren->scalarNode('region')->defaultValue('us-east-1')->end();
        $s3ConfigChildren->scalarNode('key')->defaultValue('')->end();
        $s3ConfigChildren->scalarNode('secret')->defaultValue('')->end();

        $children->enumNode('metadata')->values(['redis', 'pdo'])->defaultValue('redis')->end();

        $redis = $children->arrayNode('redis');
        $redis->addDefaultsIfNotSet();
        /** @var NodeBuilder $redisChildren */
        $redisChildren = $redis->children();
        $redisChildren->scalarNode('client')->defaultValue('phpredis')->end();
        $redisChildren->scalarNode('prefix')->defaultValue('chunked-uploader:')->end();
        $redisChildren->integerNode('ttl')->defaultValue(0)->end();
        $redisChildren->scalarNode('connection_service')->defaultValue('')->end();

        $pdo = $children->arrayNode('pdo');
        $pdo->addDefaultsIfNotSet();
        /** @var NodeBuilder $pdoChildren */
        $pdoChildren = $pdo->children();
        $pdoChildren->scalarNode('table')->defaultValue('chunked_upload_states')->end();
        $pdoChildren->scalarNode('connection')->defaultValue('default')->end();

        return $treeBuilder;
    }
}
