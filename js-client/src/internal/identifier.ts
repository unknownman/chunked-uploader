/**
 * Identifier generation.
 *
 * Prefers `crypto.randomUUID()` and strips the dashes to produce a
 * dash-free identifier, which keeps the value safe for the PHP backend's
 * identifier sanitiser and for use inside URLs and filenames. Falls back to
 * `crypto.getRandomValues()` and finally to a time+counter based value so the
 * client still works over plain HTTP and in older runtimes.
 */
export function generateIdentifier(): string {
  const cryptoRef: Crypto | undefined = globalThis.crypto;

  if (typeof cryptoRef?.randomUUID === 'function') {
    return cryptoRef.randomUUID().replaceAll('-', '');
  }

  const bytes = new Uint8Array(16);

  if (typeof cryptoRef?.getRandomValues === 'function') {
    cryptoRef.getRandomValues(bytes);
  } else {
    for (let i = 0; i < bytes.length; i += 1) {
      bytes[i] = Math.floor(Math.random() * 256);
    }
  }

  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}
