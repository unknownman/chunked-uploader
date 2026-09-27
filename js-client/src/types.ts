/**
 * Public type surface for the chunked uploader client.
 *
 * Everything here is structural: no classes are exposed in the type
 * signatures except `Error` subclasses, so consumers can pass their own
 * request/response shapes without importing runtime code.
 */

import type { DigestHook, RequestedChecksum } from './internal/checksum';

export type { DigestHook, RequestedChecksum } from './internal/checksum';
export { UnsupportedChecksumError } from './internal/checksum';

/** A binary payload accepted by the uploader. `File` adds a `name`. */
export type UploadSource = Blob | File;

/** Extra, byte-level context handed to {@link ChunkedUploaderOptions.onProgress}. */
export interface ProgressDetail {
  /** Bytes confirmed by the server so far. */
  readonly uploadedBytes: number;
  /** Total bytes in the source file. */
  readonly totalBytes: number;
  /** Zero-based index of the chunk that just completed. */
  readonly chunkIndex: number;
  /** Byte length of the chunk that just completed. */
  readonly chunkBytes: number;
}

/** Terminal result handed to {@link ChunkedUploaderOptions.onSuccess}. */
export interface UploadResult {
  /** Server-side upload identifier (also used for resume). */
  readonly identifier: string;
  /** `true` once every chunk has been acknowledged. */
  readonly complete: boolean;
  /** Number of chunks in this upload. */
  readonly totalChunks: number;
  /** Total bytes transferred. */
  readonly totalBytes: number;
}

/** Normalised view of `GET {endpoint}/status/{identifier}`. */
export interface UploadStatus {
  readonly identifier: string;
  readonly missingChunks: readonly number[];
  /** Set by the PHP backend when assembly already finished. */
  readonly completed: boolean;
}

/**
 * Lifecycle hook invoked after every acknowledged chunk.
 *
 * `percent` is `0..100` and is derived from *acknowledged* chunks, so it is
 * safe to drive a `<progress>` element directly.
 */
export type ProgressCallback = (
  percent: number,
  index: number,
  total: number,
  detail: ProgressDetail,
) => void;

export type SuccessCallback = (result: UploadResult) => void;
export type ErrorCallback = (error: Error) => void;
export type PauseCallback = () => void;
export type ResumeCallback = () => void;
/** Fired before each retry sleep, with the 1-based attempt that just failed. */
export type RetryCallback = (attempt: number, error: Error, delayMs: number) => void;

/** Discriminates which of the two request shapes a hook is being called for. */
export type RequestKind = 'status' | 'chunk';

/** Immutable context object passed to {@link BeforeRequestHook}. */
export interface RequestContext {
  readonly kind: RequestKind;
  /** Fully-qualified URL (or endpoint) the request targets. */
  readonly url: string;
  /** Zero-based chunk index; `undefined` for `status` requests. */
  readonly index: number | undefined;
  /** Zero-based attempt counter. */
  readonly attempt: number;
  /** The upload identifier in use. */
  readonly identifier: string;
  /** Pre-computed init. Mutate freely, or return overrides from the hook. */
  readonly init: RequestInit;
}

/**
 * A {@link RequestInit} where every key may be explicitly `undefined`.
 *
 * Under `exactOptionalPropertyTypes` a plain `RequestInit` forces hook authors
 * to build objects conditionally. Keys set to `undefined` are ignored when the
 * override is merged, so they behave like "leave this alone".
 */
export type RequestInitOverride = {
  [K in keyof RequestInit]?: RequestInit[K] | undefined;
};

/**
 * Escape hatch applied to every request before it hits the network.
 *
 * Return a partial {@link RequestInit} to shallow-merge overrides, or
 * `undefined` to use the pre-computed init as-is. Async hooks are awaited.
 */
export type BeforeRequestHook = (
  context: RequestContext,
) =>
  | RequestInitOverride
  | void
  | undefined
  | Promise<RequestInitOverride | void | undefined>;

/** Static headers, or a (possibly async) factory for per-request headers. */
export type HeaderSource =
  | HeadersInit
  | (() => HeadersInit | Promise<HeadersInit>);

export interface ChunkedUploaderOptions {
  /** The file or blob to upload. */
  readonly file: UploadSource;
  /** Base endpoint. Status is queried at `{endpoint}/status/{identifier}`. */
  readonly endpoint: string;
  /** Byte size of each chunk. Default `2 MiB`. */
  readonly chunkSize?: number;
  /** HMAC token issued by the backend. Always sent, even when empty. */
  readonly token?: string;
  /**
   * Resume under a known identifier instead of generating a new one.
   * Defaults to a `crypto.randomUUID()` value with dashes stripped.
   */
  readonly identifier?: string;
  /**
   * Overrides the filename reported to the backend. Defaults to the
   * `File.name`, or `'upload.bin'` for a bare `Blob`.
   */
  readonly filename?: string;
  /** Extra headers merged into every request (e.g. `Authorization`, `X-CSRF-Token`). */
  readonly headers?: HeaderSource;
  /** Per-request hook for dynamic auth, tracing, or abort wiring. */
  readonly beforeRequest?: BeforeRequestHook;
  /** Additional `fetch` options merged under the hook. `Content-Type` is never forced. */
  readonly credentials?: RequestCredentials;
  /** Abort signal; aborting rejects the active run with an `AbortError`. */
  readonly signal?: AbortSignal;
  /** Max retries per chunk *after* the first attempt. Default `5`. */
  readonly retryLimit?: number;
  /** Initial backoff delay in ms. Default `250`. */
  readonly backoffBase?: number;
  /** Upper bound for a single backoff delay in ms. Default `30000`. */
  readonly backoffMax?: number;
  /**
   * Random jitter ratio in `[0, 1]` applied to each delay. Default `0`
   * (deterministic, matching the legacy client). `0.5` = ±50%.
   */
  readonly backoffJitter?: number;
  /**
   * Per-chunk digest sent to the backend, letting S3 verify the bytes it
   * received. `'sha256'` by default; `false` disables it.
   *
   * Set this to `false` when the backend cannot use a digest -- a non-S3 driver
   * that re-hashes parts itself would otherwise hash every chunk twice.
   *
   * `crypto.subtle` is unavailable outside secure contexts, in which case the
   * field is omitted rather than failing the upload.
   */
  readonly checksum?: RequestedChecksum;
  /** Supplies a digest for algorithms WebCrypto does not implement (e.g. `md5`). */
  readonly digest?: DigestHook;
  /** Injection point for tests / non-browser fetch implementations. */
  readonly fetch?: typeof globalThis.fetch;
  /** Injected `setTimeout`, mainly so tests need not use fake timers. */
  readonly sleep?: (ms: number) => Promise<void>;

  readonly onProgress?: ProgressCallback;
  readonly onSuccess?: SuccessCallback;
  readonly onError?: ErrorCallback;
  readonly onPause?: PauseCallback;
  readonly onResume?: ResumeCallback;
  readonly onRetry?: RetryCallback;
}

/**
 * Fully-resolved options, as held internally by the uploader: every optional
 * field has been defaulted, so the hot paths contain no undefined checks.
 */
export interface ResolvedUploaderOptions {
  readonly file: UploadSource;
  readonly endpoint: string;
  readonly chunkSize: number;
  readonly token: string;
  readonly identifier: string;
  readonly filename: string;
  readonly retryLimit: number;
  readonly backoffBase: number;
  readonly backoffMax: number;
  readonly backoffJitter: number;
  readonly onProgress: ProgressCallback;
  readonly onSuccess: SuccessCallback;
  readonly onError: ErrorCallback;
  readonly onPause: PauseCallback;
  readonly onResume: ResumeCallback;
  readonly onRetry: RetryCallback;
  readonly headers: HeaderSource | undefined;
  readonly beforeRequest: BeforeRequestHook | undefined;
  readonly credentials: RequestCredentials | undefined;
  readonly signal: AbortSignal | undefined;
  readonly fetch: typeof globalThis.fetch | undefined;
  readonly sleep: (ms: number) => Promise<void>;
  readonly checksum: RequestedChecksum;
  readonly digest: DigestHook | undefined;
}

/** JSON payload the PHP backend returns for a stored chunk. */
export interface ChunkResponse {
  readonly identifier?: string;
  readonly uploadedChunks?: readonly number[];
  readonly missingChunks?: readonly number[];
  readonly completed?: boolean;
  readonly finalPath?: string | null;
  readonly error?: string;
}
