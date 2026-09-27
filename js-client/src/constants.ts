/** Default 2 MiB — matches the chunk size used by the PHP examples. */
export const DEFAULT_CHUNK_SIZE = 2 * 1024 * 1024;

/** Retries *after* the first attempt, so `0` means "try once, never retry". */
export const DEFAULT_RETRY_LIMIT = 5;

export const DEFAULT_BACKOFF_BASE = 250;
export const DEFAULT_BACKOFF_MAX = 30_000;

export const DEFAULT_FILENAME = 'upload.bin';

export const STATUS_PATH = 'status';
