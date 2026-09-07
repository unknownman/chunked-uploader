<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Assembler;

use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Exceptions\AssemblyException;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;

final class StreamAssembler implements FileAssemblerInterface
{
    private const BUFFER = 4194304; // 4MB

    public function __construct(private readonly string $finalBaseDir)
    {
        $this->ensureDirectory($this->finalBaseDir);
    }

    public function assemble(UploadState $state, ChunkStorageInterface $storage): string
    {
        $safeName = $this->sanitizeFilename($state->originalFilename);
        $safeId = (new PathSanitizer())->sanitizeIdentifier($state->identifier);
        $targetDir = rtrim($this->finalBaseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeId;
        $this->ensureDirectory($targetDir);

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $safeName;

        $out = $this->openOutputStream($targetPath);

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
                    $in = $this->unwrapStream($in, $i);
                    stream_set_chunk_size($in, self::BUFFER);
                    stream_set_chunk_size($out, self::BUFFER);
                    $copied = stream_copy_to_stream($in, $out);
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

            return $targetPath;
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }

            // attempt to remove partial file
            if (is_file($targetPath) && !unlink($targetPath)) {
                throw new AssemblyException('Unable to remove partial final file: ' . $targetPath, 0, $e);
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

    /**
     * Creates a directory recursively or verifies it already exists.
     *
     * @throws AssemblyException when the directory cannot be created
     */
    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!is_dir($dir)) {
            set_error_handler(static function (int $severity, string $message): never {
                throw new AssemblyException($message);
            });

            try {
                $created = mkdir($dir, 0775, true);
            } finally {
                restore_error_handler();
            }

            if (!$created && !is_dir($dir)) {
                throw new AssemblyException('Unable to create directory: ' . $dir);
            }
        }
    }

    /**
     * Opens the final destination and converts filesystem warnings into a
     * typed assembly failure without allowing a partially opened handle out.
     *
     * @return resource
     */
    private function openOutputStream(string $path): mixed
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new AssemblyException($message);
        });

        try {
            $stream = fopen($path, 'wb');
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            throw new AssemblyException('Unable to open final file for writing');
        }

        return $stream;
    }

    /**
     * Converts a storage stream into a native PHP stream resource.
     *
     * Local chunk drivers already return a PHP resource; S3 returns a PSR-7
     * StreamInterface, which is transparently wrapped into a native stream via
     * Guzzle's StreamWrapper (present with the AWS SDK) so the assembler can use
     * stream_copy_to_stream() uniformly.
     *
     * @param mixed $stream Stream resource or PSR-7 StreamInterface from storage
     * @param int   $index  Chunk index, for error messages
     * @return resource Native PHP stream resource
     */
    private function unwrapStream(mixed $stream, int $index): mixed
    {
        if (is_resource($stream)) {
            @rewind($stream);
            return $stream;
        }

        if ($stream instanceof \Psr\Http\Message\StreamInterface) {
            if (!$stream->isReadable()) {
                throw new AssemblyException('Chunk stream is not readable for index ' . $index);
            }
            if ($stream->isSeekable() && $stream->tell() > 0) {
                $stream->rewind();
            }

            if (class_exists(\GuzzleHttp\Psr7\StreamWrapper::class)) {
                $resource = \GuzzleHttp\Psr7\StreamWrapper::getResource($stream);
                if (is_resource($resource)) {
                    return $resource;
                }
            }

            throw new AssemblyException('Unable to wrap PSR-7 chunk stream for index ' . $index);
        }

        throw new AssemblyException('Chunk stream is not a valid resource for index ' . $index);
    }
}
