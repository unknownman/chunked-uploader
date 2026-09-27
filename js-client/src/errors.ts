/**
 * Typed error taxonomy. Every failure surfaced through `onError` and the
 * rejected promise is an `Error` (as documented by the callback contract),
 * but callers that want to branch on *why* an upload stopped can use the
 * `code` discriminant instead of matching on message strings.
 */

export type UploadErrorCode =
  | 'INVALID_OPTION'
  | 'ALREADY_RUNNING'
  | 'HTTP_ERROR'
  | 'NETWORK_ERROR'
  | 'ABORTED'
  | 'BAD_JSON'
  | 'SERVER_REJECTED';

export class ChunkedUploaderError extends Error {
  public readonly code: UploadErrorCode;

  constructor(message: string, code: UploadErrorCode, options?: { cause?: unknown }) {
    super(message, options);
    this.code = code;
    Object.setPrototypeOf(this, new.target.prototype);
  }
}

/** Thrown synchronously by the constructor for nonsensical options. */
export class UploadConfigError extends ChunkedUploaderError {
  constructor(message: string) {
    super(message, 'INVALID_OPTION');
    // Hard-coded rather than `new.target.name`, which minifiers rewrite.
    this.name = 'UploadConfigError';
  }
}

/** `start()` called twice without an intervening `await`. */
export class UploadStateError extends ChunkedUploaderError {
  constructor(message: string) {
    super(message, 'ALREADY_RUNNING');
    this.name = 'UploadStateError';
  }
}

/** The server answered with a non-2xx status. */
export class UploadHttpError extends ChunkedUploaderError {
  public readonly status: number;
  public readonly url: string;
  public readonly body: string | undefined;

  constructor(
    message: string,
    details: { status: number; url: string; body?: string | undefined },
  ) {
    super(message, 'HTTP_ERROR');
    this.name = 'UploadHttpError';
    this.status = details.status;
    this.url = details.url;
    this.body = details.body;
  }
}

/** `fetch` itself rejected (DNS, offline, CORS, TLS...). */
export class UploadNetworkError extends ChunkedUploaderError {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, 'NETWORK_ERROR', options);
    this.name = 'UploadNetworkError';
  }
}

/** The request was cancelled through the supplied `AbortSignal`. */
export class UploadAbortedError extends ChunkedUploaderError {
  constructor(message = 'Upload aborted.') {
    super(message, 'ABORTED');
    this.name = 'UploadAbortedError';
  }
}

/** A 2xx response whose body was not the JSON we expected. */
export class UploadParseError extends ChunkedUploaderError {
  public readonly body: string;

  constructor(message: string, body: string) {
    super(message, 'BAD_JSON');
    this.name = 'UploadParseError';
    this.body = body;
  }
}

/** The backend acknowledged the request but reported a domain-level failure. */
export class UploadRejectedError extends ChunkedUploaderError {
  constructor(message: string) {
    super(message, 'SERVER_REJECTED');
    this.name = 'UploadRejectedError';
  }
}
