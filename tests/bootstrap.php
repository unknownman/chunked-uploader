<?php

declare(strict_types=1);

// File: tests/bootstrap.php

namespace {
    require __DIR__ . '/../vendor/autoload.php';

    /**
     * Absolute path under the storage directory.
     *
     * Defined here because the Laravel bridge's config file calls it, and this
     * package's test environment has illuminate/support but not a full Laravel
     * application to supply the helper. Without it the config file cannot be
     * loaded at all, which forces the bridge tests to assert on the provider's
     * source text instead of actually running `register()`.
     *
     * Every definition is guarded, so when the suite does run inside a real
     * application the framework's own implementation wins.
     */
    if (!function_exists('storage_path')) {
        function storage_path(string $path = ''): string
        {
            return rtrim(sys_get_temp_dir(), '/') . '/storage' . ($path === '' ? '' : '/' . $path);
        }
    }

    if (!function_exists('config_path')) {
        function config_path(string $path = ''): string
        {
            return rtrim(sys_get_temp_dir(), '/') . '/config' . ($path === '' ? '' : '/' . $path);
        }
    }

    if (!function_exists('app')) {
        /**
         * Resolves from the current container instance, as Laravel's own helper
         * does. Returning null instead would be worse than useless here: the
         * bridge calls `app('config')` in several places, and a silent null turns
         * those call sites into `Call to a member function get() on null` rather
         * than a test that exercises the real wiring.
         *
         * illuminate/support alone does not define this function -- it belongs to
         * the framework -- which is why the shim has to reproduce the behaviour
         * rather than merely stand in.
         *
         * @param array<string, mixed> $parameters
         */
        function app(?string $abstract = null, array $parameters = []): mixed
        {
            $container = \Illuminate\Container\Container::getInstance();

            if ($abstract === null) {
                return $container;
            }

            return $container->make($abstract, $parameters);
        }
    }

    if (!function_exists('config')) {
        function config(?string $key = null, mixed $default = null): mixed
        {
            return $default;
        }
    }

    if (!function_exists('env')) {
        /**
         * Reads an environment variable.
         *
         * The real implementation pulls in phpoption, which is not a dev
         * dependency of this package. The package's own config file only uses
         * `env()` to allow an override, so returning the default exercises the
         * same default path an application with no `.env` would take.
         */
        function env(string $key, mixed $default = null): mixed
        {
            $value = getenv($key);

            return $value === false ? $default : $value;
        }
    }
}
