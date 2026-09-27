# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-09-27

### Added

#### Native S3 multipart assembly
- `S3ChunkStorage` opens a `CreateMultipartUpload` on the first chunk of an
  upload, sends each chunk as an `UploadPart` keyed by `index + 1`, and
  `S3MultipartAssembler` finishes with a single `CompleteMultipartUpload`. The
  object store performs the concatenation, so the PHP server no longer
  downloads or re-streams the assembled object: per-chunk memory and disk use is
  bounded by one chunk and is independent of total file size.
- The upload id and each part's ETag are persisted in the metadata repository,
  which is what allows an upload to resume across requests and web nodes.
  `S3ChunkStorage` and `S3MultipartAssembler` resolve the destination key from
  one place, so the driver and the assembler cannot disagree about where the
  final object lands.
- `S3ChunkStorage` rejects an invalid multipart shape up front with an
  actionable message: a non-final part below S3's 5 MiB minimum, or more than
  10,000 parts. The JavaScript client's default chunk size is 2 MiB, which is
  below that minimum, so **an S3 deployment must raise `chunkSize` on the client
  (8 MiB is a reasonable default)** or every multi-chunk upload fails.

#### Distributed assembly lock
- `Core\Contracts\LockManagerInterface` guards the assemble-and-publish
  critical section in `ChunkUploader::tryAssembleAndFinalize()` against
  concurrent final chunks arriving on different servers at the same moment.
- `RedisLockManager` acquires with an atomic `SET key token NX PX ttl` and
  releases through a token-validated Lua script, so a node whose lease lapsed
  can neither free nor extend a successor's lock.
- `PdoLockManager` uses a dedicated locks table whose `lock_key` primary key
  *is* the lost-race signal, so there is no `SELECT`-then-`INSERT` window, and it
  provisions its own table when absent.
- `LockManagerInterface::renew()` extends a held lease in a single atomic,
  owner-verified step: a Lua compare-and-`PEXPIRE` script for Redis, a
  token-conditional `UPDATE` for PDO.
- `Core\Locking\AssemblyHeartbeat` renews once `assemblyLockTtl / 2` has
  elapsed since the last *successful* renewal and latches if a renewal fails.
  `Core\Contracts\HeartbeatAwareAssemblerInterface` lets an assembler opt in;
  `StreamAssembler` ticks once per part, giving a large assembly hundreds of
  renewal opportunities instead of a fixed lease that expires underneath it.
- `ChunkUploader` re-checks ownership after assembly returns and throws
  `AssemblyLeaseLostException` before publishing `finalPath`, deleting chunks,
  or dispatching `FileAssembledEvent`. A lease that expired with no competing
  owner is re-acquired and finalizes normally, so only a genuine split-brain
  aborts the upload.

#### End-to-end checksums
- The JavaScript client computes a per-chunk digest (`checksum`, default
  `sha256`) and sends it as the `checksum` field. `crypto.subtle` has no MD5, so
  `md5` requires an injected `digest` hook returning raw bytes; the client owns
  the base64 encoding. The field is omitted when `crypto.subtle` is unavailable
  (non-secure context) rather than failing the upload.
- `S3ChunkStorage` forwards the digest to S3 as `ChecksumSHA256` or
  `ContentMD5`, so the object store hashes the part it actually received.
  `BadDigest`, `XAmzContentSHA256Mismatch`, and `InvalidDigest` are translated
  to typed exceptions, distinguishing a corrupt-in-transit part (retrying helps)
  from a malformed digest (retrying cannot help).
- `checksum_verify` config option (`local` | `storage`, default `local`) for
  both bridges, selecting whether `ChecksumRule` re-hashes the temp file. Set it
  to `storage` with the S3 driver: S3 validates the part in the same request
  that stores it, and leaving it on `local` hashes every chunk twice for no
  extra coverage.

#### Telemetry
- `Core\Contracts\MetricsTrackerInterface` reports accepted bytes, checksum
  mismatches, lock collisions, lost leases, and assembly duration. Both bridges
  bind `NullMetricsTracker` by default; an application opts in by rebinding the
  interface, so there is no configuration flag to discover and nothing to unset.

#### Contracts, drivers, and bridges
- `Core\Security\ChunkChecksum` normalises a client digest from either hex or
  base64 to raw bytes, so digests from browsers, PHP, and shell tools agree.
- `ChecksumMismatchException` distinguishes a digest rejection from an ordinary
  storage failure, so a client is told that corrupt bytes will not become valid
  on retry. Local mismatches report `local-mismatch`; S3 reports the underlying
  error code.
- `LockManagerInterface`, `MetricsTrackerInterface`,
  `HeartbeatAwareAssemblerInterface`, and `AssemblyLeaseLostException`, plus a
  `NullMetricsTracker` no-op driver.
- Optional `FlysystemChunkStorage` for Flysystem v3-compatible filesystem
  operators, including streamed reads/writes and incremental orphan cleanup.
- Optional PHP 8 `ChunkedUpload` attributes and `ChunkedUploadConfigResolver`
  for endpoint-specific limits, MIME allow-lists, and token salts.
- Optional `VirusScannerInterface` ClamAV streaming scanning, and a Redis-backed
  `RateLimiterInterface` applied before chunk validation and persistence.
- Laravel config and provider wiring for `token_salt`, `virus_scanning`,
  `rate_limiting`, `checksum_verify`, and `assembly_lock`, each toggled by a
  matching `CHUNK_UPLOADER_*` environment variable. Symfony bundle
  configuration and DI wiring for the same, including
  `redis.connection_service` (required when rate limiting is enabled).
- `ValidationPipeline` ignores `null` entries, so a container can express an
  optional rule without duplicating the rule list.
- Tests for the native multipart lifecycle (part-number mapping, ETag manifest,
  abort/reap paths, >1000-key batching), the Redis Lua and PDO token-conditional
  renewal contract, the heartbeat and split-brain guard, both framework bridge
  bindings, `RedisRateLimiter`, `ClamAvScanner` (socket-pair seam), the
  `PdoMetadataRepository` dialect generator, and `LocalChunkStorage`.

### Changed
- `ChecksumRule` now compares decoded digest bytes instead of wire strings.
  This is a behaviour change: a caller that previously relied on a hex digest
  being compared literally against a base64 one now gets the correct result.
- `RedisRateLimiter` accepts either `ext-redis` (`Redis`) or
  `predis/predis` (`Predis\ClientInterface`) natively; the bespoke
  `RedisConnectionInterface` contract was removed.
- `ChunkUploader` accepts optional `RateLimiterInterface`,
  `maxChunkAttempts`/`rateLimitWindow`/`rateLimitKey`, and `LockManagerInterface`
  / `MetricsTrackerInterface`, and enforces the rate limit **before** chunk
  validation and persistence.
- `S3MultipartAssembler` deliberately does **not** implement
  `HeartbeatAwareAssemblerInterface`. Its single `CompleteMultipartUpload` call
  has no checkpoint inside it, and PHP cannot renew a lease across a blocking
  call it does not control, so claiming the capability would imply a guard that
  provably cannot run. Its critical section must be sized to fit the TTL.
- `ChunkUploader` accepts an optional `clock` for the heartbeat, letting tests
  drive renewal timing deterministically instead of sleeping.
- `ClamAvScanner` gained a `timeout` argument (applied to both the connect and
  the socket read/write) and a more robust virus-name extraction regex.
- `PdoMetadataRepository` quoting is dialect-aware (double quotes for
  PostgreSQL/SQL Server, backticks elsewhere) and the upsert branches by dialect
  (`excluded.` for SQLite, `EXCLUDED.` for PostgreSQL, `VALUES()` for
  MySQL/MariaDB). SQLite issues `BEGIN IMMEDIATE` so the write lock is acquired
  eagerly, avoiding deferred-lock "database is locked" errors.
- `S3ChunkStorage::getChunkStream()` validates the returned PSR-7 stream, and
  `StreamAssembler::unwrapStream()` rewinds native resources and PSR-7 streams
  via `GuzzleHttp\Psr7\StreamWrapper::getResource()`.

### Fixed
- **Browser digests were rejected outright.** `ChecksumRule` compared a hex
  digest against whatever the client sent, so every base64 digest failed the
  `hash_equals()` check 100% of the time -- i.e. every browser upload with
  checksums enabled. `ChunkChecksum` now decodes hex or base64 to raw bytes
  first. Note that this is an encoding fix, not a case-folding one: base64 is
  case-*sensitive*, so comparing the encoded strings case-insensitively would
  not have been correct.
- **A resumed upload could not share its in-flight promise.** The JavaScript
  client tracked one promise in `activeRun` while returning a different one to
  the caller, so repeated `resume()` calls handed out different promises for
  the same run. `activeRun` is now the very promise returned to the caller, and
  concurrent callers share it.
- **The Laravel validator binding threw on resolution.** The
  `ChunkValidatorInterface` singleton was registered as a `static` closure while
  its body reaches `$this->checksumRule()`, so resolving it failed with a
  closure-scope error. It is now a non-static closure.
- `ChunkUploader` rolls back the persisted chunk artifact when the metadata
  progress mutation fails, without masking the original failure if cleanup also
  fails.
- Laravel no longer resolves Redis or ClamAV services when their features are
  disabled, allowing the local/default configuration to boot without either
  optional dependency, and reads optional `rate_limiting.`/`virus_scanning.`
  keys with explicit defaults so a partially published config cannot raise an
  undefined-array-key error.
- Local cross-device chunk copies are published through a temporary sibling and
  atomic rename, preventing readers from observing partial chunk contents.
- `S3ChunkStorage::deleteChunks()` and `cleanOrphanedChunks()` batch a
  `DeleteObjects` request at 1000 keys, preventing failures for files with more
  than 1000 chunks.
- Redis metadata cleanup uses Predis's native cursor iterator, and script
  responses validate JSON conversion explicitly.
- Stream assembly converts filesystem warnings into typed exceptions and always
  closes streams while removing partial output.
- `LocalChunkStorage` purges upload directories with `FilesystemIterator`
  (removing hidden files too) and `touch()`es the upload directory so the
  garbage collector's TTL check stays accurate.
- `MagicByteValidator::detectMimeType()` strips any `; charset=...` suffix so an
  allow-list match is not rejected by a finfo-appended parameter.
- Symfony `Configuration`'s `local` node calls `addDefaultsIfNotSet()`, avoiding
  an array-offset-on-null when the config is empty.

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
