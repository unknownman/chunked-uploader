# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- `RedisRateLimiter` now accepts either `ext-redis` (`Redis`) or
  `predis/predis` (`Predis\ClientInterface`) natively; the bespoke
  `RedisConnectionInterface` contract was removed.
- `ChunkUploader` accepts optional `RateLimiterInterface` and
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
- Canonical `ChunkUploader` and `ChunkUploaderInterface` coordinator names.
- Optional PHP 8 `ChunkedUpload` attributes and
  `ChunkedUploadConfigResolver` for endpoint-specific limits, MIME allow-lists,
  and token salts.
- Optional `FlysystemChunkStorage` for Flysystem v3-compatible filesystem
  operators, including streamed reads/writes and incremental orphan cleanup.
- `ChunkSecurityValidator` receives an optional `tokenSalt` for binding tokens
  to a client fingerprint.
- Laravel config and provider wiring for `token_salt`, `virus_scanning`
  (ClamAV host/port) and `rate_limiting` (Redis-backed), each toggled by
  matching `CHUNK_UPLOADER_*` environment variables.
- Symfony bundle configuration and DI wiring for the same features, including a
  new `redis.connection_service` (required when rate limiting is enabled).
- Tests for `RedisRateLimiter`, `ClamAvScanner` (socket-pair seam), the
  `ChunkUploader` rate-limit path, S3 streaming/assembly + >1000-key batching,
  the PDO dialect generator + SQLite `BEGIN IMMEDIATE`, MIME charset stripping,
  `LocalChunkStorage`, and both framework bridges.

### Fixed
- `ChunkUploader` now rolls back the persisted chunk artifact when the metadata
  progress mutation fails, without masking the original failure if cleanup also
  fails.
- Laravel no longer resolves Redis or ClamAV services when their features are
  disabled, allowing the local/default configuration to boot without either
  optional dependency.
- Local cross-device chunk copies are published through a temporary sibling and
  atomic rename, preventing readers from observing partial chunk contents.
- Redis metadata cleanup now uses Predis's native cursor iterator and script
  responses validate JSON conversion explicitly.
- Stream assembly converts filesystem warnings into typed exceptions and always
  closes streams while removing partial output.
- Symfony `Configuration` `local` node now calls `addDefaultsIfNotSet()`,
  avoiding an array-offset-on-null when the config is empty.
- `S3ChunkStorage::deleteChunks()` and `cleanOrphanedChunks()` now batch a
  `DeleteObjects` request at 1000 keys (`array_chunk()`), preventing crashes for
  files with more than 1000 chunks.
- `LocalChunkStorage` purges upload directories with `FilesystemIterator`
  (removing hidden files too) and `store()` `touch()`es the upload directory so
  the garbage collector's TTL check stays accurate.
- `PdoMetadataRepository` issues `BEGIN IMMEDIATE` for SQLite so the write lock
  is acquired eagerly, avoiding deferred-lock "database is locked" concurrency
  errors; transaction begin/end/rollback is now a dialect-aware helper.
- `MagicByteValidator::detectMimeType()` strips any `; charset=...` suffix so an
  allow-list match is not rejected by a finfo-appended parameter.
- Laravel bridge reads optional `rate_limiting.` and `virus_scanning.` config
  keys with explicit defaults, so a partially published config cannot raise an
  undefined-array-key error.

## [1.0.0] - 2026-09-05

### Added

#### Core engine
- Framework-agnostic `ChunkUploader` as the sole coordinator of the
  chunked-upload lifecycle: token/integrity validation, chunk persistence,
  atomic progress, and streaming assembly, with a `GarbageCollector` for
  orphaned-chunk and expired-metadata reclamation.
- Immutable `Chunk` and `UploadState` data-transfer objects plus the
  `UploaderConfig` DTO parsed by each framework bridge.
- `ChunkUploaderInterface` with `processChunk()`, `cancelUpload()`, and
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
