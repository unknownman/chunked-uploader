<?php

declare(strict_types=1);

namespace App\Controller;

use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Core\UploadManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ChunkUploadController
{
    public function __construct(private readonly UploadManager $uploader, private readonly MetadataRepositoryInterface $metadata, private readonly PathSanitizer $sanitizer, private readonly UploadTokenService $tokens)
    {
    }

    #[Route('/upload/token', methods: ['POST'])]
    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $identifier = $this->sanitizer->sanitizeIdentifier((string) ($data['identifier'] ?? ''));
        $chunks = (int) ($data['totalChunks'] ?? 0);
        $size = (int) ($data['totalSize'] ?? 0);
        return new JsonResponse(['identifier' => $identifier, 'token' => $this->tokens->createToken($identifier, $chunks, $size, (string) $request->getClientIp())]);
    }

    #[Route('/upload', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $file = $request->files->get('chunk');
        if ($file === null || !$file->isValid() || $file->getRealPath() === false) {
            return new JsonResponse(['error' => 'Invalid chunk upload.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $identifier = $this->sanitizer->sanitizeIdentifier((string) $request->request->get('identifier'));
        $original = $this->sanitizer->sanitizeFilename((string) $request->request->get('originalFilename', $file->getClientOriginalName()));
        $chunk = new Chunk($identifier, (string) $request->request->get('token'), (int) $request->request->get('index'), (int) $request->request->get('totalChunks'), (int) $request->request->get('chunkSize'), (int) $request->request->get('totalSize'), (string) $file->getRealPath(), $original);
        $state = $this->uploader->processChunk($chunk);
        return new JsonResponse(['uploadedChunks' => $state->uploadedChunks, 'completed' => $state->isCompleted, 'finalPath' => $state->finalPath]);
    }

    #[Route('/upload/status/{identifier}', methods: ['GET'])]
    public function status(string $identifier): JsonResponse
    {
        $state = $this->metadata->get($this->sanitizer->sanitizeIdentifier($identifier));
        if ($state === null) {
            return new JsonResponse(['error' => 'Upload not found.'], Response::HTTP_NOT_FOUND);
        }
        return new JsonResponse(['uploadedChunks' => $state->uploadedChunks, 'missingChunks' => array_values(array_diff(range(0, $state->totalChunks - 1), $state->uploadedChunks)), 'completed' => $state->isCompleted]);
    }
}
