![Resumable Chunked Uploader](docs/banner.svg)

<!--
    RESUMABLE CHUNKED UPLOADER
    ==========================
    Stream. Resume. Assemble.
-->

# Resumable Chunked Uploader

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)](https://www.php.net/releases/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-10%2B-green.svg)](https://phpunit.de/)

Enterprise-grade, framework-agnostic PHP 8.2 library for handling large file
uploads via **chunking** with **constant-memory stream assembly**, **resume
capability**, and **cryptographic integrity checks**.

Chunks are persisted independently, progress is kept in a durable store
(Redis or a relational database), and the final file is assembled using native
PHP streams — never buffering the whole file into memory, regardless of how
large it is.

---

## Table of contents

- [Highlights](#highlights)
- [Architecture](#architecture)
- [Core benefits](#core-benefits)
- [Installation](#installation)
- [Standalone PHP quickstart](#standalone-php-quickstart)
- [Framework integration](#framework-integration)
  - [Laravel 10 / 11](#laravel-10--11)
  - [Symfony 6 / 7](#symfony-6--7)
- [Vanilla JavaScript client](#vanilla-javascript-client)
- [Security model](#security-model)
- [Concurrent assembly (distributed locking)](#concurrent-assembly-distributed-locking)
- [End-to-end chunk checksums](#end-to-end-chunk-checksums)
- [API reference](#api-reference)
- [Configuration options](#configuration-options)
- [Testing](#testing)
- [License](#license)

---

## Highlights

- **O(1) memory** chunk assembly via `fopen()` + `stream_copy_to_stream()`.
- **Resume support** for out-of-order chunks and interrupted requests.
- **Atomic** progress updates in Redis (optimistic locking).
- **HMAC tokens** bound to upload metadata and client context.
- **Magic-byte MIME validation** (only reads the first 4096 bytes).
- **Strict path sanitization** against directory traversal.
- **Optional** ClamAV scanning and Redis rate limiting.
- **Atomic failure cleanup** removes a stored chunk when its metadata update
    fails, so transient database errors do not create permanent orphan bytes.
- **Bounded cleanup** scans local directories, S3 pages, Redis cursors, and PDO
    rows incrementally, including S3 deletion batches larger than 1,000 objects.
    The S3 sweeper also aborts stale multipart uploads, whose parts are invisible
    to a normal object listing and would otherwise keep accruing storage cost.
- **`ChunkUploader`** is the single canonical coordinator.
- **Optional PHP 8 attributes** provide endpoint-specific immutable config
    overrides without changing global defaults.
- **Flysystem v3** storage is available as an optional driver.
- **Zero framework dependency** in the core package.
- **Laravel** and **Symfony** bridges + a **dependency-free vanilla JS** client.

---

## Architecture

```mermaid
flowchart LR
    subgraph Client
        JS[Vanilla JS / Fetch API]
    end

    subgraph Server
        M[ChunkUploader]
        V[ValidationPipeline]
        S[ChunkStorageInterface]
        MD[MetadataRepositoryInterface]
        PT[ProgressTrackerInterface]
        A[FileAssemblerInterface]
        TS[UploadTokenService]
    end

    JS -->|POST /upload token| TS
    JS -->|POST /upload chunks| M
    M --> V
    M --> S
    M --> MD
    M --> PT
    M --> A
    A --> F[Final file]
    JS -->|GET /upload/status| MD
```

Data flow for a single chunk:

```mermaid
sequenceDiagram
    participant C as Client
    participant M as ChunkUploader
    participant TS as ChunkSecurityValidator
    participant MD as MetadataRepo
    participant S as ChunkStorage
    participant A as Assembler

    C->>M: processChunk(Chunk{index, token, ...})
    M->>TS: validate(chunk)
    TS-->>M: valid
    M->>MD: get(identifier)
    MD-->>M: UploadState|null
    M->>S: store(chunk)
    M->>MD: markChunkAsUploaded(identifier, index)
    MD-->>M: UploadState (updated)
    alt isComplete(state)
        M->>A: assemble(state, storage)
        A->>S: getChunkStream(index 0..N-1)
        S-->>A: stream
        A-->>M: finalPath
        M->>S: deleteChunks(identifier)
    end
    M-->>C: UploadState
```

---

## Core benefits

| Benefit | How it works |
| --- | --- |
| **Low memory** | Assembly streams chunk-by-chunk through a fixed 4 MiB buffer. A 10 GB file needs under 10 MiB of PHP memory. |
| **Resumable** | Progress persists per-chunk. Any client can query missing indices and resume exactly where it stopped. |
| **Idempotent** | Re-sending an already-accepted chunk is a no-op — no duplicated bytes, no corruption. |
| **Secure** | Magic-byte MIME checks, HMAC token verification, traversal-safe paths, and optional ClamAV scanning. |
| **Out-of-order safe** | Chunks may arrive in any order; assembly only runs when every index is present. |

---

## Installation

```bash
composer require resumable/chunked-uploader
```

Requirements:

- PHP **^8.2**
- `ext-json`
- One of `ext-redis` **or** `predis/predis` (for the Redis metadata driver)
- `ext-pdo` (for the PDO metadata driver)
- `aws/aws-sdk-php` (for the S3 storage driver)

---

## Standalone PHP quickstart

```php
<?php

declare(strict_types=1);

use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\Drivers\Metadata\RedisMetadataRepository;
use Resumable\ChunkedUploader\Core\Drivers\Storage\LocalChunkStorage;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Core\ChunkUploader;
use Resumable\ChunkedUploader\Core\Validation\ChunkSecurityValidator;
use Resumable\ChunkedUploader\Core\Validation\ValidationPipeline;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkUploaderException;

// 1. Wire the infrastructure.
$storage = new LocalChunkStorage('/var/lib/my-app/chunks', new PathSanitizer());
$metadata = new RedisMetadataRepository(new \Redis(['host' => '127.0.0.1']));

$assembler = new StreamAssembler('/var/lib/my-app/final');
$validator = new ChunkSecurityValidator(
    sanitizer: new PathSanitizer(),
    pipeline: new ValidationPipeline([]),
    tokenService: new UploadTokenService($_ENV['UPLOAD_TOKEN_SECRET']),
);

// 2. Create the manager once, inject it into your request handler.
$manager = new ChunkUploader(
    storage: $storage,
    metadata: $metadata,
    progress: $metadata,
    assembler: $assembler,
    validator: $validator,
    dispatcher: new class implements EventDispatcherInterface {
        public function dispatch(object $event): object { return $event; }
    },
);

// 3. Issue a token bound to the upload signature and client context.
$token = (new UploadTokenService($_ENV['UPLOAD_TOKEN_SECRET']))
    ->createToken('upload_123', 4, 20_000_000, 'client-context');

// 4. For each incoming chunk, build a Chunk DTO and process it.
try {
    $state = $manager->processChunk(new Chunk(
        identifier: 'upload_123',
        token: $token,
        index: 0,
        totalChunks: 4,
        chunkSize: filesize($tempFile), // actual byte size of this chunk
        totalSize: 20_000_000,          // expected total size
        tmpFilePath: $tempFile,         // from PHP's upload temp dir
        originalFilename: 'archive.zip',
    ));
} catch (ChunkUploaderException $e) {
    // validation / storage / assembly failure
}

// The manager returns the latest upload state; when isCompleted is true the
// final file has been assembled and temp chunks deleted.
if ($state->isCompleted) {
    // finalPath points to the assembled artifact
    echo $state->finalPath;
}
```

Create one `Chunk` per HTTP request. `index` is zero-based. Retrying an
already-accepted index is safe and idempotent.

---

## Framework integration

### Laravel 10 / 11

**1. Register the service provider**

In `bootstrap/providers.php` (Laravel 11) or `config/app.php` (Laravel 10):

```php
// Laravel 11: bootstrap/providers.php
return [
    Resumable\ChunkedUploader\Bridge\Laravel\Providers\ChunkUploaderServiceProvider::class,
];

// Laravel 10: config/app.php
'providers' => [
    Resumable\ChunkedUploader\Bridge\Laravel\Providers\ChunkUploaderServiceProvider::class,
],
```

**2. Publish configuration (optional)**

```bash
php artisan vendor:publish --provider="Resumable\ChunkedUploader\Bridge\Laravel\Providers\ChunkUploaderServiceProvider"
```

Then adjust `config/chunk-uploader.php` (allowed MIME types, spool directory,
storage driver, etc.).

New in this version: `token_salt` (binds tokens to a client fingerprint),
`virus_scanning` (ClamAV host/port), and `rate_limiting` (Redis-backed chunk
flood protection) — all with matching `.env` variable names (see the config
file for the full list).

**3. Use the Facade**

```php
use Resumable\ChunkedUploader\Bridge\Laravel\Facades\ChunkUploader;

$state = ChunkUploader::processChunk($chunk);
```

**4. Add routes (see the example controller)**

```php
Route::post('/upload/token',  [ChunkUploadController::class, 'issueToken']);
Route::post('/upload',        [ChunkUploadController::class, 'store']);
Route::get('/upload/status/{identifier}', [ChunkUploadController::class, 'status']);
```

A complete, copy-pasteable controller lives at
[`examples/laravel/ChunkUploadController.php`](examples/laravel/ChunkUploadController.php).

---

### Symfony 6 / 7

**1. Enable the bundle**

```php
// config/bundles.php
return [
    Resumable\ChunkedUploader\Bridge\Symfony\ChunkUploaderBundle::class => ['all' => true],
];
```

**2. Configure the bundle**

```yaml
# config/packages/chunk_uploader.yaml
chunk_uploader:
    max_chunk_size: 5242880
    max_file_size: 104857600
    max_chunks: 1000
    allowed_mime_types: []
    spool_directory: '%kernel.project_dir%/var/chunked-uploader'
    garbage_collection_ttl: 3600
    token_secret: '%env(APP_SECRET)%'
    token_salt: '%env(APP_SECRET)%'       # optional client-fingerprint salt
    storage: local                         # local | s3
    local:
        base_directory: '%kernel.project_dir%/var/chunked-uploader/chunks'
    s3:
        bucket: 'my-bucket'
        prefix: 'chunks/'
        final_prefix: 'uploads/'            # where the assembled object is written
        config: { version: latest, region: eu-west-1, key: ~, secret: ~ }
    metadata: redis                        # redis | pdo
    redis:
        client: phpredis                   # phpredis | predis
        prefix: 'chunked-uploader:'
        ttl: 0
        connection_service: 'redis'        # required when rate limiting is enabled
    pdo:
        table: chunked_upload_states
        connection: default
    virus_scanning:
        enabled: false
        host: '127.0.0.1'
        port: 3310
    rate_limiting:
        enabled: false
        max_attempts: 100
        decay_seconds: 60
        key: 'chunked-uploader:chunks'
```

> **S3 uploads use native multipart transfers.** The S3 driver opens a
> `CreateMultipartUpload` on the first chunk, sends each chunk as an
> `UploadPart`, and finishes with `CompleteMultipartUpload`, so the object store
> performs the concatenation instead of the server streaming every byte twice.
>
> The `UploadId` and each part's `ETag` are persisted in the metadata repository,
> which is what lets an upload resume across requests and web nodes. The final
> object lands at `<prefix><identifier>/<final_prefix><filename>`; both the driver
> and the assembler resolve that key from one place, so they cannot disagree.
>
> **S3 requires every part except the last to be at least 5 MiB**, and allows at
> most 10,000 parts. The client's default 2 MiB chunk size therefore *fails* for
> any multi-chunk S3 upload — configure a larger chunk size (e.g. 8 MiB) when
> `storage: s3`, or keep 2 MiB for local storage. This is enforced up front with
> an actionable message rather than surfacing as an opaque `EntityTooSmall` at
> completion time.

**3. Add routes via attribute on the controller**

The example at [`examples/symfony/ChunkUploadController.php`](examples/symfony/ChunkUploadController.php)
uses attribute routing and constructor injection, so no routing YAML is needed.

---

## Vanilla JavaScript client

> **Looking for the client?** The production-grade, typed TypeScript client now
> lives in [`js-client/`](js-client/) and is published as
> `@resumable/chunked-uploader-client`. It is strictly dependency-free, ships
> ESM + CJS + type declarations, and speaks the exact same wire protocol. Use it
> in new projects; the single-file demo below is retained only so the backend can
> be exercised with no build step.

A 100% dependency-free ES6+ client, using `Blob.prototype.slice()`, the Fetch
API and `FormData`, is included in [`examples/vanilla-js/`](examples/vanilla-js/).

```html
<script src="uploader.js"></script>
```

```js
const uploader = new ChunkedUploader({
  file,
  endpoint: '/upload',
  chunkSize: 2 * 1024 * 1024,          // 2 MiB
  token,                                // HMAC token from your backend
  onProgress: (percent, index, total) => {
    progressBar.value = percent;
  },
  onSuccess: (result) => console.log('Done', result),
  onError: (error) => console.error(error),
});

await uploader.start();          // also valid: await uploader.resume()
```

Features:

- Splits a `File` into binary chunks with `file.slice(start, end)`.
- Sends each chunk as `multipart/form-data` via `fetch()`.
- Automatic retry with **exponential backoff** on transient failures.
- `pause()` / `resume()` for interactive control.
- `missingChunks()` queries `GET {endpoint}/status/{id}` so a partially
  uploaded file resumes instead of restarting.
- Works in any modern browser with native `fetch`, `FormData`, and `Blob`.

Open [`examples/vanilla-js/index.html`](examples/vanilla-js/index.html) in a
browser for a ready-to-run drag-and-drop demo.

---

## Security model

1. **Tokens** — `UploadTokenService` issues HMAC-SHA256 tokens bound to the
   upload identifier, chunk count, total size, and an optional client salt
   (IP / fingerprint). Verification uses `hash_equals()` (constant-time).
2. **Paths** — `PathSanitizer` rejects null bytes, directory separators,
   traversal markers (`..`, `../`), and unsafe identifiers. Never build a
   filesystem path from user input without running it through the sanitizer.
3. **MIME** — `MagicByteValidator` inspects only the first 4096 bytes via
   `finfo` and compares against an allow-list. Client-supplied MIME headers
   are never trusted.
4. **Extension matching** — `ExtensionMimeMatchRule` rejects files whose
   claimed extension disagrees with their detected MIME type.
5. **Malware** — `ClamAvScanner` streams data to `clamd` over the INSTREAM
   protocol in 1 MiB chunks. Use `NullVirusScanner` when scanning is handled
   elsewhere.
6. **Abuse** — `RedisRateLimiter` can key limits per IP, user, or upload token
   and applies them before accepting chunk bodies.
7. **Storage** — Keep temporary and final directories outside the public
   document root, and configure permissions and retention deliberately.

---

## Concurrent assembly (distributed locking)

When the last chunks of a file reach different servers at the same moment,
every one of those requests sees a complete upload and would otherwise enter the
assembler. That produces a real failure, not just wasted work:

- **S3** — two `CompleteMultipartUpload` calls on one upload id. The loser gets
  `NoSuchUpload`, and a retrying client can then abort the multipart upload or
  delete the parts the winner just finalized.
- **Local storage** — two processes writing the same destination file, so the
  result is whichever write lands last.

`ChunkUploader` therefore takes a short distributed lock on
`assembly:lock:<identifier>` around the assemble-and-publish step, re-reads the
upload state *after* acquiring it, and releases it in a `finally` block. The lock
follows the configured **metadata** driver, so it reuses that driver's existing
connection instead of introducing a second backend to provision.

```php
$locks = new RedisLockManager($redis);              // or new PdoLockManager($pdo)

$uploader = new ChunkUploader(
    storage: $storage,
    metadata: $metadata,
    progress: $progress,
    assembler: $assembler,
    validator: $validator,
    dispatcher: $dispatcher,
    lockManager: $locks,
    assemblyLockTtl: 60,        // lease length in seconds
    assemblyWaitSeconds: 10,    // how long a losing request polls for the winner
);
```

Laravel and Symfony wire this for you from `chunk-uploader.assembly_lock.ttl`
and `chunk-uploader.assembly_lock.wait_seconds` (Laravel:
`CHUNK_UPLOADER_ASSEMBLY_LOCK_TTL`, `CHUNK_UPLOADER_ASSEMBLY_LOCK_WAIT`).
Passing `lockManager: null` — or simply omitting it — keeps the previous
single-node behaviour, so existing integrations are unaffected.

**How each driver stays correct**

| Driver | Acquire | Release |
| --- | --- | --- |
| `RedisLockManager` | `SET key token NX PX ttl` — one atomic command, so no check-then-set window | Lua compare-and-delete; a node whose lease lapsed cannot free a successor's lock |
| `PdoLockManager` | `INSERT` against a primary key on `lock_key`; the unique violation *is* the lost-race signal, so no `SELECT`-then-`INSERT` gap | `DELETE ... WHERE lock_key = ? AND token = ?` |

Both hold a **self-expiring lease**, so a node that is killed mid-assembly
cannot wedge the upload permanently. A loser that finds the lock busy polls for
the winner's published `finalPath`; if its budget runs out it returns the
current state (with `finalPath` still `null`) rather than reporting a failure
for work another node is still finishing.

Set `assemblyLockTtl` above your slowest realistic assembly time — the lease is
set once and is not renewed mid-assembly, so a TTL shorter than a slow assembly
lets a second node start a duplicate one.

---

## End-to-end chunk checksums

A chunk digest is only worth computing if something checks the bytes that
actually crossed the network. Verifying in PHP proves the copy the web server
wrote to local disk is intact; it cannot see corruption introduced between PHP
and the storage backend. With the S3 driver the digest is forwarded to S3, which
hashes the part it received and rejects the upload itself.

The browser client computes the digest by default:

```ts
const uploader = new ChunkedUploader({
  file,
  endpoint: '/upload',
  // Defaults to 'sha256'. Use false to skip it, e.g. against a driver that
  // re-hashes parts itself.
  checksum: 'sha256',
});
```

`crypto.subtle` is only exposed in secure contexts, so on a plain-HTTP origin
the field is simply omitted rather than failing an upload that would otherwise
succeed. WebCrypto has no MD5; requesting `checksum: 'md5'` without a `digest`
hook fails immediately rather than quietly skipping verification:

```ts
import md5 from 'fast-md5';

const uploader = new ChunkedUploader({
  file,
  endpoint: '/upload',
  checksum: 'md5',
  // The hook returns raw bytes; the client owns the base64 encoding.
  digest: async (blob) => md5(await blob.arrayBuffer()),
});
```

### On the server

The `checksum` request field is decoded by `Core\Security\ChunkChecksum`, which
accepts either hex or base64 and normalises to raw bytes, so digests produced by
PHP, a browser, or a shell tool all work. Malformed digests raise
`InvalidChunkException`; a blank value means "no digest supplied" and is not an
error.

Storage forwards the digest to S3 as `ChecksumSHA256` (SHA-256) or `ContentMD5`
(MD5). S3 rejections are translated into typed exceptions, distinguishing the
two cases that matter:

| S3 error | Meaning |
| --- | --- |
| `BadDigest` | Bytes diverged in transit; re-sending the chunk can help. |
| `XAmzContentSHA256Mismatch` | Same, for SHA-256. |
| `InvalidDigest` | The digest itself was malformed. Retrying identical bytes fails again, so this is reported as a client bug. |

### Choosing where verification happens

`checksum_verify` controls whether PHP also re-hashes the temp file:

| Mode | Behaviour |
| --- | --- |
| `local` (default) | `ChecksumRule` re-reads the chunk from disk and hashes it. Correct for the local driver. |
| `storage` | The rule is omitted and the backend verifies the part. **Use this with the S3 driver.** |

Leaving the default alongside S3 hashes every chunk twice — once in PHP against
the temp file, and again inside S3 against the part it received — for no
additional coverage.

```dotenv
# Laravel
CHUNK_UPLOADER_STORAGE=s3
CHUNK_UPLOADER_CHECKSUM_VERIFY=storage
```

```yaml
# Symfony
chunk_uploader:
    storage: s3
    checksum_verify: storage
```

---

## API reference

### `ChunkUploaderInterface`

| Method | Description |
| --- | --- |
| `processChunk(Chunk $chunk): UploadState` | Validates the token, persists the chunk, advances progress, and assembles when complete. Idempotent and concurrent-safe. |
| `cancelUpload(string $identifier): void` | Removes chunks and metadata. Idempotent for unknown identifiers. |
| `getStatus(string $identifier): ?UploadState` | Reads the current state of an upload, or null if unknown. |

### `Chunk` (readonly DTO)

`identifier`, `token`, `index`, `totalChunks`, `chunkSize`, `totalSize`,
`tmpFilePath`, `originalFilename`, and optional `checksum`.

### `UploadState` (readonly DTO)

`identifier`, `totalChunks`, `totalSize`, `originalFilename`, `uploadedChunks`,
`isCompleted`, `finalPath`; plus `hasChunk()`, `isComplete()`,
`withUploadedChunk()`, and `withFinalPath()`.

### Contracts

| Contract | Methods |
| --- | --- |
| `ChunkStorageInterface` | `store`, `getChunkStream`, `deleteChunks`, `cleanOrphanedChunks` |
| `MetadataRepositoryInterface` | `save`, `get`, `delete`, `markChunkAsUploaded`, `cleanExpired` |
| `ProgressTrackerInterface` | `getPercentage`, `isComplete`, `getMissingChunkIndices` |
| `FileAssemblerInterface` | `assemble(UploadState, ChunkStorageInterface): string` |
| `LockManagerInterface` | `acquire(string, int $ttlSeconds = 60): bool`, `release(string): void` |
| `ValidationRuleInterface` | `validate(Chunk): void` |
| `ChunkValidatorInterface` | `validate(Chunk): bool` |
| `VirusScannerInterface` | `scan(string): void` |
| `EventDispatcherInterface` | `dispatch(object): object` |

---

## Endpoint-specific attributes

Global Laravel or Symfony configuration remains the default. A controller
method can opt into a narrower immutable configuration for its own uploads:

```php
use Resumable\ChunkedUploader\Core\Attributes\ChunkedUpload;
use Resumable\ChunkedUploader\Core\Attributes\AllowedMimes;
use Resumable\ChunkedUploader\Core\Attributes\MaxFileSize;

final class MediaController
{
    #[ChunkedUpload(tokenSalt: 'media-endpoint')]
    #[AllowedMimes(['video/mp4'])]
    #[MaxFileSize(50 * 1024 * 1024)]
    public function upload(): void
    {
        // Resolve the method with ChunkedUploadConfigResolver in the bridge,
        // then pass the returned UploaderConfig to ChunkUploader::processChunk.
    }
}
```

The core resolver accepts either a `ReflectionMethod` or `ReflectionClass` and
returns the unchanged global instance when no attribute is present.

## Configuration options

| Setting | Default | Description |
| --- | --- | --- |
| `maxChunkSize` | 5 MiB | Hard limit of a single chunk. |
| `maxFileSize` | 100 MiB | Hard limit of the assembled file. |
| `maxChunks` | 1000 | Max chunks per upload (DoS guard). |
| `allowedMimeTypes` | `[]` | Whitelist; empty = any verified type. |
| `spoolDirectory` | `/tmp/chunked-uploader` | Chunk + final assembly dir. |
| `garbageCollectionTtl` | 3600 s | Incomplete-upload window before GC. |
| `identifierPattern` | `^[a-zA-Z0-9_-]{1,128}$` | Legal identifier pattern. |
| `tokenSalt` | `''` | Optional HMAC salt bound to client context. |
| `virusScanning` | disabled | ClamAV host/port (requires `virus_scanning.enabled: true`). |
| `rateLimiting` | disabled | Redis-backed chunk-flood guard (`max_attempts`, `decay_seconds`, `key`). |

---

## Testing

```bash
composer install
vendor/bin/phpunit
```

The full suite runs **completely offline**: all disk I/O uses ephemeral
temporary files that are cleaned up automatically; storage and metadata are
in-memory doubles (FakeRedis, FakePdo); and Redis, S3, and ClamAV are never
contacted. The suite covers:

- **Unit tests** against `PathSanitizer`, `MagicByteValidator`,
  `UploadTokenService`, `StreamAssembler` (memory ceiling), `ClamAvScanner`
  (stream-pair seam), `RedisRateLimiter`, `S3ChunkStorage` and
  `S3MultipartAssembler` (native multipart lifecycle, part-number mapping, ETag
  manifest, abort/reap paths), `UploadState`, and
  `PdoMetadataRepository` (with Postgres/MySQL dialect assertions).
- **Feature tests** for the full sequential upload flow, out-of-order
  resumable uploads, interrupted-upload recovery (idempotent retries,
  resume-from-missing-index, service-restart continuity), and both the
  Laravel and Symfony framework bridges.

## License

MIT. See [`LICENSE`](LICENSE).
