<?php

declare(strict_types=1);

// File: phpstan-bootstrap.php
//
// PHPStan bootstrap that provides minimal signatures for the Laravel bridge
// helpers and facade classes, which are not present in this standalone package
// dev environment but exist at runtime inside a real Laravel application.

namespace {
    /**
     * Resolve a service from the Laravel container.
     *
     * @param string|class-string|null $abstract
     */
    function app(string $abstract = null, array $parameters = []): mixed
    {
    }

    /**
     * Read a config value.
     */
    function config(string $key = null, mixed $default = null): mixed
    {
    }

    /**
     * Absolute path to a file under the storage directory.
     */
    function storage_path(string $path = ''): string
    {
    }

    /**
     * Absolute path to a file under the config directory.
     */
    function config_path(string $path = ''): string
    {
    }
}

namespace Illuminate\Support\Facades {
    use Illuminate\Database\Connection as DatabaseConnection;
    use Illuminate\Redis\Connections\Connection as RedisConnection;

    class DB
    {
        public static function connection(string $name = null): DatabaseConnection
        {
        }
    }

    class Redis
    {
        public static function connection(string $name = null): RedisConnection
        {
        }
    }
}

namespace Illuminate\Database {
    class Connection
    {
        public function getPdo(): \PDO
        {
        }
    }
}

namespace Illuminate\Redis\Connections {
    class Connection
    {
        public function client(): mixed
        {
        }
    }
}
