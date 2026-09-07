<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/DependencyInjection/MetadataDriverFactory.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\DependencyInjection;

use PDO;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Drivers\Metadata\PdoMetadataRepository;
use Resumable\ChunkedUploader\Core\Drivers\Metadata\RedisMetadataRepository;

/**
 * Builds the configured metadata repository concrete from the bundle
 * parameters, and re-exposes it as a progress tracker when supported.
 */
final class MetadataDriverFactory
{
    /**
     * @param \Redis|\Predis\ClientInterface|null $redisClient
     */
    public function __construct(
        private readonly string $driver,
        private readonly mixed $redisClient,
        private readonly string $redisPrefix,
        private readonly ?int $redisTtl,
        private readonly ?PDO $pdo,
        private readonly string $pdoTable,
    ) {
    }

    public function create(): MetadataRepositoryInterface
    {
        if ($this->driver === 'pdo') {
            if ($this->pdo === null) {
                throw new \RuntimeException('The PDO metadata driver requires a configured PDO connection.');
            }

            $repository = new PdoMetadataRepository(
                pdo: $this->pdo,
                tableName: $this->pdoTable,
            );
            $repository->ensureSchema();

            return $repository;
        }

        if ($this->redisClient === null) {
            throw new \RuntimeException('The Redis metadata driver requires a configured Redis client.');
        }

        return new RedisMetadataRepository(
            redis: $this->redisClient,
            keyPrefix: $this->redisPrefix,
            ttl: $this->redisTtl,
        );
    }

    public function createTracker(): ProgressTrackerInterface
    {
        $repository = $this->create();
        if (!$repository instanceof ProgressTrackerInterface) {
            throw new \RuntimeException('Configured metadata repository must also implement ProgressTrackerInterface.');
        }

        return $repository;
    }
}
