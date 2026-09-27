/**
 * Client-side chunk digests for end-to-end integrity checking.
 *
 * The digest is sent alongside the part so the object store verifies the bytes
 * *it* received. Hashing in PHP instead would only prove that the copy the web
 * server wrote to local disk is intact, which cannot detect corruption that
 * happened on the wire between PHP and S3.
 */

/** Digest algorithms this module can compute without a third-party dependency. */
export type ChecksumAlgorithm = 'sha256';

/** Anything the caller can ask for; `md5` needs a custom implementation. */
export type RequestedChecksum = ChecksumAlgorithm | 'md5' | false;

/**
 * Injectable digest used for algorithms WebCrypto does not implement.
 *
 * `crypto.subtle.digest` supports the SHA family only -- there is no MD5 -- so
 * asking for MD5 requires the application to supply one.
 *
 * The hook returns raw digest **bytes**, not an encoded string: `digestChunk`
 * owns the wire encoding, so a hook cannot accidentally emit hex where base64 is
 * required and silently produce a digest the backend rejects.
 */
export type DigestHook = (
  blob: Blob,
  algorithm: RequestedChecksum,
) => Promise<DigestBytes> | DigestBytes;

/** Raw digest output accepted from a {@see DigestHook}. */
export type DigestBytes = Uint8Array | ArrayBuffer;

/** Thrown when a requested algorithm cannot be computed. */
export class UnsupportedChecksumError extends Error {
  constructor(algorithm: string) {
    super(
      `Cannot compute a ${algorithm} checksum. ` +
        `crypto.subtle supports the SHA family (sha256, sha384, sha512) but not ${algorithm}; ` +
        `pass a \`digest\` hook to supply a ${algorithm} implementation, or use checksum: 'sha256'.`,
    );
    this.name = 'UnsupportedChecksumError';
  }
}

/**
 * WebCrypto digest name for each algorithm this module can compute natively.
 */
const NATIVE_NAMES: Readonly<Record<ChecksumAlgorithm, string>> = {
  sha256: 'SHA-256',
};

function subtle(): SubtleCrypto | undefined {
  const candidate = (globalThis as { crypto?: Crypto }).crypto;
  return candidate?.subtle;
}

/**
 * Computes a digest of `blob` and returns it **base64-encoded**.
 *
 * Base64 is the format the S3 REST API expects (`ChecksumSHA256` and
 * `ContentMD5` are both base64), so choosing it here means the backend can hand
 * the value straight to the SDK with no re-encoding. The PHP backend also
 * accepts hex, which is the friendlier format to log and to eyeball.
 */
export async function digestChunk(
  blob: Blob,
  algorithm: RequestedChecksum,
  hook: DigestHook | undefined,
): Promise<string | undefined> {
  if (algorithm === false) {
    return undefined;
  }

  if (hook !== undefined) {
    return base64(toBytes(await hook(blob, algorithm)));
  }

  const name = NATIVE_NAMES[algorithm as ChecksumAlgorithm];
  if (name === undefined) {
    throw new UnsupportedChecksumError(algorithm);
  }

  const crypto = subtle();
  if (crypto === undefined) {
    // `crypto.subtle` is only exposed in secure contexts. Uploading from a
    // plain-HTTP origin is a real deployment, so degrade to "no digest" rather
    // than failing an upload that would otherwise succeed.
    return undefined;
  }

  // A reused streaming source has already been consumed; slice() re-arms it.
  const buffer = await blob.arrayBuffer();
  const digest = await crypto.digest(name, buffer);

  return base64(new Uint8Array(digest));
}

/** Normalises either accepted buffer representation to bytes. */
function toBytes(value: DigestBytes): Uint8Array {
  return value instanceof Uint8Array ? value : new Uint8Array(value);
}

/** Encodes bytes as base64 without assuming Node's `Buffer` is present. */
export function base64(bytes: Uint8Array): string {
  let binary = '';
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }

  const encoder = (globalThis as { btoa?: (input: string) => string }).btoa;
  if (encoder !== undefined) {
    return encoder(binary);
  }

  // Node.js has no `btoa` on older runtimes; Buffer is the equivalent.
  const nodeBuffer = (globalThis as { Buffer?: { from(input: string, encoding: string): { toString(encoding: string): string } } })
    .Buffer;
  if (nodeBuffer !== undefined) {
    return nodeBuffer.from(binary, 'binary').toString('base64');
  }

  throw new Error('No base64 encoder available in this environment.');
}
