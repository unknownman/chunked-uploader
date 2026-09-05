<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core;

use Psr\Log\LoggerInterface;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Contracts\AssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\StorageInterface;
use Resumable\ChunkedUploader\Core\Events\ChunkUploadedEvent;
use Resumable\ChunkedUploader\Core\Events\FileAssembledEvent;
use Resumable\ChunkedUploader\Core\Events\UploadFailedEvent;
use Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Facade/Coordinator of the chunked upload pipeline.
 *
 * Orchestrates the full lifecycle of a chunk: validation, storage persistence,
 * metadata state transition, event dispatch, and (on the final chunk) stream
 * assembly. All dependencies are injected interfaces, keeping this class a thin
 * mediator rather than a god object.
 */
class ChunkUploader
{
    public function __construct(
        private readonly UploaderConfig $config,
        private readonly StorageInterface $storage,
        private readonly MetadataRepositoryInterface $metadata,
        private readonly AssemblerInterface $assembler,
        private readonly ChunkValidatorInterface $validator,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Processes a single incoming chunk through the entire pipeline.
     *
     * The chunk is validated (MIME magic bytes, checksum, security) before any
     * persistence. The upload state is then loaded or created, advanced, and
     * saved atomically. When the last chunk is persisted, assembly is triggered
     * and the final path is stored on the returned state.
     *
     * @throws \Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException
     * @throws \Resumable\ChunkedUploader\Core\Exceptions\InvalidChunkException
     * @throws \Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException
     */
    public function processChunk(Chunk $chunk): UploadState
    {
        $this->validator->validate($chunk);
        $this->assertChunkWithinLimits($chunk);

        $existing = $this->metadata->get($chunk->identifier);
        $state = $existing ?? $this->createInitialState($chunk);

        if ($existing === null) {
            $this->metadata->save($state);
        }

        if ($state->hasChunk($chunk->index)) {
            return $state;
        }

        $this->storage->storeChunk($chunk);

        $state = $state->withUploadedChunk($chunk->index);
        $this->metadata->save($state);

        $this->logger->info('Chunk stored', [
            'identifier' => $chunk->identifier,
            'index' => $chunk->index,
        ]);

        $this->dispatcher->dispatch(new ChunkUploadedEvent($state, $chunk));

        if ($state->isComplete()) {
            return $this->assembleAndFinalize($state);
        }

        return $state;
    }

    /**
     * Ends an incomplete upload, cleaning up its persisted chunks.
     */
    public function cancel(string $identifier): void
    {
        $state = $this->metadata->get($identifier);

        if ($state === null) {
            return;
        }

        $this->storage->deleteChunks($state);
        $this->metadata->delete($identifier);

        $this->logger->info('Upload cancelled', ['identifier' => $identifier]);
    }

    /**
     * Returns the current state of an upload without altering it.
     */
    public function status(string $identifier): ?UploadState
    {
        return $this->metadata->get($identifier);
    }

    /**
     * Assembles the final file, persists the new state, and emits lifecycle events.
     */
    private function assembleAndFinalize(UploadState $state): UploadState
    {
        try {
            $finalPath = $this->assembler->assemble($state, $this->storage);

            $finalState = $state->withFinalPath($finalPath);
            $this->metadata->save($finalState);

            $this->logger->info('File assembled', [
                'identifier' => $state->identifier,
                'finalPath' => $finalPath,
            ]);

            $this->dispatcher->dispatch(new FileAssembledEvent($finalState, $finalPath));

            return $finalState;
        } catch (\Throwable $exception) {
            $this->storage->deleteChunks($state);
            $this->metadata->delete($state->identifier);

            $this->logger->error('Upload assembly failed', [
                'identifier' => $state->identifier,
                'exception' => $exception,
            ]);

            $this->dispatcher->dispatch(new UploadFailedEvent($state, $exception));

            throw new UploadFailedException(
                sprintf('Assembly of upload "%s" failed: %s', $state->identifier, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * Builds the initial persisted state for a brand-new upload.
     */
    private function createInitialState(Chunk $chunk): UploadState
    {
        return new UploadState(
            identifier: $chunk->identifier,
            originalFilename: $chunk->originalName,
            totalChunks: $chunk->totalChunks,
            totalSize: 0,
            expiresAt: new \DateTimeImmutable(
                sprintf('+%d seconds', $this->config->garbageCollectionTtl),
            ),
        );
    }

    /**
     * Enforces chunk-index bounds and the configured chunk-count ceiling as a
     * DoS guard before any state mutation occurs.
     */
    private function assertChunkWithinLimits(Chunk $chunk): void
    {
        if ($chunk->index < 0 || $chunk->index >= $chunk->totalChunks) {
            throw new InvalidChunkException(
                sprintf(
                    'Chunk index %d is out of bounds for an upload of %d chunks.',
                    $chunk->index,
                    $chunk->totalChunks,
                ),
            );
        }

        if ($chunk->totalChunks > $this->config->maxChunks) {
            throw new InvalidChunkException(
                sprintf(
                    'Upload declares %d chunks which exceeds the configured maximum of %d.',
                    $chunk->totalChunks,
                    $this->config->maxChunks,
                ),
            );
        }
    }
}