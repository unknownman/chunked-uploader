# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- `RedisRateLimiter` now accepts either `ext-redis` (`Redis`) or
  `predis/predis` (`Predis\ClientInterface`) natively; the bespoke
  `RedisConnectionInterface` contract was removed.
- `UploadManager` accepts optional `RateLimiterInterface` and
  `maxChunkAttempts`/`rateLimitWindow`/`rateLimitKey`, and enforces the rate
  limit **before** chunk validation and persistence.
- `ClamAvScanner` gained a `timeout` argument (applied to both the connect and
  the socket read/write) and a more robust virus-name extraction regex.
- `PdoMetadataRepository` quoting is now dialect-aware (double quotes for
  PostgreSQL/SQL Server, backticks elsewhere) and the upsert branches by
  dialect (`excluded.` for SQLite, `EXCLUDED.` for PostgreSQL, `VALUES()` for
  MySQL/MariaDB).
- `S3ChunkStorage::getChunkStream()` validates the returned PSR-7 stream, and
  `StreamAssembler::unwrapStream()` rewinds native resources and PSR-7 streams
  via `GuzzleHttp\Psr7\StreamWrapper::getResource()`.

### Added
- `ChunkSecurityValidator` receives an optional `tokenSalt` for binding tokens
  to a client fingerprint.
- Laravel config and provider wiring for `token_salt`, `virus_scanning`
  (ClamAV host/port) and `rate_limiting` (Redis-backed), each toggled by
  matching `CHUNK_UPLOADER_*` environment variables.
- Symfony bundle configuration and DI wiring for the same features, including a
  new `redis.connection_service` (required when rate limiting is enabled).
- Tests for `RedisRateLimiter`, `ClamAvScanner` (socket-pair seam), the
  `UploadManager` rate-limit path, S3 streaming/assembly, the PDO dialect
  generator, and both framework bridges.

### Fixed
- Symfony `Configuration` `local` node now calls `addDefaultsIfNotSet()`,
  avoiding an array-offset-on-null when the config is empty.

## [1.0.0] - 2026-09-05

### Added

#### Core engine
- Framework-agnostic `UploadManager` as the sole coordinator of the
  chunked-upload lifecycle: token/integrity validation, chunk persistence,
  atomic progress, and streaming assembly, with a `GarbageCollector` for
  orphaned-chunk and expired-metadata reclamation.
- Immutable `Chunk` and `UploadState` data-transfer objects plus the
  `UploaderConfig` DTO parsed by each framework bridge.
- `UploadManagerInterface` with `processChunk()`, `cancelUpload()`, and
  `getStatus()`.

#### Streaming assembly
- `StreamAssembler` performing O(1)-memory stream-to-stream assembly via
  `stream_copy_to_stream()` with a fixed 4 MiB buffer.
- Transparent `unwrapStream()` adapter so both native PHP resources (local)
  and PSR-7 `StreamInterface` bodies (S3) feed the same copy loop.
- Automatic cleanup of partial output on failed assembly.
- Traversal-safe final filename handling.

#### Contracts & drivers
- `ChunkStorageInterface`, `MetadataRepositoryInterface`,
  `ProgressTrackerInterface`, `FileAssemblerInterface`,
  `ValidationRuleInterface`, `ChunkValidatorInterface`,
  `VirusScannerInterface`, `RateLimiterInterface`, and
  `EventDispatcherInterface` contracts.
- `LocalChunkStorage` and `S3ChunkStorage` chunk drivers, both implementing
  `cleanOrphanedChunks()`; S3 streams each chunk as an object and reads it back
  as a PSR-7 body.
- `RedisMetadataRepository` (ext-redis and predis) with atomic Lua-based
  `markChunkAsUploaded()` and `has()`; `PdoMetadataRepository` with transaction
  + row-lock correctness, JSON chunk columns, `ensureSchema()`, and both
  implement the `ProgressTrackerInterface`.

#### Security
- `PathSanitizer` rejecting null bytes, traversal markers, separators, and
  unsafe identifiers.
- `MagicByteValidator` header-only MIME detection via `finfo` (reads at most
  4096 bytes).
- `UploadTokenService` issuing and verifying HMAC-SHA256 tokens with
  constant-time `hash_equals()` comparison.
- `ClamAvScanner` INSTREAM-protocol scanner and `NullVirusScanner` fallback.
- `RedisRateLimiter` for per-key abuse protection.
- Validation rules: `MaxChunkSizeRule`, `MaxTotalSizeRule`, `MagicByteRule`,
  `ExtensionMimeMatchRule`, and a streaming-SHA-256 `ChecksumRule`, composed
  through the `ValidationPipeline` and `ChunkSecurityValidator`.

#### Lifecycle events
- `ChunkUploadedEvent`, `FileAssembledEvent`, and `UploadFailedEvent` with a
  framework-adaptive `EventDispatcherInterface`.

#### Framework bridges
- Laravel 10/11 service provider, facade, cleanup `Artisan` command, and
  publishable configuration.
- Symfony 6/7 bundle with configuration tree, dependency-injection extension,
  storage/metadata driver factories, cleanup console command, and `services.php`
  wiring.

#### Examples & docs
- Dependency-free vanilla JavaScript client (`examples/vanilla-js/`) with
  exponential-backoff retry, pause/resume, and resume-from-missing-chunks.
- Copy-pasteable Laravel and Symfony example controllers.
- Full `README.md`, Keep-a-Changelog compliant `CHANGELOG.md`, and MIT `LICENSE`.
- `phpcs.xml.dist` (PSR-12) and a `phpstan.neon` baseline.

#### Test suite
- PHPUnit 11-compatible unit and feature test suite (133 tests).
- Ephemeral temp-file disk I/O and in-memory storage/metadata doubles; fully
  offline with no Redis, S3, or ClamAV daemon dependency.
- Coverage for byte-for-byte assembly, memory ceiling, traversal rejection,
  token tampering, out-of-order resumability, idempotent retries,
  service-restart continuity, the Redis/PDO atomic state drivers, the garbage
  collector, the checksum rule, and both framework bridges.
