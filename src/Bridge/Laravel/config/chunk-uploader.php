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
    | Assembly lock
    |--------------------------------------------------------------------------
    |
    | When the last chunks of a file arrive simultaneously on different servers,
    | every one of those nodes sees a complete upload and would otherwise run
    | the assembler -- producing two final objects, or an aborted multipart
    | upload. The uploader therefore takes a short distributed lock keyed on the
    | upload identifier and assembles once, while losers wait briefly for the
    | winner's result.
    |
    | The lock follows the "metadata" driver above and reuses its connection.
    | "ttl" bounds how long a node that dies mid-assembly can block the upload,
    | so keep it above your slowest realistic assembly time; the lock is not
    | renewed mid-assembly. "wait_seconds" is how long a losing request polls
    | for the winner's final path before returning the current status.
    |
    */

    'assembly_lock' => [
        'ttl' => (int) env('CHUNK_UPLOADER_ASSEMBLY_LOCK_TTL', 60),
        'wait_seconds' => (int) env('CHUNK_UPLOADER_ASSEMBLY_LOCK_WAIT', 10),
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

        /*
        | Key prefix for the object S3 assembles from the uploaded parts, relative
        | to each upload's namespace. Uploads use native S3 multipart transfers, so
        | the final key is resolved once and shared by the storage driver and the
        | assembler to guarantee they agree on the destination.
        |
        | Note: S3 requires every part except the last to be at least 5 MiB, so a
        | client chunk size below that will be rejected unless the upload is a
        | single part.
        */
        'final_prefix' => env('CHUNK_UPLOADER_S3_FINAL_PREFIX', 'uploads/'),

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
    | Checksum Verification
    |--------------------------------------------------------------------------
    |
    | Where a client-supplied chunk digest gets checked.
    | Supported: 'local' | 'storage'
    |
    | 'local'  - PHP re-reads the chunk off local disk and hashes it there.
    |            Correct for the local storage driver.
    | 'storage' - the digest is forwarded to the storage backend, which verifies
    |            the bytes it actually received and rejects the part itself.
    |
    | Set this to 'storage' when using the S3 driver. S3 validates the part
    | against the digest in the same request that stores it, which is the only
    | place a checksum can catch corruption introduced on the wire. Leaving it
    | at 'local' alongside S3 hashes every chunk twice: once in PHP against the
    | temp file, and again inside S3 against the part it received.
    |
    */

    'checksum_verify' => env('CHUNK_UPLOADER_CHECKSUM_VERIFY', 'local'),

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
