<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Models;

/**
 * Lightweight, immutable upload state used across the core components.
 */
final readonly class UploadState
{
    /**
     * @param string $identifier
     * @param int $totalChunks
     * @param int $totalSize
     * @param string $originalFilename
     * @param array<int, int> $uploadedChunks
     * @param bool $isCompleted
     * @param string|null $finalPath
     */
    public function __construct(
        public string $identifier,
        public int $totalChunks,
        public int $totalSize,
        public string $originalFilename,
        public array $uploadedChunks = [],
        public bool $isCompleted = false,
        public ?string $finalPath = null,
    ) {
    }

    public function hasChunk(int $index): bool
    {
        return in_array($index, $this->uploadedChunks, true);
    }

    public function isComplete(): bool
    {
        return $this->isCompleted || count($this->uploadedChunks) === $this->totalChunks;
    }

    public function withUploadedChunk(int $index): self
    {
        if ($this->hasChunk($index)) {
            return $this;
        }

        $chunks = $this->uploadedChunks;
        $chunks[] = $index;
        sort($chunks, SORT_NUMERIC);

        $isComplete = count($chunks) === $this->totalChunks;

        return new self(
            identifier: $this->identifier,
            totalChunks: $this->totalChunks,
            totalSize: $this->totalSize,
            originalFilename: $this->originalFilename,
            uploadedChunks: $chunks,
            isCompleted: $isComplete,
            finalPath: $this->finalPath,
        );
    }

    public function withFinalPath(string $path): self
    {
        return new self(
            identifier: $this->identifier,
            totalChunks: $this->totalChunks,
            totalSize: $this->totalSize,
            originalFilename: $this->originalFilename,
            uploadedChunks: $this->uploadedChunks,
            isCompleted: true,
            finalPath: $path,
        );
    }
}
