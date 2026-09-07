<?php

declare(strict_types=1);

// File: src/Bridge/Laravel/Facades/ChunkUploader.php

namespace Resumable\ChunkedUploader\Bridge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Laravel facade for the chunked uploader manager.
 *
 * @method static UploadState    processChunk(Chunk $chunk) Handle a single incoming chunk.
 * @method static void           cancelUpload(string $identifier) Abort an upload and clean its chunks.
 * @method static UploadState|null getStatus(string $identifier) Read the state of an upload.
 */
final class ChunkUploader extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'chunk-uploader';
    }
}
