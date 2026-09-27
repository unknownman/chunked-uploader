/**
 * Quick-start: the minimum viable integration.
 *
 * Run the typecheck with:  npm run typecheck
 */
import {
  ChunkedUploader,
  UploadHttpError,
  type UploadResult,
} from '../src/index';

const fileInput = document.querySelector<HTMLInputElement>('#file');
const progressBar = document.querySelector<HTMLProgressElement>('#progress');
const pauseButton = document.querySelector<HTMLButtonElement>('#pause');
const resumeButton = document.querySelector<HTMLButtonElement>('#resume');

let uploader: ChunkedUploader | null = null;

async function upload(file: File): Promise<UploadResult> {
  uploader = new ChunkedUploader({
    file,
    endpoint: '/upload',
    chunkSize: 2 * 1024 * 1024, // 2 MiB
    token: readTokenFromMeta(),

    // Send auth headers with every request (status probe included).
    headers: { Authorization: `Bearer ${sessionStorage.getItem('api_token') ?? ''}` },

    // ...or compute them per request, e.g. a short-lived signed URL.
    beforeRequest: async ({ kind, url }) => ({
      headers: { 'X-Upload-Intent': kind === 'status' ? 'probe' : 'store' },
      cache: url.includes('/status/') ? 'no-store' : undefined,
    }),

    retryLimit: 5, // 1 attempt + up to 5 retries
    backoffBase: 250, // 250ms, 500ms, 1s, 2s, 4s ...

    onProgress: (percent, index, total, detail) => {
      if (progressBar !== null) {
        progressBar.value = percent;
      }
      console.log(`${percent.toFixed(1)}% — chunk ${index + 1}/${total} (${detail.uploadedBytes} bytes)`);
    },
    onSuccess: (result) => {
      console.log(`Upload ${result.identifier} complete.`);
    },
    onError: (error) => {
      if (error instanceof UploadHttpError) {
        console.error(`Server rejected the upload (HTTP ${error.status}).`);
        return;
      }
      console.error(error.message);
    },
  });

  return uploader.start();
}

pauseButton?.addEventListener('click', () => {
  uploader?.pause();
});

resumeButton?.addEventListener('click', () => {
  void uploader?.resume();
});

fileInput?.addEventListener('change', () => {
  const file = fileInput.files?.[0];
  if (file !== undefined) {
    void upload(file).catch(() => {
      /* already surfaced through onError */
    });
  }
});

function readTokenFromMeta(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="upload-token"]')?.content ?? '';
}
