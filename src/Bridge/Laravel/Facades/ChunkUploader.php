<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Bridge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Laravel facades for ChunkUploader.
 *
 * @method static UploadState processChunk(Chunk $chunk) Handle a single incoming chunk.
 * @method static void        cancel(string $identifier) Abort an upload and clean its chunks.
 * @method static UploadState|null status(string $identifier) Read the state of an upload.
 */
final class ChunkUploader extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'chunk-uploader';
    }
}