import { describe, expect, it, vi } from 'vitest';
import { ChunkedUploader } from '../src/index';
import { UploadConfigError, UploadHttpError, UploadStateError } from '../src/index';
import {
  ENDPOINT,
  captureRejection,
  chunkAck,
  createFetchStub,
  deferred,
  jsonResponse,
  makeFile,
  readForm,
  sentIndices,
  testOptions,
  type FetchMock,
} from './helpers';

const FILE_SIZE = 1_000;
const CHUNK_SIZE = 400; // -> 3 chunks: 400, 400, 200

describe('ChunkedUploader: construction', () => {
  it('derives chunk count from file size and chunk size', () => {
    const uploader = new ChunkedUploader(testOptions({ file: makeFile(FILE_SIZE) }));
    expect(uploader.totalChunks).toBe(3);
    expect(uploader.chunkSize).toBe(400);
  });

  it('strips a trailing slash from the endpoint', () => {
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(10), endpoint: 'https://example.test/upload///' }),
    );
    expect(uploader.endpoint).toBe('https://example.test/upload');
  });

  it('generates a dash-free identifier when none is supplied', async () => {
    const randomUUID = vi.spyOn(globalThis.crypto, 'randomUUID');
    const uploader = new ChunkedUploader({
      file: makeFile(10),
      endpoint: ENDPOINT,
    });
    expect(randomUUID).toHaveBeenCalledTimes(1);
    expect(uploader.identifier).toMatch(/^[0-9a-f]{32}$/);
  });

  it('rejects invalid options', () => {
    expect(() => new ChunkedUploader(testOptions({ file: makeFile(0) }))).toThrow(UploadConfigError);
    expect(() =>
      new ChunkedUploader(testOptions({ file: makeFile(10), chunkSize: 0 })),
    ).toThrow(/positive integer/);
    expect(() =>
      new ChunkedUploader(testOptions({ file: makeFile(10), retryLimit: -1 })),
    ).toThrow(/non-negative integer/);
    expect(() =>
      new ChunkedUploader(testOptions({ file: makeFile(10), endpoint: '  ' })),
    ).toThrow(/non-empty string/);
    expect(() =>
      new ChunkedUploader(testOptions({ file: makeFile(10), backoffJitter: 2 })),
    ).toThrow(/backoffJitter/);
  });

  it('falls back to a default filename for a bare Blob', () => {
    const uploader = new ChunkedUploader(
      testOptions({ file: new Blob([new Uint8Array(10)]) }),
    );
    expect(uploader.filename).toBe('upload.bin');
  });
});

describe('ChunkedUploader: upload flow', () => {
  it('queries status, uploads every chunk in order and reports success', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2] });
    const onProgress = vi.fn();
    const onSuccess = vi.fn();
    const onError = vi.fn();

    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch, onProgress, onSuccess, onError }),
    );

    const result = await uploader.start();

    expect(stub.statusCalls).toEqual([`${ENDPOINT}/status/test-id`]);
    expect(sentIndices(stub.chunkCalls)).toEqual([0, 1, 2]);
    expect(result).toEqual({
      identifier: 'test-id',
      complete: true,
      totalChunks: 3,
      totalBytes: FILE_SIZE,
    });
    expect(onSuccess).toHaveBeenCalledWith(result);
    expect(onError).not.toHaveBeenCalled();
  });

  it('reports cumulative progress across chunks', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2] });
    const onProgress = vi.fn();
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch, onProgress }),
    );

    await uploader.start();

    const percents = onProgress.mock.calls.map((call) => call[0] as number);
    expect(percents).toEqual([40, 80, 100]);

    const last = onProgress.mock.calls.at(-1) as [number, number, number, { uploadedBytes: number }];
    expect(last[1]).toBe(2);
    expect(last[2]).toBe(3);
    expect(last[3].uploadedBytes).toBe(FILE_SIZE);
  });

  it('sends the exact form fields the PHP validators require', async () => {
    const stub = createFetchStub({ missing: [0] });
    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE, 'movie.mp4'),
        fetch: stub.fetch as typeof fetch,
        chunkSize: CHUNK_SIZE,
      }),
    );
    await uploader.uploadChunk(0);

    const body = readForm(stub.chunkCalls[0]?.init);
    expect(body.get('identifier')).toBe('test-id');
    expect(body.get('token')).toBe('');
    expect(body.get('index')).toBe('0');
    expect(body.get('totalChunks')).toBe('3');
    expect(body.get('totalSize')).toBe(String(FILE_SIZE));
    expect(body.get('chunkSize')).toBe('400');
    expect(body.get('originalFilename')).toBe('movie.mp4');
    expect((body.get('chunk') as File).size).toBe(400);
  });

  it('treats a 404 status probe as "upload everything"', async () => {
    const stub = createFetchStub({});
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch }),
    );

    await expect(uploader.missingChunks()).resolves.toEqual([0, 1, 2]);

    await uploader.start();
    expect(sentIndices(stub.chunkCalls)).toEqual([0, 1, 2]);
  });

  it('only uploads the chunks the backend reports as missing', async () => {
    const stub = createFetchStub({ missing: [2] });
    const onProgress = vi.fn();
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch, onProgress }),
    );

    await uploader.start();

    expect(sentIndices(stub.chunkCalls)).toEqual([2]);
    expect(onProgress).toHaveBeenCalledTimes(1);
  });

  it('short-circuits when the backend already completed the upload', async () => {
    const stub = createFetchStub({ missing: [], completed: true });
    const onSuccess = vi.fn();
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch, onSuccess }),
    );

    const result = await uploader.start();

    expect(stub.chunkCalls).toHaveLength(0);
    expect(result.complete).toBe(true);
    expect(onSuccess).toHaveBeenCalledTimes(1);
  });

  it('rejects out-of-range chunk indices', async () => {
    const stub = createFetchStub({ missing: [0] });
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch }),
    );
    await expect(uploader.uploadChunk(99)).rejects.toThrow(UploadConfigError);
  });

  it('refuses to start twice concurrently', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2] });
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch }),
    );

    const first = uploader.start();
    expect(() => uploader.start()).toThrow(UploadStateError);
    await first;
  });
});

describe('ChunkedUploader: retry and backoff', () => {
  it('retries a transient failure and succeeds', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2], failChunks: new Set([1]) });
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch }),
    );

    const result = await uploader.start();
    expect(result.complete).toBe(true);
    // chunk 1 was attempted twice.
    expect(sentIndices(stub.chunkCalls)).toEqual([0, 1, 1, 2]);
  });

  it('backs off exponentially between retries', async () => {
    const delays: number[] = [];
    let attempts = 0;
    const fetchMock: FetchMock = async (_url, init) => {
      if ((init?.method ?? 'GET') === 'GET') {
        return jsonResponse({ identifier: 'test-id', missingChunks: [0], completed: false });
      }
      attempts += 1;
      return attempts < 4
        ? jsonResponse({ error: 'boom' }, 500)
        : chunkAck('test-id', [0]);
    };

    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        backoffBase: 100,
        fetch: fetchMock as typeof fetch,
        sleep: async (ms) => {
          delays.push(ms);
        },
      }),
    );

    await uploader.start();
    expect(delays).toEqual([100, 200, 400]);
  });

  it('does not retry non-retryable client errors', async () => {
    const stub = createFetchStub({
      missing: [0, 1, 2],
      failChunks: new Set([0]),
      chunkStatus: 422,
    });
    const onError = vi.fn();
    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        onError,
      }),
    );

    const error = await captureRejection(uploader.start());

    expect(error).toBeInstanceOf(UploadHttpError);
    expect((error as UploadHttpError).status).toBe(422);
    expect(sentIndices(stub.chunkCalls)).toEqual([0]);
    expect(onError).toHaveBeenCalledTimes(1);
  });

  it('surfaces the last error once retries are exhausted', async () => {
    let attempts = 0;
    const fetchMock: FetchMock = async (_url, init) => {
      if ((init?.method ?? 'GET') === 'GET') {
        return jsonResponse({ identifier: 'test-id', missingChunks: [0], completed: false });
      }
      attempts += 1;
      return jsonResponse({ error: 'boom' }, 503);
    };

    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        retryLimit: 2,
        fetch: fetchMock as typeof fetch,
      }),
    );

    const error = await captureRejection(uploader.start());
    expect((error as UploadHttpError).status).toBe(503);
    // 1 initial attempt + 2 retries
    expect(attempts).toBe(3);
  });

  it('rejects a 2xx response that reports a domain-level error', async () => {
    const fetchMock: FetchMock = async (_url, init) => {
      if ((init?.method ?? 'GET') === 'GET') {
        return jsonResponse({ identifier: 'test-id', missingChunks: [0], completed: false });
      }
      return jsonResponse({ error: 'Checksum mismatch.' });
    };

    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: fetchMock as typeof fetch }),
    );

    const error = await captureRejection(uploader.start());
    expect((error as Error).message).toMatch(/Checksum mismatch/);
  });

  it('wraps transport failures', async () => {
    const fetchMock: FetchMock = async () => {
      throw new TypeError('Failed to fetch');
    };

    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        retryLimit: 1,
        fetch: fetchMock as typeof fetch,
      }),
    );

    const error = await captureRejection(uploader.start());
    expect((error as Error).message).toMatch(/Failed to fetch/);
  });

  it('reports non-2xx status probes as HTTP errors', async () => {
    const fetchMock: FetchMock = async () => new Response('nope', { status: 500 });

    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: fetchMock as typeof fetch }),
    );

    const error = await captureRejection(uploader.start());
    expect((error as UploadHttpError).status).toBe(500);
  });

  it('notifies onRetry with the scheduled delay', async () => {
    const onRetry = vi.fn();
    let attempts = 0;
    const fetchMock: FetchMock = async (_url, init) => {
      if ((init?.method ?? 'GET') === 'GET') {
        return jsonResponse({ identifier: 'test-id', missingChunks: [0], completed: false });
      }
      attempts += 1;
      return attempts < 2 ? new Response('', { status: 500 }) : chunkAck('test-id', [0]);
    };

    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        backoffBase: 50,
        fetch: fetchMock as typeof fetch,
        onRetry,
      }),
    );

    await uploader.start();
    expect(onRetry).toHaveBeenCalledWith(1, expect.any(Error), 50);
  });
});

describe('ChunkedUploader: headers and beforeRequest', () => {
  it('merges static headers into every request', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2] });
    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        headers: { Authorization: 'Bearer abc123', 'X-CSRF-Token': 'csrf' },
      }),
    );

    await uploader.start();

    for (const call of stub.chunkCalls) {
      const headers = new Headers(call.init?.headers);
      expect(headers.get('Authorization')).toBe('Bearer abc123');
      expect(headers.get('X-CSRF-Token')).toBe('csrf');
    }
    const statusHeaders = new Headers(stub.statusInits[0]?.headers);
    expect(statusHeaders.get('Accept')).toBe('application/json');
    expect(statusHeaders.get('Authorization')).toBe('Bearer abc123');
  });

  it('resolves headers from an async factory', async () => {
    const stub = createFetchStub({ missing: [0] });
    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        headers: async () => ({ Authorization: 'Bearer fresh' }),
      }),
    );

    await uploader.start();
    const headers = new Headers(stub.chunkCalls[0]?.init?.headers);
    expect(headers.get('Authorization')).toBe('Bearer fresh');
  });

  it('never lets a custom Content-Type break the multipart boundary', async () => {
    const stub = createFetchStub({ missing: [0] });
    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    await uploader.start();

    const headers = new Headers(stub.chunkCalls[0]?.init?.headers);
    expect(headers.get('Content-Type')).toBeNull();
  });

  it('lets beforeRequest inject headers per request kind', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2] });
    const kinds: string[] = [];
    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        beforeRequest: (ctx) => {
          kinds.push(ctx.kind);
          return { headers: { 'X-Trace': `${ctx.kind}-${ctx.attempt}` } };
        },
      }),
    );

    await uploader.start();

    expect(kinds[0]).toBe('status');
    const chunkHeaders = new Headers(stub.chunkCalls[0]?.init?.headers);
    expect(chunkHeaders.get('X-Trace')).toBe('chunk-0');
  });

  it('supports async beforeRequest hooks that receive the chunk index', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2] });
    const seen: (number | undefined)[] = [];
    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        beforeRequest: async (ctx) => {
          seen.push(ctx.index);
          return { cache: 'no-store' };
        },
      }),
    );

    await uploader.start();

    expect(seen).toEqual([undefined, 0, 1, 2]);
    expect(stub.chunkCalls[0]?.init?.cache).toBe('no-store');
  });

  it('ignores override keys explicitly set to undefined', async () => {
    const stub = createFetchStub({ missing: [0] });
    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        beforeRequest: (ctx) => {
          // A conditional override written the ergonomic way.
          return ctx.kind === 'status' ? { cache: 'no-store' } : { cache: undefined };
        },
      }),
    );

    await uploader.start();

    // `cache: undefined` must not clobber anything the uploader computed.
    expect(stub.chunkCalls[0]?.init?.cache).toBeUndefined();
    expect(stub.statusInits[0]?.cache).toBe('no-store');
  });

  it('lets beforeRequest mutate the computed init in place', async () => {
    const stub = createFetchStub({ missing: [0] });
    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        beforeRequest: (ctx) => {
          ctx.init.keepalive = true;
        },
      }),
    );

    await uploader.start();
    expect(stub.chunkCalls[0]?.init?.keepalive).toBe(true);
  });
});

describe('ChunkedUploader: pause and resume', () => {
  it('halts before the next chunk and continues on resume', async () => {
    const requested: number[] = [];
    const gate = deferred<Response>();

    const fetchMock: FetchMock = async (_url, init) => {
      if ((init?.method ?? 'GET') === 'GET') {
        return jsonResponse({ identifier: 'test-id', missingChunks: [0, 1, 2], completed: false });
      }
      const index = Number(readForm(init).get('index'));
      requested.push(index);
      // Hold chunk 0 open so we can pause while it is in flight.
      return index === 0 ? gate.promise : chunkAck('test-id', [index]);
    };

    const onPause = vi.fn();
    const onResume = vi.fn();
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: fetchMock as typeof fetch, onPause, onResume }),
    );

    const run = uploader.start();
    await vi.waitFor(() => expect(requested).toEqual([0]));

    // Pausing mid-flight is allowed; the in-flight chunk still settles.
    uploader.pause();
    expect(uploader.isPaused).toBe(true);
    expect(onPause).toHaveBeenCalledTimes(1);

    gate.resolve(chunkAck('test-id', [0]));
    await new Promise((resolve) => setTimeout(resolve, 20));

    // Chunk 0 completed, but chunk 1 must not have been dispatched.
    expect(requested).toEqual([0]);
    expect(uploader.isRunning).toBe(true);

    const resumed = uploader.resume();
    expect(uploader.isPaused).toBe(false);
    expect(onResume).toHaveBeenCalledTimes(1);

    await expect(run).resolves.toMatchObject({ complete: true });
    await expect(resumed).resolves.toMatchObject({ complete: true });
    expect(requested).toEqual([0, 1, 2]);
  });

  it('shares the in-flight promise across repeated resume() calls', async () => {
    const gate = deferred<Response>();
    const fetchMock: FetchMock = async (_url, init) => {
      if ((init?.method ?? 'GET') === 'GET') {
        return jsonResponse({ identifier: 'test-id', missingChunks: [0], completed: false });
      }
      return gate.promise;
    };

    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: fetchMock as typeof fetch }),
    );

    const first = uploader.start();
    uploader.pause();
    expect(() => uploader.resume()).not.toThrow(UploadStateError);
    const second = uploader.resume();
    const third = uploader.resume();
    expect(second).toBe(first);
    expect(third).toBe(first);

    gate.resolve(chunkAck('test-id', [0]));
    await expect(first).resolves.toMatchObject({ complete: true });
    await expect(second).resolves.toMatchObject({ complete: true });
  });

  it('starts a fresh run when resuming after a completed upload', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2] });
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch }),
    );

    await uploader.start();
    expect(uploader.isRunning).toBe(false);

    await uploader.resume();
    expect(sentIndices(stub.chunkCalls)).toEqual([0, 1, 2, 0, 1, 2]);
  });

  it('is a no-op to pause when not running', () => {
    const onPause = vi.fn();
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), onPause }),
    );
    uploader.pause();
    expect(uploader.isPaused).toBe(true);
    expect(onPause).toHaveBeenCalledTimes(1);
  });
});

describe('ChunkedUploader: cancellation', () => {
  it('aborts an in-flight upload', async () => {
    const fetchMock: FetchMock = async (_url, init) => {
      if ((init?.method ?? 'GET') === 'GET') {
        return jsonResponse({ identifier: 'test-id', missingChunks: [0, 1, 2], completed: false });
      }
      return new Promise<Response>((_resolve, reject) => {
        init?.signal?.addEventListener('abort', () => {
          const abortError = new Error('aborted');
          abortError.name = 'AbortError';
          reject(abortError);
        });
      });
    };

    const onError = vi.fn();
    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: fetchMock as typeof fetch, onError }),
    );

    const run = uploader.start();
    await vi.waitFor(() => expect(uploader.isRunning).toBe(true));
    uploader.abort();

    const error = await captureRejection(run);
    expect((error as Error).name).toBe('UploadAbortedError');
    expect(onError).toHaveBeenCalledTimes(1);
  });

  it('honours an external AbortSignal', async () => {
    const controller = new AbortController();
    const fetchMock: FetchMock = async (_url, init) => {
      if ((init?.method ?? 'GET') === 'GET') {
        return jsonResponse({ identifier: 'test-id', missingChunks: [0], completed: false });
      }
      return new Promise<Response>((_resolve, reject) => {
        init?.signal?.addEventListener('abort', () => {
          const abortError = new Error('aborted');
          abortError.name = 'AbortError';
          reject(abortError);
        });
      });
    };

    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        fetch: fetchMock as typeof fetch,
        signal: controller.signal,
      }),
    );

    const run = uploader.start();
    await vi.waitFor(() => expect(uploader.isRunning).toBe(true));
    controller.abort();

    const error = await captureRejection(run);
    expect((error as Error).name).toBe('UploadAbortedError');
  });

  it('fails fast when the signal is already aborted', async () => {
    const fetchMock = vi.fn() as unknown as FetchMock;
    const uploader = new ChunkedUploader(
      testOptions({
        file: makeFile(FILE_SIZE),
        fetch: fetchMock as typeof fetch,
        signal: AbortSignal.abort(),
      }),
    );

    const error = await captureRejection(uploader.start());
    expect((error as Error).name).toBe('UploadAbortedError');
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
