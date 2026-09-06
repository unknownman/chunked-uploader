# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-05

### Added

#### Core engine
- Framework-agnostic `UploadManager` orchestrating the full chunked-upload lifecycle:
  token verification, chunk persistence, atomic progress, and final assembly.
- Immutable `Chunk` and `UploadState` data-transfer objects.
- `UploadManagerInterface` with `processChunk()` and `cancelUpload()`.

#### Streaming assembly
- `StreamAssembler` performing O(1)-memory stream-to-stream assembly via
  `stream_copy_to_stream()` with a fixed 4 MiB buffer.
- Automatic cleanup of partial output on failed assembly.
- Traversal-safe final filename handling.

#### Contracts & drivers
- `ChunkStorageInterface`, `MetadataRepositoryInterface`,
  `ProgressTrackerInterface`, `FileAssemblerInterface`,
  `ValidationRuleInterface`, `ChunkValidatorInterface`, and
  `VirusScannerInterface` contracts.
- `LocalChunkStorage` local filesystem chunk driver.
- `RedisProgressTracker` with optimistic-locking atomic updates.
- Stubs for `PdoMetadataRepository`, `RedisMetadataRepository`,
  `LocalStorageDriver`, and `S3StorageDriver`.

#### Security
- `PathSanitizer` rejecting null bytes, traversal markers, separators, and
  unsafe identifiers.
- `MagicByteValidator` header-only MIME detection via `finfo`.
- `UploadTokenService` and `UploadTokenManager` issuing and verifying
  HMAC-SHA256 tokens with constant-time `hash_equals()` comparison.
- `ClamAvScanner` INSTREAM-protocol scanner and `NullVirusScanner` fallback.
- `RedisRateLimiter` for per-key abuse protection.
- Validation rules: `MaxChunkSizeRule`, `MaxTotalSizeRule`, `MagicByteRule`,
  and `ExtensionMimeMatchRule`.

#### Lifecycle events
- `ChunkUploadedEvent`, `FileAssembledEvent`, and `UploadFailedEvent` with a
  PSR-compatible `EventDispatcherInterface`.

#### Framework bridges
- Laravel 10/11 service provider, facade, and publishable configuration.
- Symfony 6/7 bundle with dependency-injection extension and YAML configuration.

#### Examples & docs
- Dependency-free vanilla JavaScript client (`examples/vanilla-js/`) with
  exponential-backoff retry, pause/resume, and resume-from-status.
- Copy-pasteable Laravel and Symfony example controllers.
- Full `README.md`, Keep-a-Changelog compliant `CHANGELOG.md`, and MIT `LICENSE`.

#### Test suite
- PHPUnit 10/11-compatible unit and feature test suite (87 tests).
- vfsStream-compatible ephemeral temp-file disk I/O; fully offline.
- Coverage for byte-for-byte assembly, memory ceiling, traversal rejection,
  token tampering, out-of-order resumability, idempotent retries, and
  service-restart continuity.
