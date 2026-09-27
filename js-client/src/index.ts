/**
 * `chunked-uploader-client`
 *
 * Typed, dependency-free browser client for the `resumable/chunked-uploader`
 * PHP backend.
 *
 * @packageDocumentation
 */

export { ChunkedUploader } from './uploader';

export {
  ChunkedUploaderError,
  UploadAbortedError,
  UploadConfigError,
  UploadHttpError,
  UploadNetworkError,
  UploadParseError,
  UploadRejectedError,
  UploadStateError,
} from './errors';
export type { UploadErrorCode } from './errors';

export type {
  BeforeRequestHook,
  ChunkResponse,
  ChunkedUploaderOptions,
  ErrorCallback,
  HeaderSource,
  PauseCallback,
  ProgressCallback,
  ProgressDetail,
  RequestContext,
  RequestInitOverride,
  RequestKind,
  ResolvedUploaderOptions,
  RetryCallback,
  ResumeCallback,
  SuccessCallback,
  UploadResult,
  UploadSource,
  UploadStatus,
} from './types';

export {
  DEFAULT_BACKOFF_BASE,
  DEFAULT_BACKOFF_MAX,
  DEFAULT_CHUNK_SIZE,
  DEFAULT_FILENAME,
  DEFAULT_RETRY_LIMIT,
} from './constants';

export { UnsupportedChecksumError } from './internal/checksum';
export type { DigestHook, RequestedChecksum } from './internal/checksum';

export { computeBackoffDelay } from './internal/backoff';
export { generateIdentifier } from './internal/identifier';
