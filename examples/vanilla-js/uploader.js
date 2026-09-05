class ChunkedUploader {
  constructor(options) {
    this.file = options.file;
    this.endpoint = options.endpoint;
    this.chunkSize = options.chunkSize || 2 * 1024 * 1024;
    this.token = options.token;
    this.identifier = options.identifier || crypto.randomUUID().replaceAll('-', '');
    this.onProgress = options.onProgress || (() => {});
    this.onSuccess = options.onSuccess || (() => {});
    this.onError = options.onError || (() => {});
    this.paused = false;
    this.retryLimit = options.retryLimit || 5;
  }

  async start() {
    try {
      this.paused = false;
      const missing = await this.missingChunks();
      for (const index of missing) {
        while (this.paused) {
          await new Promise((resolve) => { this.resumeWaiter = resolve; });
        }
        await this.uploadChunk(index);
      }
      this.onSuccess({ identifier: this.identifier });
    } catch (error) {
      this.onError(error);
      throw error;
    }
  }

  pause() {
    this.paused = true;
  }

  resume() {
    this.paused = false;
    if (this.resumeWaiter) {
      this.resumeWaiter();
      this.resumeWaiter = null;
    }
    return this.start();
  }

  async missingChunks() {
    const response = await fetch(`${this.endpoint}/status/${encodeURIComponent(this.identifier)}`, {
      headers: { Accept: 'application/json' },
    });
    if (response.status === 404) {
      return Array.from({ length: this.totalChunks }, (_, index) => index);
    }
    if (!response.ok) {
      throw new Error(`Unable to query upload status (${response.status})`);
    }
    const data = await response.json();
    return Array.isArray(data.missingChunks) ? data.missingChunks : Array.from({ length: this.totalChunks }, (_, index) => index);
  }

  async uploadChunk(index) {
    const totalChunks = Math.ceil(this.file.size / this.chunkSize);
    this.totalChunks = totalChunks;
    const start = index * this.chunkSize;
    const end = Math.min(start + this.chunkSize, this.file.size);
    const blob = this.file.slice(start, end);
    const form = new FormData();
    form.append('chunk', blob, this.file.name);
    form.append('identifier', this.identifier);
    form.append('token', this.token);
    form.append('index', String(index));
    form.append('totalChunks', String(totalChunks));
    form.append('chunkSize', String(blob.size));
    form.append('totalSize', String(this.file.size));
    form.append('originalFilename', this.file.name);

    let lastError;
    for (let attempt = 0; attempt <= this.retryLimit; attempt += 1) {
      try {
        const response = await fetch(this.endpoint, { method: 'POST', body: form });
        if (!response.ok) {
          throw new Error(`Chunk ${index} failed (${response.status})`);
        }
        const completed = Math.min(end, this.file.size);
        this.onProgress(completed / this.file.size * 100, index, totalChunks);
        return await response.json();
      } catch (error) {
        lastError = error;
        if (attempt === this.retryLimit) {
          throw lastError;
        }
        await new Promise((resolve) => setTimeout(resolve, 250 * (2 ** attempt)));
      }
    }
    throw lastError;
  }
}

window.ChunkedUploader = ChunkedUploader;
