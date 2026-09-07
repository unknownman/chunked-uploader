<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/DependencyInjection/ChunkUploaderExtension.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Resumable\ChunkedUploader\Core\Contracts\RateLimiterInterface;
use Resumable\ChunkedUploader\Core\Contracts\VirusScannerInterface;
use Resumable\ChunkedUploader\Core\Security\RateLimiting\RedisRateLimiter;
use Resumable\ChunkedUploader\Core\Security\Scanners\ClamAvScanner;
use Resumable\ChunkedUploader\Core\Security\Scanners\NullVirusScanner;

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

        $this->wireMetadataConnection($config, $container);

        $this->registerSecurityServices($config, $container);

        $parameters = [
            'chunk_uploader.max_chunk_size' => $config['max_chunk_size'],
            'chunk_uploader.max_file_size' => $config['max_file_size'],
            'chunk_uploader.max_chunks' => $config['max_chunks'],
            'chunk_uploader.allowed_mime_types' => $config['allowed_mime_types'],
            'chunk_uploader.spool_directory' => $config['spool_directory'],
            'chunk_uploader.garbage_collection_ttl' => $config['garbage_collection_ttl'],
            'chunk_uploader.token_secret' => $config['token_secret'],
            'chunk_uploader.token_salt' => $config['token_salt'],
            'chunk_uploader.virus_scanning.enabled' => $config['virus_scanning']['enabled'],
            'chunk_uploader.virus_scanning.host' => $config['virus_scanning']['host'],
            'chunk_uploader.virus_scanning.port' => $config['virus_scanning']['port'],
            'chunk_uploader.rate_limiting.enabled' => $config['rate_limiting']['enabled'],
            'chunk_uploader.rate_limiting.max_attempts' => $config['rate_limiting']['max_attempts'],
            'chunk_uploader.rate_limiting.decay_seconds' => $config['rate_limiting']['decay_seconds'],
            'chunk_uploader.rate_limiting.key' => $config['rate_limiting']['key'],
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
            'chunk_uploader.redis.connection_service' => $config['redis']['connection_service'],
            'chunk_uploader.pdo.table' => $config['pdo']['table'],
            'chunk_uploader.pdo.connection' => $config['pdo']['connection'],
        ];

        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
        }
    }

    /**
     * Binds the optional security services based on the processed config.
     *
     * The virus scanner is a no-op unless ClamAV scanning is enabled; the rate
     * limiter is only registered when flood protection is requested and must be
     * pointed at an existing Redis connection service.
     *
     * @param array<string, mixed> $config
     */
    private function registerSecurityServices(array $config, ContainerBuilder $container): void
    {
        $virus = $config['virus_scanning'];
        if ($virus['enabled']) {
            $container->register(VirusScannerInterface::class, ClamAvScanner::class)
                ->setArgument('$endpoint', sprintf(
                    'tcp://%s:%d',
                    (string) $virus['host'],
                    (int) $virus['port'],
                ));
        } else {
            $container->register(VirusScannerInterface::class, NullVirusScanner::class);
        }

        $rate = $config['rate_limiting'];
        if (!$rate['enabled']) {
            return;
        }

        $connectionService = (string) $config['redis']['connection_service'];
        if ($connectionService === '') {
            throw new \RuntimeException(
                'chunk_uploader.rate_limiting.enabled requires setting chunk_uploader.redis.connection_service '
                . 'to the id of your \Redis or Predis\\Client service.',
            );
        }

        $container->register(RateLimiterInterface::class, RedisRateLimiter::class)
            ->setArgument('$redis', new Reference($connectionService))
            ->setArgument('$prefix', 'rate-limit:');
    }

    /**
     * Injects the configured Redis or PDO/DBAL service into the metadata
     * factory. Empty Redis service IDs remain lazy and fail with a clear error
     * only when the Redis driver is actually resolved.
     *
     * @param array<string, mixed> $config
     */
    private function wireMetadataConnection(array $config, ContainerBuilder $container): void
    {
        $definition = $container->getDefinition(MetadataDriverFactory::class);
        $metadataDriver = (string) $config['metadata'];

        if ($metadataDriver === 'redis') {
            $serviceId = (string) $config['redis']['connection_service'];
            if ($serviceId !== '') {
                $definition->setArgument('$redisClient', new Reference($serviceId));
            }
            return;
        }

        $serviceId = (string) $config['pdo']['connection'];
        if ($serviceId !== '') {
            $definition->setArgument('$pdo', new Reference($serviceId));
        }
    }
}
