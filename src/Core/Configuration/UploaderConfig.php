<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Configuration;

/**
 * Immutable, framework-agnostic configuration for the uploader's core engine.
 *
 * Framework bridges parse their host configuration files and map the values
 * onto this DTO, keeping the Core completely unaware of Laravel/Symfony.
 */
final readonly class UploaderConfig
{
    /**
     * @param int                  $maxChunkSize        Hard limit of a single chunk in bytes; enforced before processing
     * @param int                  $maxFileSize         Hard limit of the fully assembled file in bytes; enforced before processing
     * @param int                  $maxChunks           Hard limit of chunks per upload; DoS guard against chunk flooding
     * @param list<string>         $allowedMimeTypes    Whitelist of permitted MIME types; empty means any verified type
     * @param string               $spoolDirectory      Directory where chunk and final assembly files are spooled
     * @param int                  $garbageCollectionTtl Seconds an incomplete upload may linger before GC reclaims its chunks
     * @param string               $identifierPattern   POSIX ERE pattern identifiers must fully match
     */
    public function __construct(
        public int $maxChunkSize = 5 * 1024 * 1024,
        public int $maxFileSize = 100 * 1024 * 1024,
        public int $maxChunks = 1000,
        public array $allowedMimeTypes = [],
        public string $spoolDirectory = '/tmp/chunked-uploader',
        public int $garbageCollectionTtl = 3600,
        public string $identifierPattern = '/^[a-zA-Z0-9_-]{1,128}$/D',
    ) {
    }
}