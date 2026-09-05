<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Responsibly merges all persisted chunks of an upload into a single final file.
 *
 * Implementations MUST operate in a strictly stream-based manner (fopen,
 * stream_copy_to_stream, ...) guaranteeing that memory usage stays constant
 * regardless of file or chunk count.
 */
interface AssemblerInterface
{
    /**
     * Merges the persisted chunks of the given state into a final file.
     *
     * @return string Absolute path to the successfully assembled final file
     *
     * @throws \Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException
     *         when any chunk is missing, unreadable, or the merge fails
     */
    public function assemble(UploadState $state, StorageInterface $storage): string;
}