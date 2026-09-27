import { describe, expect, it, vi } from 'vitest';
import { ChunkedUploader, UnsupportedChecksumError } from '../src/index';
import {
  createFetchStub,
  makeDistinctFile,
  makeFile,
  readForm,
  testOptions,
} from './helpers';

const FILE_SIZE = 1_000;
const CHUNK_SIZE = 400; // -> 3 chunks: 400, 400, 200

/**
 * The exact bytes the uploader will send for chunk `index`.
 *
 * Mirrors makeDistinctFile()'s pattern, so a digest computed over the wrong
 * slice produces a value that cannot match.
 */
function chunkBytes(index: number): Uint8Array {
  const start = index * CHUNK_SIZE;
  const end = Math.min(start + CHUNK_SIZE, FILE_SIZE);
  const bytes = new Uint8Array(end - start);
  for (let i = start; i < end; i += 1) {
    bytes[i - start] = (i * 7 + (i >> 8)) & 0xff;
  }
  return bytes;
}

function base64(bytes: Uint8Array): string {
  return Buffer.from(bytes).toString('base64');
}

function hex(bytes: Uint8Array): string {
  return Buffer.from(bytes).toString('hex');
}

/** Installs a deterministic `crypto.subtle` so digests are assertable. */
function stubSubtleDigest(): void {
  vi.spyOn(globalThis.crypto, 'subtle', 'get').mockReturnValue({
    digest: vi.fn(async (_algorithm: string, data: ArrayBuffer) => data),
  } as unknown as SubtleCrypto);
}

describe('checksum: wire format', () => {
  it('sends a base64 SHA-256 of the chunk bytes by default', async () => {
    stubSubtleDigest();
    const stub = createFetchStub({ missing: [0, 1, 2] });

    await new ChunkedUploader(
      testOptions({ file: makeDistinctFile(FILE_SIZE), fetch: stub.fetch as typeof fetch }),
    ).start();

    const form = readForm(stub.chunkCalls[0]?.init);
    // The stub returns the raw bytes, so the field must be their base64 form.
    // This is the encoding the PHP backend decodes for S3.
    expect(form.get('checksum')).toBe(base64(chunkBytes(0)));
  });

  it('digests each chunk over that chunk\'s own byte range', async () => {
    stubSubtleDigest();
    const stub = createFetchStub({ missing: [0, 1, 2] });

    await new ChunkedUploader(
      testOptions({ file: makeDistinctFile(FILE_SIZE), fetch: stub.fetch as typeof fetch }),
    ).start();

    // A whole-file digest would let the backend validate a chunk it never saw.
    const digests = stub.chunkCalls.map((call) => readForm(call.init).get('checksum'));
    expect(digests).toEqual([
      base64(chunkBytes(0)),
      base64(chunkBytes(1)),
      base64(chunkBytes(2)),
    ]);
    // The last chunk is short; hashing the full slice would be a real bug.
    expect(chunkBytes(2).length).toBe(200);
    expect(new Set(digests).size).toBe(3);
  });

  it('omits the field entirely when checksums are disabled', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2] });

    await new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch, checksum: false }),
    ).start();

    // Omitted rather than sent empty: the backend can then tell "no digest
    // supplied" from "digest was empty", which are different situations.
    expect(readForm(stub.chunkCalls[0]?.init).has('checksum')).toBe(false);
  });

  it('routes md5 through an injected digest hook', async () => {
    const digest = vi.fn(async (): Promise<Uint8Array> => new Uint8Array(16).fill(7));
    const stub = createFetchStub({ missing: [0, 1, 2] });

    await new ChunkedUploader(
      testOptions({
        file: makeDistinctFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        checksum: 'md5',
        digest,
      }),
    ).start();

    // WebCrypto has no MD5, so an implementation must be supplied.
    expect(digest).toHaveBeenCalled();
    expect(readForm(stub.chunkCalls[0]?.init).get('checksum')).toBe(base64(new Uint8Array(16).fill(7)));
  });

  it('computes the digest once per chunk, not once per retry', async () => {
    const digest = vi.fn(async (): Promise<Uint8Array> => new Uint8Array(32).fill(1));
    const stub = createFetchStub({ missing: [0, 1, 2], failChunks: new Set([0]) });

    await new ChunkedUploader(
      testOptions({
        file: makeDistinctFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        digest,
      }),
    ).start();

    // Chunk 0 was attempted twice but the bytes cannot have changed between
    // attempts, so re-digesting would only burn main-thread time.
    const attemptsForZero = stub.chunkCalls.filter(
      (call) => readForm(call.init).get('index') === '0',
    ).length;
    expect(attemptsForZero).toBe(2);
    expect(digest).toHaveBeenCalledTimes(3);
  });

  it('reuses one digest value across a chunk retry', async () => {
    const digest = vi.fn(async (): Promise<Uint8Array> => new Uint8Array(32).fill(1));
    const stub = createFetchStub({ missing: [0, 1, 2], failChunks: new Set([0]) });

    await new ChunkedUploader(
      testOptions({ file: makeDistinctFile(FILE_SIZE), fetch: stub.fetch as typeof fetch, digest }),
    ).start();

    const sentForZero = stub.chunkCalls
      .filter((call) => readForm(call.init).get('index') === '0')
      .map((call) => readForm(call.init).get('checksum'));
    expect(new Set(sentForZero).size).toBe(1);
  });

  it('fails fast when md5 is requested without a digest implementation', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2] });

    const uploader = new ChunkedUploader(
      testOptions({ file: makeFile(FILE_SIZE), fetch: stub.fetch as typeof fetch, checksum: 'md5' }),
    );

    // WebCrypto cannot produce MD5, and silently skipping the digest would
    // leave the caller believing uploads were verified.
    await expect(uploader.start()).rejects.toBeInstanceOf(UnsupportedChecksumError);
    expect(stub.chunkCalls).toHaveLength(0);
  });

  it('omits the digest when WebCrypto is unavailable rather than failing the upload', async () => {
    vi.spyOn(globalThis.crypto, 'subtle', 'get').mockReturnValue(undefined as unknown as SubtleCrypto);
    const stub = createFetchStub({ missing: [0, 1, 2] });

    // Plain HTTP origins have no crypto.subtle. Refusing to upload there would
    // break a working setup, so the field is simply left out.
    await new ChunkedUploader(
      testOptions({ file: makeDistinctFile(FILE_SIZE), fetch: stub.fetch as typeof fetch }),
    ).start();

    expect(readForm(stub.chunkCalls[0]?.init).has('checksum')).toBe(false);
  });

  it('surfaces a digest hook failure as an upload error, not a retry', async () => {
    const stub = createFetchStub({ missing: [0, 1, 2] });
    const boom = new Error('no digest for you');

    const uploader = new ChunkedUploader(
      testOptions({
        file: makeDistinctFile(FILE_SIZE),
        fetch: stub.fetch as typeof fetch,
        digest: (): Promise<Uint8Array> => {
          throw boom;
        },
      }),
    );

    await expect(uploader.start()).rejects.toBeDefined();
    // Hashing is a precondition for the request, so a failure must not look
    // like a flaky network and trigger the retry ladder.
    expect(stub.chunkCalls).toHaveLength(0);
  });

  it('keeps hex and base64 distinguishable for the backend', async () => {
    const bytes = new Uint8Array([0xde, 0xad, 0xbe, 0xef].concat(new Array(28).fill(0x01)));
    const digest = vi.fn(async (): Promise<Uint8Array> => bytes);
    const stub = createFetchStub({ missing: [0] });

    await new ChunkedUploader(
      testOptions({ file: makeDistinctFile(CHUNK_SIZE), fetch: stub.fetch as typeof fetch, digest }),
    ).start();

    // Base64 of bytes containing high values includes characters that hex
    // cannot express, which is exactly why the backend cannot assume hex.
    const sent = readForm(stub.chunkCalls[0]?.init).get('checksum') as string;
    expect(sent).not.toBe(hex(bytes));
    expect(Buffer.from(sent, 'base64').toString('hex')).toBe(hex(bytes));
  });
});
