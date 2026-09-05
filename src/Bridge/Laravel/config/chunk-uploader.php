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
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | Which StorageInterface concrete is bound into the container.
    | Supported: 'local' | 's3'
    |
    */

    'storage' => 'local',

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
];