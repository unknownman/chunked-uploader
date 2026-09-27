import type { ChunkedUploaderOptions } from '../src/types';

/** Minimal structurally-typed stand-in for `globalThis.fetch`. */
export type FetchMock = (url: string, init?: RequestInit) => Promise<Response>;

export const ENDPOINT = 'https://example.test/upload';

/** Builds a `Response` with a JSON body, mirroring the PHP backend. */
export function jsonResponse(
  body: unknown,
  status = 200,
): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

/** A 404 "unknown upload" probe response, as returned for unseen identifiers. */
export function notFound(): Response {
  return jsonResponse({ error: 'Upload not found.' }, 404);
}

/** An acknowledged-chunk response, as the PHP controllers return. */
export function chunkAck(identifier: string, uploaded: number[]): Response {
  return jsonResponse({
    identifier,
    uploadedChunks: uploaded,
    missingChunks: [],
    completed: uploaded.length >= 3,
  });
}

export interface Deferred<T> {
  readonly promise: Promise<T>;
  resolve: (value: T) => void;
  reject: (reason: unknown) => void;
}

export function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

/** Creates an in-memory File of `size` zero bytes without touching the disk. */
export function makeFile(size: number, name = 'payload.bin'): File {
  return new File([new Uint8Array(size)], name, { type: 'application/octet-stream' });
}

/**
 * Creates a File whose byte value varies with its offset.
 *
 * Prefer this over {@link makeFile} when asserting per-chunk digests: a
 * zero-filled file gives byte-identical chunks, so a bug that hashed the wrong
 * slice of the file would go unnoticed.
 */
export function makeDistinctFile(size: number, name = 'payload.bin'): File {
  const bytes = new Uint8Array(size);
  for (let i = 0; i < size; i += 1) {
    bytes[i] = (i * 7 + (i >> 8)) & 0xff;
  }
  return new File([bytes], name, { type: 'application/octet-stream' });
}

/**
 * Reads a FormData body out of a RequestInit.
 *
 * Note: the body is already a `FormData` instance, and re-wrapping it with
 * `new FormData(existing)` is not portable across runtimes, so we hand the
 * instance straight back.
 */
export function readForm(init: RequestInit | undefined): FormData {
  const body: unknown = init?.body;
  if (body instanceof FormData) {
    return body;
  }
  throw new Error(`Expected a FormData body, received ${Object.prototype.toString.call(body)}.`);
}

/**
 * A fetch double that answers the status probe with `missing` and every chunk
 * POST with a success ack, while recording every call for assertions.
 */
export function createFetchStub(options: {
  readonly missing?: number[];
  readonly completed?: boolean;
  readonly chunkStatus?: number;
  readonly failChunks?: ReadonlySet<number>;
}): {
  readonly fetch: FetchMock;
  readonly statusCalls: string[];
  readonly statusInits: (RequestInit | undefined)[];
  readonly chunkCalls: { url: string; init: RequestInit | undefined }[];
} {
  const statusCalls: string[] = [];
  const statusInits: (RequestInit | undefined)[] = [];
  const chunkCalls: { url: string; init: RequestInit | undefined }[] = [];
  const attempts = new Map<number, number>();

  const fetchMock: FetchMock = async (url, init) => {
    if ((init?.method ?? 'GET') === 'GET') {
      statusCalls.push(url);
      statusInits.push(init);
      if (options.missing === undefined) {
        return notFound();
      }
      return jsonResponse({
        identifier: 'test-id',
        missingChunks: options.missing,
        completed: options.completed ?? false,
      });
    }

    chunkCalls.push({ url, init });
    const index = Number(readForm(init).get('index'));
    const attempt = (attempts.get(index) ?? 0) + 1;
    attempts.set(index, attempt);

    // `failChunks` fails only the *first* attempt, modelling a transient fault.
    if (options.failChunks?.has(index) === true && attempt === 1) {
      return jsonResponse({ error: 'nope' }, options.chunkStatus ?? 500);
    }
    return chunkAck('test-id', [index]);
  };

  return { fetch: fetchMock, statusCalls, statusInits, chunkCalls };
}

/** Options pre-wired for tests: no real timers, injected fetch. */
export function testOptions(
  overrides: Partial<ChunkedUploaderOptions> & Pick<ChunkedUploaderOptions, 'file'>,
): ChunkedUploaderOptions {
  return {
    endpoint: ENDPOINT,
    identifier: 'test-id',
    chunkSize: 400,
    sleep: () => Promise.resolve(),
    ...overrides,
  };
}

/** Collects the `index` field of every FormData body that was sent. */
export function sentIndices(
  chunkCalls: readonly { init: RequestInit | undefined }[],
): number[] {
  return chunkCalls.map((call) => Number(readForm(call.init).get('index')));
}

/** Silences the "unhandled rejection" noise when a test intentionally fails. */
export function captureRejection(promise: Promise<unknown>): Promise<unknown> {
  return promise.then(
    (value) => value,
    (error: unknown) => error,
  );
}
