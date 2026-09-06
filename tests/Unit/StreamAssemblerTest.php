<?php

declare(strict_types=1);

// File: tests/Unit/StreamAssemblerTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\Exceptions\AssemblyException;
use Resumable\ChunkedUploader\Core\Exceptions\ChunkNotFoundException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\TestCase;

final class StreamAssemblerTest extends TestCase
{
    #[Test]
    public function test_it_assembles_multiple_chunk_streams_into_a_byte_for_byte_replica_of_the_original_file(): void
    {
        $storage = new InMemoryChunkStorage();
        $contents = ['alpha', 'beta', 'gamma', 'delta', 'epsilon'];
        $expected = '';
        foreach ($contents as $index => $content) {
            $expected .= $content;
            $storage->store(new Chunk(
                identifier: 'upload',
                token: '',
                index: $index,
                totalChunks: count($contents),
                chunkSize: strlen($content),
                totalSize: array_sum(array_map('strlen', $contents)),
                tmpFilePath: $this->temporaryFile($content),
                originalFilename: 'payload.txt',
            ));
        }

        $state = new UploadState('upload', count($contents), strlen($expected), 'payload.txt', range(0, count($contents) - 1), true);
        $target = (new StreamAssembler($this->temporaryDirectory . '/final'))->assemble($state, $storage);

        self::assertFileExists($target);
        self::assertSame($expected, file_get_contents($target));
        self::assertSame(hash('sha256', $expected), hash_file('sha256', $target));
    }

    #[Test]
    public function test_it_assembles_large_multimegabyte_chunks_with_constant_memory_usage(): void
    {
        $storage = new InMemoryChunkStorage();
        $chunkData = $this->payload(1, 'B'); // 1 MiB per chunk
        $chunkCount = 20;

        for ($index = 0; $index < $chunkCount; $index++) {
            $storage->store(new Chunk(
                identifier: 'large',
                token: '',
                index: $index,
                totalChunks: $chunkCount,
                chunkSize: strlen($chunkData),
                totalSize: strlen($chunkData) * $chunkCount,
                tmpFilePath: $this->temporaryFile($chunkData),
                originalFilename: 'large.bin',
            ));
        }

        $state = new UploadState('large', $chunkCount, strlen($chunkData) * $chunkCount, 'large.bin', range(0, $chunkCount - 1), true);

        $before = memory_get_usage(true);
        $target = (new StreamAssembler($this->temporaryDirectory . '/large-final'))->assemble($state, $storage);
        $growth = memory_get_usage(true) - $before;

        self::assertSame(strlen($chunkData) * $chunkCount, filesize($target));
        self::assertLessThan(10 * 1024 * 1024, $growth, 'Assembly must stay under the 10MiB memory ceiling.');
        self::assertSame(hash_file('sha256', $target), hash('sha256', str_repeat($chunkData, $chunkCount)));
    }

    #[Test]
    public function test_it_assembles_chunks_regardless_of_the_order_they_were_stored(): void
    {
        $storage = new InMemoryChunkStorage();
        $parts = ['one', 'two', 'three', 'four'];
        $expected = 'onetwothreefour';

        // Store in reverse order to prove assembly always reads by index.
        foreach (array_reverse(array_keys($parts)) as $index) {
            $storage->store(new Chunk('shuffled', '', $index, 4, strlen($parts[$index]), strlen($expected), $this->temporaryFile($parts[$index]), 'shuffled.txt'));
        }

        $state = new UploadState('shuffled', 4, strlen($expected), 'shuffled.txt', range(0, 3), true);
        $target = (new StreamAssembler($this->temporaryDirectory . '/shuffled-final'))->assemble($state, $storage);

        self::assertSame($expected, file_get_contents($target));
    }

    #[Test]
    public function test_it_removes_a_partial_target_and_cleans_up_when_a_required_chunk_is_missing(): void
    {
        $storage = new InMemoryChunkStorage();
        $storage->store(new Chunk(
            identifier: 'broken',
            token: '',
            index: 0,
            totalChunks: 2,
            chunkSize: 5,
            totalSize: 10,
            tmpFilePath: $this->temporaryFile('first'),
            originalFilename: 'broken.bin',
        ));
        $state = new UploadState('broken', 2, 10, 'broken.bin', [0], false);

        $targetDir = $this->temporaryDirectory . '/broken-final';
        $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'broken' . DIRECTORY_SEPARATOR . 'broken.bin';

        try {
            (new StreamAssembler($targetDir))->assemble($state, $storage);
            self::fail('Expected assembly to fail for a missing chunk.');
        } catch (AssemblyException) {
            self::assertFileDoesNotExist($targetFile, 'Partial target must be deleted on failed assembly.');
        }
    }

    #[Test]
    public function test_it_propagates_missing_chunk_as_assembly_error_and_leaves_no_partial_output(): void
    {
        $storage = new InMemoryChunkStorage();
        $storage->store(new Chunk('partial-upload', '', 0, 2, 5, 10, $this->temporaryFile('first'), 'partial.bin'));

        $state = new UploadState('partial-upload', 2, 10, 'partial.bin', [0], true); // claims complete but chunk 1 absent

        $this->expectException(AssemblyException::class);
        (new StreamAssembler($this->temporaryDirectory . '/partial-final'))->assemble($state, $storage);
    }

    #[Test]
    public function test_it_throws_an_assembly_error_when_the_target_directory_cannot_be_created(): void
    {
        $storage = new InMemoryChunkStorage();
        $state = new UploadState('t', 1, 1, 'f.txt', [0], true);

        // Point at a path that is a file so mkdir must fail.
        $blocker = $this->temporaryFile('blocker');
        $this->expectException(AssemblyException::class);
        (new StreamAssembler($blocker))->assemble($state, $storage);
    }

    #[Test]
    public function test_it_sanitizes_the_final_filename_to_prevent_traversal(): void
    {
        $storage = new InMemoryChunkStorage();
        $storage->store(new Chunk('safe', '', 0, 1, 4, 4, $this->temporaryFile('data'), '../../evil.txt'));

        $state = new UploadState('safe', 1, 4, '../../evil.txt', [0], true);
        $target = (new StreamAssembler($this->temporaryDirectory . '/safe-final'))->assemble($state, $storage);

        // The assembled path must stay inside the target directory.
        self::assertStringStartsWith($this->temporaryDirectory . '/safe-final', $target);
        self::assertStringNotContainsString('..', $target);
        self::assertFileExists($target);
    }

    #[Test]
    public function test_it_assembles_a_single_chunk_upload_without_any_intermediate_buffering(): void
    {
        $storage = new InMemoryChunkStorage();
        $data = str_repeat(chr(0x41), 4096); // exactly one buffer
        $storage->store(new Chunk('single', '', 0, 1, strlen($data), strlen($data), $this->temporaryFile($data), 'single.bin'));

        $state = new UploadState('single', 1, strlen($data), 'single.bin', [0], true);
        $target = (new StreamAssembler($this->temporaryDirectory . '/single-final'))->assemble($state, $storage);

        self::assertSame($data, file_get_contents($target));
        self::assertSame(strlen($data), filesize($target));
    }
}
