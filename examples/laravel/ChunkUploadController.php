<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Core\UploadManager;

final class ChunkUploadController
{
    public function __construct(
        private readonly UploadManager $uploader,
        private readonly MetadataRepositoryInterface $metadata,
        private readonly PathSanitizer $sanitizer,
        private readonly UploadTokenService $tokens,
    ) {
    }

    public function issueToken(Request $request): JsonResponse
    {
        $identifier = $this->sanitizer->sanitizeIdentifier((string) $request->input('identifier'));
        $chunks = (int) $request->input('totalChunks');
        $size = (int) $request->input('totalSize');
        return response()->json(['identifier' => $identifier, 'token' => $this->tokens->createToken($identifier, $chunks, $size, (string) $request->ip())]);
    }

    public function store(Request $request): JsonResponse
    {
        $file = $request->file('chunk');
        abort_unless($file !== null && $file->isValid(), 422, 'Invalid chunk upload.');
        $identifier = $this->sanitizer->sanitizeIdentifier((string) $request->input('identifier'));
        $original = $this->sanitizer->sanitizeFilename((string) $request->input('originalFilename', $file->getClientOriginalName()));
        $chunk = new Chunk($identifier, (string) $request->input('token'), (int) $request->input('index'), (int) $request->input('totalChunks'), (int) $request->input('chunkSize'), (int) $request->input('totalSize'), $file->getRealPath(), $original);
        $state = $this->uploader->processChunk($chunk);
        return response()->json(['uploadedChunks' => $state->uploadedChunks, 'completed' => $state->isCompleted, 'finalPath' => $state->finalPath]);
    }

    public function status(string $identifier): JsonResponse
    {
        $state = $this->metadata->get($this->sanitizer->sanitizeIdentifier($identifier));
        abort_if($state === null, 404);
        return response()->json(['uploadedChunks' => $state->uploadedChunks, 'missingChunks' => array_values(array_diff(range(0, $state->totalChunks - 1), $state->uploadedChunks)), 'completed' => $state->isCompleted]);
    }
}
