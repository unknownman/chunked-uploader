<?php

declare(strict_types=1);

// File: src/Core/Contracts/FileAssemblerInterface.php

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Exceptions\AssemblyException;
use Resumable\ChunkedUploader\Core\Exceptions\MissingChunkException;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Securely merges the persisted chunks of an upload into one final file.
 *
 * The assembler iterates the expected chunks in ascending index order and pipes
 * each chunk stream into a single destination stream using stream_copy_to_stream()
 * or equivalent native streaming primitives. All data movement is
 * stream-to-stream, guaranteeing constant (O(1)) memory usage regardless of the
 * final file size -- the assembled file is never buffered into PHP memory.
 */
interface FileAssemblerInterface
{
    /**
     * Assembles every stored chunk of the state into the final artifact.
     *
     * Chunks are consumed in ascending index order from the given storage
     * implementation; out-of-order or missing chunks abort the operation before
     * any partial output is left behind. On success the assembled artifact is
     * available at the returned location, which may be an absolute filesystem
     * path or a storage URI depending on the concrete driver in use.
     *
     * @param UploadState           $state   The upload whose chunks are assembled
     * @param ChunkStorageInterface $storage Chunk source used to stream each part
     *
     * @return string Absolute path or storage URI of the final assembled file
     *
     * @throws MissingChunkException when a required chunk index is absent during iteration
     * @throws AssemblyException     when a stream write fails or the medium reports full
     */
    public function assemble(UploadState $state, ChunkStorageInterface $storage): string;
}
