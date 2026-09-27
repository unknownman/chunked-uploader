import {
  DEFAULT_BACKOFF_BASE,
  DEFAULT_BACKOFF_MAX,
  DEFAULT_CHUNK_SIZE,
  DEFAULT_FILENAME,
  DEFAULT_RETRY_LIMIT,
  STATUS_PATH,
} from './constants';
import {
  UploadAbortedError,
  UploadConfigError,
  UploadHttpError,
  UploadNetworkError,
  UploadRejectedError,
  UploadStateError,
} from './errors';
import { computeBackoffDelay, sleep as defaultSleep } from './internal/backoff';
import { digestChunk } from './internal/checksum';
import { generateIdentifier } from './internal/identifier';
import { normaliseStatus, performRequest } from './internal/http';
import type {
  ChunkResponse,
  ChunkedUploaderOptions,
  ProgressDetail,
  ResolvedUploaderOptions,
  UploadResult,
  UploadSource,
  UploadStatus,
} from './types';

/**
 * HTTP statuses that will never succeed on a blind retry: the request itself
 * is malformed, unauthorised, or the payload is unacceptable. Everything else
 * (408, 429, 5xx, network faults) is treated as transient.
 */
const NON_RETRYABLE_STATUSES: ReadonlySet<number> = new Set([
  400, 401, 403, 404, 405, 409, 410, 413, 415, 422,
]);

function isFileLike(value: UploadSource): value is File {
  return typeof (value as File).name === 'string' && (value as File).name.length > 0;
}

function requirePositiveInteger(value: number, label: string, fallback: number): number {
  if (value === undefined) {
    return fallback;
  }
  if (!Number.isFinite(value) || !Number.isInteger(value) || value <= 0) {
    throw new UploadConfigError(`${label} must be a positive integer, received ${String(value)}.`);
  }
  return value;
}

function requireNonNegativeInteger(value: number, label: string, fallback: number): number {
  if (value === undefined) {
    return fallback;
  }
  if (!Number.isFinite(value) || !Number.isInteger(value) || value < 0) {
    throw new UploadConfigError(`${label} must be a non-negative integer, received ${String(value)}.`);
  }
  return value;
}

/**
 * Dependency-free resumable uploader.
 *
 * Splits a {@link UploadSource} into chunks with `Blob.slice()`, posts each one
 * as `multipart/form-data` via the Fetch API, retries transient failures with
 * exponential backoff, and can resume an interrupted upload by asking the
 * backend which chunks are still missing.
 *
 * @example
 * ```ts
 * const uploader = new ChunkedUploader({
 *   file: input.files[0]!,
 *   endpoint: '/upload',
 *   token: 'hmac-token',
 *   onProgress: (percent) => setBar(percent),
 * });
 * await uploader.start();
 * ```
 */
export class ChunkedUploader {
  /** The source file, kept for slicing and metadata. */
  public readonly file: UploadSource;
  /** Endpoint with any trailing slash removed. */
  public readonly endpoint: string;
  public readonly chunkSize: number;
  public readonly token: string;
  public readonly identifier: string;
  public readonly filename: string;
  public readonly retryLimit: number;
  public readonly backoffBase: number;
  public readonly backoffMax: number;
  public readonly backoffJitter: number;
  /** Number of chunks this upload is split into. */
  public readonly totalChunks: number;

  private readonly options: ResolvedUploaderOptions;
  private readonly completedChunks = new Set<number>();
  private uploadedBytes = 0;

  private paused = false;
  private running = false;
  private activeRun: Promise<UploadResult> | null = null;
  private resumeWaiter: (() => void) | null = null;
  private controller: AbortController | null = null;
  private externalSignal: AbortSignal | undefined;

  constructor(options: ChunkedUploaderOptions) {
    this.assertValidOptions(options);
    this.options = this.resolveOptions(options);

    this.file = this.options.file;
    this.endpoint = this.options.endpoint;
    this.chunkSize = this.options.chunkSize;
    this.token = this.options.token;
    this.identifier = this.options.identifier;
    this.filename = this.options.filename;
    this.retryLimit = this.options.retryLimit;
    this.backoffBase = this.options.backoffBase;
    this.backoffMax = this.options.backoffMax;
    this.backoffJitter = this.options.backoffJitter;
    this.totalChunks = Math.ceil(this.file.size / this.chunkSize);

    this.externalSignal = this.options.signal;
  }

  /** `true` between `pause()` and `resume()`. */
  public get isPaused(): boolean {
    return this.paused;
  }

  /** `true` while a `start()`/`resume()` run is in flight. */
  public get isRunning(): boolean {
    return this.running;
  }

  /** Bytes confirmed by the backend so far. */
  public get bytesUploaded(): number {
    return this.uploadedBytes;
  }

  /**
   * Begin (or continue) the upload. Queries the backend for missing chunks
   * first so an interrupted upload resumes rather than restarts.
   *
   * @throws {UploadStateError} Synchronously, when a run is already in flight.
   * @throws {UploadHttpError | UploadNetworkError | UploadParseError} On transport failure.
   */
  public start(): Promise<UploadResult> {
    if (this.running) {
      throw new UploadStateError('Upload is already running.');
    }
    return this.execute();
  }

  /**
   * Resume a paused (or previously failed) upload.
   *
   * Idempotent and safe to call while a run is in flight: in that case the
   * existing promise is returned so concurrent callers share a single run
   * instead of racing (and instead of receiving a `UploadStateError`).
   */
  public resume(): Promise<UploadResult> {
    this.setPaused(false);

    if (this.running && this.activeRun !== null) {
      return this.activeRun;
    }

    return this.start();
  }

  /** Pause after the in-flight chunk settles. Safe to call when not running. */
  public pause(): void {
    this.setPaused(true);
  }

  /** Abort the active run. The pending promise rejects with an `UploadAbortedError`. */
  public abort(): void {
    this.controller?.abort();
    this.releasePauseWaiter();
    this.setPaused(false);
  }

  /**
   * Ask the backend which chunk indices still need uploading.
   *
   * A `404` means the backend has no record of the identifier, so every chunk
   * is considered missing and the upload starts from scratch.
   */
  public async missingChunks(): Promise<number[]> {
    const status = await this.fetchStatus(0);
    if (status === null) {
      return Array.from({ length: this.totalChunks }, (_, index) => index);
    }
    return [...status.missingChunks];
  }

  /** Upload a single chunk with retry + exponential backoff. */
  public async uploadChunk(index: number): Promise<ChunkResponse> {
    return this.sendChunk(index);
  }

  // ---------------------------------------------------------------- internals

  private assertValidOptions(options: ChunkedUploaderOptions): void {
    if (options === null || typeof options !== 'object') {
      throw new UploadConfigError('ChunkedUploader requires an options object.');
    }
    const { file, endpoint } = options;

    if (file === undefined || file === null || typeof file.size !== 'number') {
      throw new UploadConfigError('options.file must be a File or Blob.');
    }
    if (file.size <= 0) {
      throw new UploadConfigError('options.file must not be empty; there is nothing to upload.');
    }
    if (typeof endpoint !== 'string' || endpoint.trim() === '') {
      throw new UploadConfigError('options.endpoint must be a non-empty string.');
    }
  }

  private resolveOptions(options: ChunkedUploaderOptions): ResolvedUploaderOptions {
    const chunkSize = requirePositiveInteger(
      options.chunkSize ?? DEFAULT_CHUNK_SIZE,
      'options.chunkSize',
      DEFAULT_CHUNK_SIZE,
    );
    const backoffJitter = options.backoffJitter ?? 0;

    if (!Number.isFinite(backoffJitter) || backoffJitter < 0 || backoffJitter > 1) {
      throw new UploadConfigError('options.backoffJitter must be between 0 and 1.');
    }

    return {
      file: options.file,
      endpoint: options.endpoint.replace(/\/+$/, ''),
      chunkSize,
      token: options.token ?? '',
      identifier: options.identifier ?? generateIdentifier(),
      filename: options.filename ?? (isFileLike(options.file) ? options.file.name : DEFAULT_FILENAME),
      retryLimit: requireNonNegativeInteger(
        options.retryLimit ?? DEFAULT_RETRY_LIMIT,
        'options.retryLimit',
        DEFAULT_RETRY_LIMIT,
      ),
      backoffBase: requirePositiveInteger(
        options.backoffBase ?? DEFAULT_BACKOFF_BASE,
        'options.backoffBase',
        DEFAULT_BACKOFF_BASE,
      ),
      backoffMax: requirePositiveInteger(
        options.backoffMax ?? DEFAULT_BACKOFF_MAX,
        'options.backoffMax',
        DEFAULT_BACKOFF_MAX,
      ),
      backoffJitter,
      onProgress: options.onProgress ?? noop,
      onSuccess: options.onSuccess ?? noop,
      onError: options.onError ?? noop,
      onPause: options.onPause ?? noop,
      onResume: options.onResume ?? noop,
      onRetry: options.onRetry ?? noop,
      headers: options.headers,
      beforeRequest: options.beforeRequest,
      credentials: options.credentials,
      signal: options.signal,
      fetch: options.fetch ?? globalThis.fetch?.bind(globalThis),
      sleep: options.sleep ?? defaultSleep,
      checksum: options.checksum ?? 'sha256',
      digest: options.digest,
    };
  }

  private execute(): Promise<UploadResult> {
    this.running = true;
    this.paused = false;
    this.controller = new AbortController();
    this.detachExternalSignal();
    this.attachExternalSignal();

    // `activeRun` must be the very promise handed back to the caller so that
    // `resume()` can hand the same promise to concurrent callers.
    const run = this.settle();
    this.activeRun = run;
    return run;
  }

  private async settle(): Promise<UploadResult> {
    try {
      return await this.runUpload();
    } catch (error) {
      const normalised = toError(error);
      this.options.onError(normalised);
      throw normalised;
    } finally {
      this.running = false;
      this.activeRun = null;
      this.controller = null;
      this.releasePauseWaiter();
      this.detachExternalSignal();
    }
  }

  private async runUpload(): Promise<UploadResult> {
    this.assertNotAborted();

    const status = await this.fetchStatus(0);

    // The backend may already hold a fully assembled upload (e.g. the client
    // crashed after the final chunk). Nothing left to send.
    if (status?.completed === true) {
      this.markAllChunksComplete();
      const result = this.buildResult();
      this.options.onSuccess(result);
      return result;
    }

    const missing = status === null
      ? Array.from({ length: this.totalChunks }, (_, index) => index)
      : [...status.missingChunks];

    for (const index of missing) {
      if (this.paused) {
        await this.waitUntilResumed();
      }
      this.assertNotAborted();
      await this.sendChunk(index);
    }

    const result = this.buildResult();
    this.options.onSuccess(result);
    return result;
  }

  /** Returns `null` when the backend has never seen this identifier (HTTP 404). */
  private async fetchStatus(attempt: number): Promise<UploadStatus | null> {
    const url = `${this.endpoint}/${STATUS_PATH}/${encodeURIComponent(this.identifier)}`;

    try {
      const payload = await performRequest({
        url,
        kind: 'status',
        init: {
          method: 'GET',
          headers: { Accept: 'application/json' },
        },
        index: undefined,
        attempt,
        identifier: this.identifier,
        headers: this.options.headers,
        beforeRequest: this.options.beforeRequest,
        credentials: this.options.credentials,
        signal: this.signal,
        fetchImpl: this.requireFetch(),
        expectJson: true,
      });
      return normaliseStatus(payload, this.identifier, this.totalChunks);
    } catch (error) {
      if (error instanceof UploadHttpError && error.status === 404) {
        return null;
      }
      throw error;
    }
  }

  private async sendChunk(index: number): Promise<ChunkResponse> {
    if (!Number.isInteger(index) || index < 0 || index >= this.totalChunks) {
      throw new UploadConfigError(
        `Chunk index ${String(index)} is out of range (0..${this.totalChunks - 1}).`,
      );
    }

    const blob = this.chunkBlob(index);
    // Hashed before the request is built so a WebCrypto failure surfaces as a
    // config error rather than as a mysterious network retry.
    const checksum = await digestChunk(blob, this.options.checksum, this.options.digest);
    const form = this.buildFormData(index, blob, checksum);

    let lastError: Error = new UploadNetworkError(`Chunk ${index} was never attempted.`);

    for (let attempt = 0; attempt <= this.retryLimit; attempt += 1) {
      this.assertNotAborted();

      if (attempt > 0) {
        const delay = computeBackoffDelay(
          attempt - 1,
          this.backoffBase,
          this.backoffMax,
          this.backoffJitter,
        );
        this.options.onRetry(attempt, lastError, delay);
        await this.options.sleep(delay);
        this.assertNotAborted();
      }

      try {
        const payload = await performRequest({
          url: this.endpoint,
          kind: 'chunk',
          init: { method: 'POST', body: form },
          index,
          attempt,
          identifier: this.identifier,
          headers: this.options.headers,
          beforeRequest: this.options.beforeRequest,
          credentials: this.options.credentials,
          signal: this.signal,
          fetchImpl: this.requireFetch(),
          expectJson: false,
        });

        if (typeof payload.error === 'string' && payload.error !== '') {
          throw new UploadRejectedError(`Chunk ${index} rejected: ${payload.error}`);
        }

        this.commitChunk(index, blob.size);
        return payload;
      } catch (error) {
        lastError = toError(error);
        if (!this.isRetryable(lastError) || attempt === this.retryLimit) {
          throw lastError;
        }
      }
    }

    throw lastError;
  }

  private isRetryable(error: Error): boolean {
    if (error instanceof UploadAbortedError) {
      return false;
    }
    if (error instanceof UploadHttpError) {
      return !NON_RETRYABLE_STATUSES.has(error.status);
    }
    if (error instanceof UploadConfigError || error instanceof UploadRejectedError) {
      return false;
    }
    return true;
  }

  private chunkBlob(index: number): Blob {
    const start = index * this.chunkSize;
    const end = Math.min(start + this.chunkSize, this.file.size);
    return this.file.slice(start, end);
  }

  /**
   * Field names must match the PHP backend validators exactly
   * (`identifier`, `token`, `index`, `totalChunks`, `totalSize`, `chunkSize`,
   * `originalFilename`, `chunk`).
   *
   * `checksum` is omitted entirely when it could not be computed, so a backend
   * treats it as "no digest supplied" rather than receiving an empty value it
   * would have to guess about.
   */
  private buildFormData(index: number, blob: Blob, checksum: string | undefined): FormData {
    const form = new FormData();
    form.append('chunk', blob, this.filename);
    form.append('identifier', this.identifier);
    form.append('token', this.token);
    form.append('index', String(index));
    form.append('totalChunks', String(this.totalChunks));
    form.append('chunkSize', String(blob.size));
    form.append('totalSize', String(this.file.size));
    form.append('originalFilename', this.filename);
    if (checksum !== undefined) {
      form.append('checksum', checksum);
    }
    return form;
  }

  private commitChunk(index: number, chunkBytes: number): void {
    if (!this.completedChunks.has(index)) {
      this.completedChunks.add(index);
      this.uploadedBytes += chunkBytes;
    }
    this.emitProgress(index, chunkBytes);
  }

  private markAllChunksComplete(): void {
    for (let index = 0; index < this.totalChunks; index += 1) {
      this.completedChunks.add(index);
    }
    this.uploadedBytes = this.file.size;
    this.emitProgress(this.totalChunks - 1, this.chunkBlob(this.totalChunks - 1).size);
  }

  private emitProgress(index: number, chunkBytes: number): void {
    const totalBytes = this.file.size;
    const percent = totalBytes === 0 ? 100 : Math.min(100, (this.uploadedBytes / totalBytes) * 100);
    const detail: ProgressDetail = {
      uploadedBytes: this.uploadedBytes,
      totalBytes,
      chunkIndex: index,
      chunkBytes,
    };
    this.options.onProgress(percent, index, this.totalChunks, detail);
  }

  private buildResult(): UploadResult {
    return {
      identifier: this.identifier,
      complete: true,
      totalChunks: this.totalChunks,
      totalBytes: this.file.size,
    };
  }

  private setPaused(next: boolean): void {
    if (this.paused === next) {
      return;
    }
    this.paused = next;
    if (next) {
      this.options.onPause();
    } else {
      this.options.onResume();
      // Wake a loop that is parked in `waitUntilResumed()`.
      this.releasePauseWaiter();
    }
  }

  private waitUntilResumed(): Promise<void> {
    if (!this.paused) {
      return Promise.resolve();
    }
    return new Promise<void>((resolve) => {
      this.resumeWaiter = resolve;
    });
  }

  private releasePauseWaiter(): void {
    const waiter = this.resumeWaiter;
    this.resumeWaiter = null;
    waiter?.();
  }

  private get signal(): AbortSignal | undefined {
    if (this.externalSignal !== undefined) {
      return this.externalSignal;
    }
    return this.controller?.signal;
  }

  private attachExternalSignal(): void {
    const external = this.externalSignal;
    if (external === undefined) {
      return;
    }
    if (external.aborted) {
      this.controller?.abort();
      return;
    }
    external.addEventListener('abort', this.onExternalAbort, { once: true });
  }

  private detachExternalSignal(): void {
    this.externalSignal?.removeEventListener('abort', this.onExternalAbort);
  }

  private readonly onExternalAbort = (): void => {
    this.controller?.abort();
    this.releasePauseWaiter();
  };

  private assertNotAborted(): void {
    const signal = this.signal;
    if (signal?.aborted === true) {
      throw new UploadAbortedError();
    }
  }

  private requireFetch(): typeof globalThis.fetch {
    const fetchImpl = this.options.fetch;
    if (typeof fetchImpl !== 'function') {
      throw new UploadConfigError(
        'No global fetch implementation was found. Pass options.fetch explicitly.',
      );
    }
    return fetchImpl;
  }
}

function noop(): void {
  /* intentionally empty */
}

function toError(value: unknown): Error {
  if (value instanceof Error) {
    return value;
  }
  return new Error(typeof value === 'string' ? value : JSON.stringify(value));
}
