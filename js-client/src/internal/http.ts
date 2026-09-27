import { UploadAbortedError, UploadHttpError, UploadNetworkError, UploadParseError } from '../errors';
import type {
  BeforeRequestHook,
  ChunkResponse,
  HeaderSource,
  RequestInitOverride,
  RequestKind,
  UploadStatus,
} from '../types';

/** Header names are compared case-insensitively. */
const CONTENT_TYPE = 'content-type';

export interface PerformRequestInput {
  readonly url: string;
  readonly kind: RequestKind;
  readonly init: RequestInit;
  readonly index: number | undefined;
  readonly attempt: number;
  readonly identifier: string;
  readonly headers: HeaderSource | undefined;
  readonly beforeRequest: BeforeRequestHook | undefined;
  readonly credentials: RequestCredentials | undefined;
  readonly signal: AbortSignal | undefined;
  readonly fetchImpl: typeof globalThis.fetch;
  /** `status` requests expect JSON; chunk POSTs tolerate empty bodies. */
  readonly expectJson: boolean;
}

function isAbortError(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'name' in error &&
    (error as { name?: unknown }).name === 'AbortError'
  );
}

/**
 * Merge custom headers into an init without ever clobbering a `Content-Type`
 * the platform set for us. `FormData` bodies must keep the browser-generated
 * `multipart/form-data; boundary=...` header, so a user-supplied
 * `Content-Type` is dropped rather than forwarded.
 */
export async function buildInit(input: {
  readonly init: RequestInit;
  readonly headers: HeaderSource | undefined;
  readonly credentials: RequestCredentials | undefined;
  readonly signal: AbortSignal | undefined;
  readonly isMultipart: boolean;
}): Promise<RequestInit> {
  const headers = new Headers(input.init.headers);

  if (input.headers !== undefined) {
    const resolved = typeof input.headers === 'function' ? await input.headers() : input.headers;
    const custom = new Headers(resolved);
    custom.forEach((value, key) => {
      if (input.isMultipart && key.toLowerCase() === CONTENT_TYPE) {
        return;
      }
      headers.set(key, value);
    });
  }

  const merged: RequestInit = {
    ...input.init,
    headers,
    ...(input.credentials !== undefined ? { credentials: input.credentials } : {}),
    ...(input.signal !== undefined ? { signal: input.signal } : {}),
  };

  return merged;
}

/** Apply the `beforeRequest` hook, allowing both mutation and returned overrides. */
export async function applyBeforeRequest(
  hook: BeforeRequestHook | undefined,
  context: {
    readonly url: string;
    readonly kind: RequestKind;
    readonly index: number | undefined;
    readonly attempt: number;
    readonly identifier: string;
    readonly init: RequestInit;
  },
): Promise<RequestInit> {
  if (hook === undefined) {
    return context.init;
  }

  const overrides = await hook(context);

  if (overrides === undefined || overrides === null) {
    return context.init;
  }

  return { ...context.init, ...definedOnly(overrides) };
}

/** Drops keys explicitly set to `undefined` so they do not clobber the init. */
function definedOnly(override: RequestInitOverride): RequestInit {
  const result: Record<string, unknown> = {};

  for (const [key, value] of Object.entries(override)) {
    if (value !== undefined) {
      result[key] = value;
    }
  }

  return result;
}

/**
 * Execute one request and return its parsed JSON body.
 *
 * Translates transport-level failures into the typed error taxonomy and never
 * throws a raw `unknown`.
 */
export async function performRequest(input: PerformRequestInput): Promise<ChunkResponse> {
  const init = await buildInit({
    init: input.init,
    headers: input.headers,
    credentials: input.credentials,
    signal: input.signal,
    isMultipart: typeof (input.init.body as { append?: unknown } | undefined)?.append === 'function',
  });

  const withHook = await applyBeforeRequest(input.beforeRequest, {
    url: input.url,
    kind: input.kind,
    index: input.index,
    attempt: input.attempt,
    identifier: input.identifier,
    init,
  });

  let response: Response;

  try {
    response = await input.fetchImpl(input.url, withHook);
  } catch (error) {
    if (isAbortError(error) || input.signal?.aborted === true) {
      throw new UploadAbortedError();
    }
    const message = error instanceof Error ? error.message : String(error);
    throw new UploadNetworkError(
      `Request to ${input.url} failed: ${message}`,
      error instanceof Error ? { cause: error } : undefined,
    );
  }

  if (!response.ok) {
    // A 404 on the status probe is the documented "unknown upload" signal and
    // is handled by the caller, so it is not treated as a hard failure here.
    const body = await readBodySafely(response);
    throw new UploadHttpError(
      `${input.kind === 'status' ? 'Upload status' : `Chunk ${String(input.index)}`} rejected (HTTP ${response.status})`,
      { status: response.status, url: input.url, body },
    );
  }

  if (!input.expectJson) {
    const text = await readBodySafely(response);
    if (text === undefined || text.trim() === '') {
      return {};
    }
    return parseJson(text, input.url);
  }

  const text = await readBodySafely(response);
  if (text === undefined || text.trim() === '') {
    return {};
  }
  return parseJson(text, input.url);
}

async function readBodySafely(response: Response): Promise<string | undefined> {
  try {
    return await response.text();
  } catch {
    return undefined;
  }
}

function parseJson(text: string, url: string): ChunkResponse {
  try {
    const parsed: unknown = JSON.parse(text);
    if (typeof parsed !== 'object' || parsed === null) {
      return {};
    }
    return parsed;
  } catch {
    throw new UploadParseError(`Response from ${url} was not valid JSON.`, text);
  }
}

/**
 * Normalise a status payload into missing-chunk indices.
 *
 * Tolerates the PHP backend's `{ missingChunks: [...] }` shape, a bare array,
 * and a missing/garbage field (treated as "upload everything").
 */
export function normaliseStatus(
  payload: ChunkResponse,
  identifier: string,
  totalChunks: number,
): UploadStatus {
  const raw: unknown = payload.missingChunks;
  const list = Array.isArray(raw) ? raw : [];

  const missing = list
    .map((value) => (typeof value === 'number' ? value : Number(value)))
    .filter((value) => Number.isInteger(value) && value >= 0 && value < totalChunks)
    .sort((a, b) => a - b);

  const hasUsableField = Array.isArray(raw);
  const completed = payload.completed === true;

  return {
    identifier: typeof payload.identifier === 'string' ? payload.identifier : identifier,
    missingChunks: hasUsableField && !completed ? missing : hasUsableField ? [] : range(totalChunks),
    completed,
  };
}

function range(count: number): number[] {
  return Array.from({ length: count }, (_, index) => index);
}
