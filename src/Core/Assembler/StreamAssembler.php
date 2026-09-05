<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Assembler;

use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Exceptions\AssemblyException;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Core\Models\Chunk;

final class StreamAssembler implements FileAssemblerInterface
{
    private const BUFFER = 4194304; // 4MB

    public function __construct(private readonly string $finalBaseDir)
    {
        if (!is_dir($this->finalBaseDir) && !@mkdir($this->finalBaseDir, 0775, true) && !is_dir($this->finalBaseDir)) {
            throw new AssemblyException('Unable to create final storage directory');
        }
    }

    public function assemble(UploadState $state, ChunkStorageInterface $storage): string
    {
        $safeName = $this->sanitizeFilename($state->originalFilename);
        $targetDir = rtrim($this->finalBaseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $state->identifier;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new AssemblyException('Unable to create assembly target directory');
        }

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $safeName;

        $out = @fopen($targetPath, 'wb');
        if ($out === false) {
            throw new AssemblyException('Unable to open final file for writing');
        }

        $closedOut = false;
        try {
            for ($i = 0; $i < $state->totalChunks; $i++) {
                $chunkDto = new Chunk(
                    identifier: $state->identifier,
                    token: '',
                    index: $i,
                    totalChunks: $state->totalChunks,
                    chunkSize: 0,
                    totalSize: $state->totalSize,
                    tmpFilePath: '',
                    originalFilename: $state->originalFilename,
                );

                $in = null;
                try {
                    $in = $storage->getChunkStream($chunkDto);
                    if (!is_resource($in)) {
                        throw new AssemblyException('Chunk stream is not a valid resource for index ' . $i);
                    }

                    $copied = stream_copy_to_stream($in, $out, self::BUFFER);
                    if ($copied === false) {
                        throw new AssemblyException('Failed to copy chunk ' . $i . ' to final file');
                    }
                } catch (ChunkNotFoundException $e) {
                    throw $e;
                } finally {
                    if (is_resource($in)) {
                        fclose($in);
                    }
                }
            }

            fflush($out);
            fclose($out);
            $closedOut = true;

            return $targetPath;
        } catch (\Throwable $e) {
            if (!$closedOut && is_resource($out)) {
                fclose($out);
            }

            // attempt to remove partial file
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }

            throw new AssemblyException('Assembly failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        // Replace any remaining unsafe characters
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file.bin';
    }
}