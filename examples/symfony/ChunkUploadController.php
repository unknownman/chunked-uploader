<?php

declare(strict_types=1);

// File: examples/symfony/ChunkUploadController.php

namespace App\Controller;

use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkUploaderException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Core\UploadManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Minimal, copy-pasteable Symfony controller exposing the chunked upload
 * workflow: token issuance, chunk ingestion, and a status/resume endpoint.
 *
 * The controller is constructor-injected with the core services; wire them in
 * config/services.yaml (see the bundle docs). Attribute routing is used, so a
 * modern Symfony 6/7 app needs no extra route configuration.
 */
final class ChunkUploadController
{
    public function __construct(
        private readonly UploadManager $uploader,
        private readonly MetadataRepositoryInterface $metadata,
        private readonly ProgressTrackerInterface $progress,
        private readonly PathSanitizer $sanitizer,
        private readonly UploadTokenService $tokens,
    ) {
    }

    /**
     * Issue an upload token bound to the client context.
     */
    #[Route('/upload/token', name: 'upload_issue_token', methods: ['POST'])]
    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $identifier = $this->sanitizer->sanitizeIdentifier((string) ($data['identifier'] ?? ''));
        $totalChunks = (int) ($data['totalChunks'] ?? 0);
        $totalSize = (int) ($data['totalSize'] ?? 0);

        if ($identifier === '' || $totalChunks < 1 || $totalSize < 1) {
            return new JsonResponse(['error' => 'Invalid token request.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'identifier' => $identifier,
            'token' => $this->tokens->createToken(
                $identifier,
                $totalChunks,
                $totalSize,
                (string) $request->getClientIp(),
            ),
            'totalChunks' => $totalChunks,
            'totalSize' => $totalSize,
        ]);
    }

    /**
     * Ingest a single chunk. Idempotent across network retries.
     */
    #[Route('/upload', name: 'upload_store_chunk', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $file = $request->files->get('chunk');
        if ($file === null || !$file->isValid() || $file->getRealPath() === false) {
            return new JsonResponse(['error' => 'Invalid chunk upload.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $identifier = $this->sanitizer->sanitizeIdentifier(
                (string) $request->request->get('identifier', ''),
            );
            $originalName = (string) $request->request->get(
                'originalFilename',
                $file->getClientOriginalName(),
            );
            $original = $this->sanitizer->sanitizeFilename($originalName);
        } catch (ChunkUploaderException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $chunk = new Chunk(
            identifier: $identifier,
            token: (string) $request->request->get('token', ''),
            index: (int) $request->request->get('index', -1),
            totalChunks: (int) $request->request->get('totalChunks', 0),
            chunkSize: (int) $request->request->get('chunkSize', $file->getSize() ?? 0),
            totalSize: (int) $request->request->get('totalSize', 0),
            tmpFilePath: (string) $file->getRealPath(),
            originalFilename: $original,
        );

        try {
            $state = $this->uploader->processChunk($chunk);
        } catch (ChunkUploaderException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'identifier' => $state->identifier,
            'uploadedChunks' => $state->uploadedChunks,
            'missingChunks' => $this->progress->getMissingChunkIndices($state),
            'completed' => $state->isCompleted,
            'finalPath' => $state->finalPath,
        ]);
    }

    /**
     * Status/resume endpoint: returns which chunks are still missing so the
     * client only re-sends what the server does not have.
     */
    #[Route('/upload/status/{identifier}', name: 'upload_status', methods: ['GET'])]
    public function status(string $identifier): JsonResponse
    {
        try {
            $identifier = $this->sanitizer->sanitizeIdentifier($identifier);
        } catch (ChunkUploaderException) {
            return new JsonResponse(['error' => 'Upload not found.'], Response::HTTP_NOT_FOUND);
        }

        $state = $this->metadata->get($identifier);
        if ($state === null) {
            return new JsonResponse(['error' => 'Upload not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'identifier' => $state->identifier,
            'uploadedChunks' => $state->uploadedChunks,
            'missingChunks' => $this->progress->getMissingChunkIndices($state),
            'completed' => $state->isCompleted,
            'finalPath' => $state->finalPath,
        ]);
    }
}
