<?php

declare(strict_types=1);

// File: examples/laravel/ChunkUploadController.php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use ReflectionMethod;
use Resumable\ChunkedUploader\Core\Attributes\AllowedMimes;
use Resumable\ChunkedUploader\Core\Attributes\MaxFileSize;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Configuration\ChunkedUploadConfigResolver;
use Resumable\ChunkedUploader\Core\Configuration\UploaderConfig;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkUploaderException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Core\ChunkUploader;
use Resumable\ChunkedUploader\Core\Attributes\ChunkedUpload;

/**
 * Minimal, copy-pasteable Laravel controller exposing the chunked upload
 * workflow: token issuance, chunk ingestion, and a status/resume endpoint.
 *
 * Wire it into routes/web.php (or routes/api.php) with:
 *
 *   Route::post('/upload/token', [ChunkUploadController::class, 'issueToken']);
 *   Route::post('/upload',       [ChunkUploadController::class, 'store']);
 *   Route::get('/upload/status/{identifier}', [ChunkUploadController::class, 'status']);
 */
final class ChunkUploadController
{
    public function __construct(
        private readonly ChunkUploader $uploader,
        private readonly MetadataRepositoryInterface $metadata,
        private readonly ProgressTrackerInterface $progress,
        private readonly PathSanitizer $sanitizer,
        private readonly UploadTokenService $tokens,
        private readonly UploaderConfig $config,
        private readonly ChunkedUploadConfigResolver $configResolver,
    ) {
    }

    /**
     * Issuing an upload token lets the client prove (via HMAC) that it
     * launched a legitimate upload with a known chunk set and size.
     */
    public function issueToken(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'identifier' => ['required', 'string', 'max:128'],
            'totalChunks' => ['required', 'integer', 'min:1', 'max:1000'],
            'totalSize' => ['required', 'integer', 'min:1'],
        ])->validate();

        $identifier = $this->sanitizer->sanitizeIdentifier((string) $data['identifier']);
        $totalChunks = (int) $data['totalChunks'];
        $totalSize = (int) $data['totalSize'];

        return response()->json([
            'identifier' => $identifier,
            'token' => $this->tokens->createToken($identifier, $totalChunks, $totalSize, (string) $request->ip()),
            'chunkSize' => (int) config('chunk-uploader.max_chunk_size', 2 * 1024 * 1024),
            'totalChunks' => $totalChunks,
            'totalSize' => $totalSize,
        ]);
    }

    /**
     * Ingests a single chunk. Idempotent: retrying the same index is safe and
     * the server will neither duplicate bytes nor corrupt the final file.
     */
    #[ChunkedUpload(tokenSalt: 'media-endpoint')]
    #[AllowedMimes(['video/mp4'])]
    #[MaxFileSize(50 * 1024 * 1024)]
    public function store(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'identifier' => ['required', 'string', 'max:128'],
            'token' => ['required', 'string'],
            'index' => ['required', 'integer', 'min:0'],
            'totalChunks' => ['required', 'integer', 'min:1'],
            'totalSize' => ['required', 'integer', 'min:1'],
            'chunkSize' => ['integer', 'min:1'],
            'originalFilename' => ['required', 'string', 'max:255'],
        ])->validate();

        $file = $request->file('chunk');
        abort_unless($file !== null && $file->isValid(), 422, 'Invalid chunk upload.');

        $identifier = $this->sanitizer->sanitizeIdentifier((string) $data['identifier']);
        $originalName = (string) ($data['originalFilename'] ?? $file->getClientOriginalName());
        $original = $this->sanitizer->sanitizeFilename($originalName);

        $chunk = new Chunk(
            identifier: $identifier,
            token: (string) $data['token'],
            index: (int) $data['index'],
            totalChunks: (int) $data['totalChunks'],
            chunkSize: (int) ($data['chunkSize'] ?? $file->getSize() ?? 0),
            totalSize: (int) $data['totalSize'],
            tmpFilePath: (string) $file->getRealPath(),
            originalFilename: $original,
        );

        try {
            $config = $this->configResolver->resolve(new ReflectionMethod(self::class, __FUNCTION__), $this->config);
            $state = $this->uploader->processChunk($chunk, $config);
        } catch (ChunkUploaderException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'identifier' => $state->identifier,
            'uploadedChunks' => $state->uploadedChunks,
            'missingChunks' => $this->progress->getMissingChunkIndices($state),
            'completed' => $state->isCompleted,
            'finalPath' => $state->finalPath,
        ]);
    }

    /**
     * Status/resume endpoint the client queries before re-uploading so it can
     * skip chunks the server already holds.
     */
    public function status(string $identifier): JsonResponse
    {
        try {
            $identifier = $this->sanitizer->sanitizeIdentifier($identifier);
        } catch (ChunkUploaderException) {
            abort(404, 'Upload not found.');
        }

        $state = $this->metadata->get($identifier);
        abort_if($state === null, 404, 'Upload not found.');

        return response()->json([
            'identifier' => $state->identifier,
            'uploadedChunks' => $state->uploadedChunks,
            'missingChunks' => $this->progress->getMissingChunkIndices($state),
            'completed' => $state->isCompleted,
            'finalPath' => $state->finalPath,
        ]);
    }
}
