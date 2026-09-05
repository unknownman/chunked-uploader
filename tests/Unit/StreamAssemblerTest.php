<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\Exceptions\AssemblyException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\TestCase;

final class StreamAssemblerTest extends TestCase
{
    public function test_it_assembles_multiple_chunk_streams_byte_for_byte(): void
    {
        $storage = new InMemoryChunkStorage();
        $contents = ['alpha', 'beta', 'gamma'];
        foreach ($contents as $index => $content) {
            $storage->store(new Chunk('upload', '', $index, 3, strlen($content), 14, $this->temporaryFile($content), 'payload.txt'));
        }

        $state = new UploadState('upload', 3, 14, 'payload.txt', [0, 1, 2], true);
        $target = (new StreamAssembler($this->temporaryDirectory . '/final'))->assemble($state, $storage);

        self::assertSame('alphabetagamma', file_get_contents($target));
    }

    public function test_it_keeps_memory_growth_below_ten_megabytes_for_large_streams(): void
    {
        $storage = new InMemoryChunkStorage();
        $payload = str_repeat('A', 1024 * 1024);
        for ($index = 0; $index < 20; $index++) {
            $storage->store(new Chunk('large', '', $index, 20, strlen($payload), strlen($payload) * 20, $this->temporaryFile($payload), 'large.bin'));
        }

        $state = new UploadState('large', 20, strlen($payload) * 20, 'large.bin', range(0, 19), true);
        $before = memory_get_usage(true);
        $target = (new StreamAssembler($this->temporaryDirectory . '/large-final'))->assemble($state, $storage);
        $growth = memory_get_usage(true) - $before;

        self::assertSame(strlen($payload) * 20, filesize($target));
        self::assertLessThan(10 * 1024 * 1024, $growth);
    }

    public function test_it_removes_a_partial_target_when_a_required_chunk_is_missing(): void
    {
        $storage = new InMemoryChunkStorage();
        $storage->store(new Chunk('broken', '', 0, 2, 5, 10, $this->temporaryFile('first'), 'broken.bin'));
        $state = new UploadState('broken', 2, 10, 'broken.bin', [0], false);

        try {
            (new StreamAssembler($this->temporaryDirectory . '/broken-final'))->assemble($state, $storage);
            self::fail('Expected assembly to fail for a missing chunk.');
        } catch (AssemblyException) {
            self::assertFileDoesNotExist($this->temporaryDirectory . '/broken-final/broken/broken.bin');
        }
    }
}
