<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Exceptions;

use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Raised when the assembly lock is found to be lost while the critical section
 * is still running, meaning mutual exclusion was not in force.
 *
 * This is deliberately distinct from {@see AssemblyException}. An
 * AssemblyException means "assembly failed and the upload still needs
 * retrying"; this one means "another node may be assembling this upload right
 * now". Retrying blindly is wrong for the latter, because the chunk data is
 * intact and the competitor will finalize it.
 *
 * The guarantees this exception is thrown to preserve:
 *
 *  - `finalPath` is **not** written, so a status poll never reports a file that
 *    two nodes both believe they produced.
 *  - The chunks are **not** deleted, because the new lock owner owns them now.
 *    Deleting them would destroy the very data the winner needs to finish.
 *  - No `FileAssembledEvent` is dispatched, so no downstream consumer (virus
 *    scanner, thumbnailer, webhook) is handed a path a competitor is rewriting.
 *
 * The upload therefore stays resumable: the metadata still lists every chunk as
 * uploaded, so a later attempt re-enters assembly and completes normally.
 */
final class AssemblyLeaseLostException extends ChunkUploaderException
{
    public function __construct(string $message, private readonly ?UploadState $state = null)
    {
        parent::__construct($message);
    }

    /**
     * The upload state as it stood when the lease was lost, when available.
     */
    public function state(): ?UploadState
    {
        return $this->state;
    }
}
