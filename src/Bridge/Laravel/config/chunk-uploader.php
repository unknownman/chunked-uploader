<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Chunk Size
    |--------------------------------------------------------------------------
    |
    | Hard limit of a single chunk in bytes. Enforced server-side before any
    | chunk is processed. Default: 5 MB.
    |
    */

    'max_chunk_size' => 5 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Maximum File Size
    |--------------------------------------------------------------------------
    |
    | Hard limit of the fully assembled file in bytes. Enforced server-side
    | before processing begins. Default: 100 MB.
    |
    */

    'max_file_size' => 100 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Maximum Chunk Count
    |--------------------------------------------------------------------------
    |
    | Hard limit of chunks per upload. Guards against chunk-flood denial of
    | service. Default: 1000 chunks.
    |
    */

    'max_chunks' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    |
    | Whitelist of MIME types permitted for uploads, verified from file magic
    | bytes (never the client header). An empty list permits every type whose
    | magic bytes can be verified.
    |
    */

    'allowed_mime_types' => [],

    /*
    |--------------------------------------------------------------------------
    | Spool Directory
    |--------------------------------------------------------------------------
    |
    | Absolute path where chunk artifacts and final assemblies are spooled.
    |
    */

    'spool_directory' => storage_path('app/chunked-uploader'),

    /*
    |--------------------------------------------------------------------------
    | Garbage Collection TTL
    |--------------------------------------------------------------------------
    |
    | Seconds an incomplete upload may linger before its orphaned chunks are
    | collected and its state expired.
    |
    */

    'garbage_collection_ttl' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Token Secret
    |--------------------------------------------------------------------------
    |
    | HMAC secret used to sign upload tokens. Override with a long random value
    | in production. Falls back to the application key when unset.
    |
    | token_salt optionally binds each token to a client fingerprint (for
    | example the client IP). Enabling it breaks uploads across roaming networks,
    | so leave empty unless the stricter binding is required.
    |
    */

    'token_secret' => env('CHUNK_UPLOADER_TOKEN_SECRET', env('APP_KEY', '')),
    'token_salt' => env('CHUNK_UPLOADER_TOKEN_SALT', ''),

    /*
    |--------------------------------------------------------------------------
    | ClamAV Virus Scanning
    |--------------------------------------------------------------------------
    |
    | Streaming malware detection for assembled chunks via the ClamAV INSTREAM
    | protocol. Disabled by default (no scan is performed). When enabled,
    | `host` and `port` are combined into a `tcp://host:port` daemon endpoint;
    | any infection rejects the chunk with a VirusDetectedException.
    |
    */

    'virus_scanning' => [
        'enabled' => env('CHUNK_UPLOADER_VIRUS_SCANNING', false),
        'host' => env('CHUNK_UPLOADER_CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CHUNK_UPLOADER_CLAMAV_PORT', 3310),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunk Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Fixed-window flood protection against excessive chunk submissions from a
    | single client. When enabled, every inbound chunk is counted against the
    | shared Redis-backed limiter key; after `max_attempts` chunks within
    | `decay_seconds` the request is rejected before validation or disk I/O.
    | Requires the Redis metadata driver to share its connection.
    |
    */

    'rate_limiting' => [
        'enabled' => env('CHUNK_UPLOADER_RATE_LIMITING', false),
        'max_attempts' => (int) env('CHUNK_UPLOADER_RATE_LIMIT_MAX', 100),
        'decay_seconds' => (int) env('CHUNK_UPLOADER_RATE_LIMIT_WINDOW', 60),
        'key' => env('CHUNK_UPLOADER_RATE_LIMIT_KEY', 'chunked-uploader:chunks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | Which ChunkStorageInterface concrete is bound into the container.
    | Supported: 'local' | 's3'
    |
    */

    'storage' => 'local',

    'local' => [
        'base_directory' => env('CHUNK_UPLOADER_LOCAL_BASE', storage_path('app/chunked-uploader/chunks')),
    ],

    's3' => [
        'bucket' => env('CHUNK_UPLOADER_S3_BUCKET', ''),
        'prefix' => env('CHUNK_UPLOADER_S3_PREFIX', 'chunks/'),
        'config' => [
            'version' => env('AWS_VERSION', 'latest'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID', ''),
                'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata Driver
    |--------------------------------------------------------------------------
    |
    | Which MetadataRepositoryInterface concrete is bound into the container.
    | Supported: 'redis' | 'pdo'
    |
    */

    'metadata' => 'redis',

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'prefix' => env('CHUNK_UPLOADER_REDIS_PREFIX', 'chunked-uploader:'),
        'ttl' => (int) env('CHUNK_UPLOADER_REDIS_TTL', 0),
        'connection' => env('REDIS_CONNECTION', 'default'),
    ],

    'pdo' => [
        'connection' => env('DB_CONNECTION', 'mysql'),
        'table' => env('CHUNK_UPLOADER_PDO_TABLE', 'chunked_upload_states'),
    ],
];
