# @resumable/chunked-uploader-client

Typed, dependency-free resumable chunked upload client for the
[`resumable/chunked-uploader`](https://github.com/) PHP backend.

- **Zero runtime dependencies** — only `Blob.slice()`, the Fetch API and `FormData`.
- **Fully typed** — strict TypeScript, no `any` in the public surface.
- **Hybrid ESM/CJS** — ships `dist/index.js`, `dist/index.cjs`, `.d.ts` and `.d.cts`.
- **Resumable** — asks the backend which chunks are missing, so an interrupted
  upload continues instead of restarting.
- **Resilient** — exponential backoff, a retry classifier that refuses to retry
  hopeless 4xx responses, and `AbortSignal` support.

## Install

```bash
npm install @resumable/chunked-uploader-client
```

## Quick start

```ts
import { ChunkedUploader, type UploadResult } from '@resumable/chunked-uploader-client';

const uploader = new ChunkedUploader({
  file: fileInput.files![0]!,
  endpoint: '/upload',
  chunkSize: 2 * 1024 * 1024,
  token: 'hmac-token-from-server',

  onProgress: (percent, index, total) => {
    console.log(`${percent.toFixed(1)}% (chunk ${index + 1}/${total})`);
  },
  onSuccess: (result: UploadResult) => {
    console.log(`Upload ${result.identifier} complete.`);
  },
  onError: (error: Error) => {
    console.error(error.message);
  },
});

const result = await uploader.start();
```

A complete, runnable version lives in [`examples/quickstart.ts`](./examples/quickstart.ts).

## Authentication headers

Two escape hatches, usable together.

**`headers`** — a static object, or a (possibly async) factory evaluated before
every request, including the status probe:

```ts
new ChunkedUploader({
  file,
  endpoint: '/upload',
  headers: { Authorization: `Bearer ${token}`, 'X-CSRF-Token': csrf },
  // or refresh a short-lived token on each attempt:
  // headers: async () => ({ Authorization: `Bearer ${await refreshToken()}` }),
});
```

**`beforeRequest`** — full control. Mutate `ctx.init` in place, return a partial
`RequestInit` to merge, or return nothing at all:

```ts
new ChunkedUploader({
  file,
  endpoint: '/upload',
  beforeRequest: async (ctx) => {
    // ctx.kind: 'status' | 'chunk'
    // ctx.index: chunk index, or undefined for the status probe
    // ctx.attempt: zero-based retry counter
    if (ctx.kind === 'chunk' && ctx.index === 0) {
      return { headers: { 'X-First-Chunk': 'true' } };
    }
  },
});
```

> A custom `Content-Type` is deliberately dropped on chunk uploads so the
> browser can keep the `multipart/form-data; boundary=…` header it generates.
> Set it yourself only if you are not sending `FormData`.

## Resuming an interrupted upload

Pass the `identifier` you stored earlier and the client will skip the chunks the
backend already holds:

```ts
const uploader = new ChunkedUploader({
  file,
  endpoint: '/upload',
  identifier: localStorage.getItem('upload-id')!,
  token,
});

await uploader.start(); // re-queries GET /upload/status/{identifier}
```

If the backend has no record of the identifier it answers `404`, and the client
transparently treats every chunk as missing.

## Pause / resume / cancel

```ts
uploader.pause();           // finishes the in-flight chunk, then parks
await uploader.resume();    // idempotent; shares the in-flight promise

uploader.abort();           // rejects the pending promise with UploadAbortedError
```

`resume()` returns the same promise object as the original `start()` when a run is
already active, so concurrent callers never race.

## Error handling

Every failure is an `Error` subclass with a stable `code`, so you can branch
without matching on message strings:

| Class | `code` | Raised when |
| --- | --- | --- |
| `UploadConfigError` | `INVALID_OPTION` | Bad options (also thrown synchronously by the constructor) |
| `UploadStateError` | `ALREADY_RUNNING` | `start()` called while a run is in flight |
| `UploadHttpError` | `HTTP_ERROR` | Non-2xx response; carries `status`, `url`, `body` |
| `UploadNetworkError` | `NETWORK_ERROR` | `fetch` itself rejected |
| `UploadAbortedError` | `ABORTED` | Cancelled via `abort()` or `AbortSignal` |
| `UploadParseError` | `BAD_JSON` | 2xx body was not the expected JSON |
| `UploadRejectedError` | `SERVER_REJECTED` | 2xx body reported a domain-level error |

## Options

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `file` | `Blob \| File` | — | Required. Must be non-empty. |
| `endpoint` | `string` | — | Required. Trailing slashes are trimmed. |
| `chunkSize` | `number` | `2097152` | Bytes per chunk. |
| `token` | `string` | `''` | HMAC token; always sent, even when empty. |
| `identifier` | `string` | `crypto.randomUUID()` (dashes stripped) | Reuse to resume. |
| `filename` | `string` | `File.name` or `'upload.bin'` | Name reported to the backend. |
| `headers` | `HeadersInit \| () => HeadersInit \| Promise<…>` | — | Merged into every request. |
| `beforeRequest` | `BeforeRequestHook` | — | Per-request hook. |
| `credentials` | `RequestCredentials` | — | For cookie-based sessions. |
| `signal` | `AbortSignal` | — | External cancellation. |
| `retryLimit` | `number` | `5` | Retries *after* the first attempt. |
| `backoffBase` | `number` | `250` | Initial backoff in ms. |
| `backoffMax` | `number` | `30000` | Cap for a single delay. |
| `backoffJitter` | `number` | `0` | Random jitter ratio in `[0, 1]`. `0.5` = ±50%. |
| `onProgress` | `(percent, index, total, detail) => void` | — | After each acknowledged chunk. |
| `onSuccess` | `(result) => void` | — | On completion. |
| `onError` | `(error) => void` | — | On failure, before the promise rejects. |
| `onPause` / `onResume` | `() => void` | — | State transitions. |
| `onRetry` | `(attempt, error, delayMs) => void` | — | Before each backoff sleep. |
| `fetch` | `typeof fetch` | `globalThis.fetch` | Injection point for tests. |
| `sleep` | `(ms) => Promise<void>` | `setTimeout` | Injection point for tests. |

### Read-only members

`file`, `endpoint`, `chunkSize`, `token`, `identifier`, `filename`,
`retryLimit`, `backoffBase`, `backoffMax`, `backoffJitter`, `totalChunks`.

### Instance API

`start()`, `resume()`, `pause()`, `abort()`, `missingChunks()`, `uploadChunk(index)`.

Getters: `isPaused`, `isRunning`, `bytesUploaded`.

## Retry semantics

`retryLimit` counts retries *after* the first attempt, so the default `5` means
up to six requests per chunk. Delays follow `backoffBase * 2^n`, capped by
`backoffMax`; enable `backoffJitter` to spread retries across concurrent tabs.

`400`, `401`, `403`, `404`, `405`, `409`, `410`, `413`, `415` and `422` are
treated as permanent and fail immediately — retrying a validation error just
delays the error message. `408`, `429`, `5xx` and network faults are retried.

## Development

```bash
npm install
npm run test         # vitest
npm run test:coverage
npm run lint
npm run typecheck
npm run build        # tsup -> dist/
```

The test suite stubs `fetch` and injects a no-op `sleep`, so the 41 tests run in
well under a second with no network and no fake timers.

## License

MIT
