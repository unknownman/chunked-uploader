'use strict';

/**
 * ChunkedUploader
 *
 * Dependency-free resumable upload client. Splits a File into binary chunks
 * with Blob.prototype.slice(), sends them sequentially to a backend endpoint
 * as multipart/form-data via the Fetch API, retries transient failures with
 * exponential backoff, and can resume a partially uploaded file by asking the
 * server which chunks are still missing.
 *
 * Usage:
 *   const uploader = new ChunkedUploader({
 *     file,
 *     endpoint: '/upload',
 *     chunkSize: 2 * 1024 * 1024,
 *     token: 'hmac-token',
 *     onProgress: (percent, index, total) => { ... },
 *     onSuccess: (result) => { ... },
 *     onError: (error) => { ... },
 *   });
 *   await uploader.start();
 */
class ChunkedUploader {
  /**
   * @param {Object} options
   * @param {File}   options.file       The file to upload.
   * @param {string} options.endpoint   Base URL (status is {endpoint}/status/{id}).
   * @param {number} [options.chunkSize] Byte size of each chunk (default 2 MiB).
   * @param {string} [options.token]    HMAC token issued by the backend.
   * @param {string} [options.identifier] Reuse an identifier to resume instead of
   *                                      generating a new one.
   * @param {Function} [options.onProgress] (percent:number, index:number, total:number)
   * @param {Function} [options.onSuccess]  (serverResult:Object)
   * @param {Function} [options.onError]    (error:Error)
   * @param {number} [options.retryLimit]   Max attempts per chunk (default 5).
   * @param {number} [options.backoffBase]  Initial backoff ms (default 250).
   */
    constructor(options)
    {
        this.file = options.file;
        this.endpoint = options.endpoint.replace(/\/$/, '');
        this.chunkSize = options.chunkSize || 2 * 1024 * 1024;
        this.token = options.token || '';
        this.identifier = options.identifier || crypto.randomUUID().replaceAll('-', '');
        this.onProgress = options.onProgress || (() => {});
        this.onSuccess = options.onSuccess || (() => {});
        this.onError = options.onError || (() => {});
        this.retryLimit = options.retryLimit || 5;
        this.backoffBase = options.backoffBase || 250;

        this.totalChunks = Math.max(0, Math.ceil(this.file.size / this.chunkSize));
        this.paused = false;
        this._resumeWaiter = null;
        this._running = false;
    }

  /**
   * Begins (or continues) the upload. Asks the server for missing chunks
   * first so a previously interrupted upload can resume rather than restart.
   *
   * @returns {Promise<Object>} Resolves with the final server response.
   * @throws {Error} When retries are exhausted or the server rejects the file.
   */
    async start()
    {
        if (this._running) {
            throw new Error('Upload is already running.');
        }
        this._running = true;
        this.paused = false;
        try {
            const missing = await this.missingChunks();
            for (const index of missing.sort((a, b) => a - b)) {
                if (this.paused) {
                    await this._waitUntilResumed();
                }
                await this.uploadChunk(index);
            }
            const result = { identifier: this.identifier, complete: true };
            this.onSuccess(result);
            return result;
        } catch (error) {
            this.onError(error);
            throw error;
        } finally {
            this._running = false;
        }
    }

  /**
   * Pauses the upload after the in-flight chunk finishes.
   */
    pause()
    {
        this.paused = true;
    }

  /**
   * Resumes a paused (or previously failed) upload from where it left off.
   *
   * @returns {Promise<Object>} Same promise semantics as start().
   */
    async resume()
    {
        this.paused = false;
        if (this._resumeWaiter) {
            const resolve = this._resumeWaiter;
            this._resumeWaiter = null;
            resolve();
        }
        if (this._running) {
            return undefined;
        }
        return this.start();
    }

  /**
   * Queries the backend for the set of chunk indices that still need to be
   * uploaded. If the backend has never heard of the identifier it responds
   * 404, in which case every chunk from 0..N-1 is considered missing.
   *
   * @returns {Promise<number[]>} Ascending list of missing chunk indices.
   */
    async missingChunks()
    {
        const url = `${this.endpoint}/status/${encodeURIComponent(this.identifier)}`;
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (response.status === 404) {
            return Array.from({ length: this.totalChunks }, (_, i) => i);
        }
        if (!response.ok) {
            throw new Error(`Unable to query upload status(HTTP ${response.status})`);
        }
        const data = await response.json();
        const missing = Array.isArray(data.missingChunks)
        ? data.missingChunks.map(Number)
        : Array.from({ length: this.totalChunks }, (_, i) => i);
        return missing.filter((i) => i >= 0 && i < this.totalChunks);
    }

  /**
   * Transfers one chunk with automatic retry and exponential backoff.
   *
   * @param {number} index Zero-based chunk index.
   * @returns {Promise<Object>} Server JSON response for that chunk.
   */
    async uploadChunk(index)
    {
        const start = index * this.chunkSize;
        const end = Math.min(start + this.chunkSize, this.file.size);
        const blob = this.file.slice(start, end);

        const form = new FormData();
        form.append('chunk', blob, this.file.name);
        form.append('identifier', this.identifier);
        form.append('token', this.token);
        form.append('index', String(index));
        form.append('totalChunks', String(this.totalChunks));
        form.append('chunkSize', String(blob.size));
        form.append('totalSize', String(this.file.size));
        form.append('originalFilename', this.file.name);

        let lastError;
        for (let attempt = 0; attempt <= this.retryLimit; attempt += 1) {
            if (attempt > 0) {
                const delay = this.backoffBase * (2 ** (attempt - 1));
                await new Promise((resolve) => setTimeout(resolve, delay));
            }
            try {
                const response = await fetch(this.endpoint, { method: 'POST', body: form });
                if (!response.ok) {
                    throw new Error(`Chunk ${index} rejected(HTTP ${response.status})`);
                }
                const data = await response.json();
                const uploaded = Math.min(end, this.file.size);
                this.onProgress((uploaded / this.file.size) * 100, index, this.totalChunks);
                return data;
            } catch (error) {
                lastError = error;
                if (attempt === this.retryLimit) {
                    throw lastError;
                }
            }
        }
        throw lastError;
    }

    _waitUntilResumed()
    {
        return new Promise((resolve) => {
            this._resumeWaiter = resolve;
        });
    }
}

if (typeof window !== 'undefined') {
    window.ChunkedUploader = ChunkedUploader;
}
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ChunkedUploader;
}
