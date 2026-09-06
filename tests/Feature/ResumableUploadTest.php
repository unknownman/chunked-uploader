<?php

declare(strict_types=1);

// File: tests/Feature/ResumableUploadTest.php

namespace Resumable\ChunkedUploader\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Tests\TestCase;

final class ResumableUploadTest extends TestCase
{
    #[Test]
    public function test_it_handles_out_of_order_chunks_and_only_completes_when_all_are_present(): void
    {
        $sandbox = $this->makeSandbox('upload_ooo');
        $manager = $sandbox['manager'];
        $metadata = $sandbox['metadata'];
        $token = $sandbox['token'];

        // 1. Send chunk index 2 of 3 first.
        $state = $manager->processChunk(new Chunk(
            identifier: 'upload_ooo',
            token: $token,
            index: 2,
            totalChunks: 3,
            chunkSize: 1,
            totalSize: 3,
            tmpFilePath: $this->temporaryFile('C'),
            originalFilename: 'payload.txt',
        ));

        self::assertFalse($state->isCompleted);
        self::assertSame([0, 1], $metadata->getMissingChunkIndices($state));

        // 2. Send chunk index 0 next.
        $state = $manager->processChunk(new Chunk(
            identifier: 'upload_ooo',
            token: $token,
            index: 0,
            totalChunks: 3,
            chunkSize: 1,
            totalSize: 3,
            tmpFilePath: $this->temporaryFile('A'),
            originalFilename: 'payload.txt',
        ));

        self::assertFalse($state->isCompleted);
        self::assertSame([1], $metadata->getMissingChunkIndices($state));

        // 3. Send chunk index 1 last.
        $state = $manager->processChunk(new Chunk(
            identifier: 'upload_ooo',
            token: $token,
            index: 1,
            totalChunks: 3,
            chunkSize: 1,
            totalSize: 3,
            tmpFilePath: $this->temporaryFile('B'),
            originalFilename: 'payload.txt',
        ));

        self::assertTrue($state->isCompleted);
        self::assertSame([], $metadata->getMissingChunkIndices($state));
        self::assertSame('ABC', file_get_contents($state->finalPath ?? ''));
        self::assertNotNull($state->finalPath);
    }

    #[Test]
    public function test_it_finalizes_only_when_the_very_last_chunk_arrives_even_if_earlier_chunks_are_received_in_reverse(): void
    {
        $sandbox = $this->makeSandbox('upload_reverse');
        $manager = $sandbox['manager'];
        $token = $sandbox['token'];
        $parts = ['P', 'Q', 'R', 'S', 'T'];

        // Upload everything except index 0 first.
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $state = $manager->processChunk(new Chunk(
                identifier: 'upload_reverse',
                token: $token,
                index: $i,
                totalChunks: count($parts),
                chunkSize: 1,
                totalSize: count($parts),
                tmpFilePath: $this->temporaryFile($parts[$i]),
                originalFilename: 'payload.txt',
            ));
            self::assertFalse($state->isCompleted, 'Upload must not complete while chunks are missing.');
        }

        // Last index closes the reverse sequence.
        $final = $manager->processChunk(new Chunk(
            identifier: 'upload_reverse',
            token: $token,
            index: 0,
            totalChunks: count($parts),
            chunkSize: 1,
            totalSize: count($parts),
            tmpFilePath: $this->temporaryFile($parts[0]),
            originalFilename: 'payload.txt',
        ));

        self::assertTrue($final->isCompleted);
        self::assertSame('PQRST', file_get_contents($final->finalPath ?? ''));
    }

    #[Test]
    public function test_it_returns_missing_chunk_indices_in_sorted_ascending_order(): void
    {
        $sandbox = $this->makeSandbox('upload_missing');
        $manager = $sandbox['manager'];
        $metadata = $sandbox['metadata'];
        $token = $sandbox['token'];

        $state = $manager->processChunk(new Chunk(
            identifier: 'upload_missing',
            token: $token,
            index: 2,
            totalChunks: 5,
            chunkSize: 1,
            totalSize: 5,
            tmpFilePath: $this->temporaryFile('C'),
            originalFilename: 'payload.txt',
        ));

        self::assertSame([0, 1, 3, 4], $metadata->getMissingChunkIndices($state));

        $state = $manager->processChunk(new Chunk(
            identifier: 'upload_missing',
            token: $token,
            index: 3,
            totalChunks: 5,
            chunkSize: 1,
            totalSize: 5,
            tmpFilePath: $this->temporaryFile('D'),
            originalFilename: 'payload.txt',
        ));

        self::assertSame([0, 1, 4], $metadata->getMissingChunkIndices($state));
        self::assertSame(40.0, $metadata->getPercentage($state));
    }

    #[Test]
    public function test_it_tracks_a_high_index_chunk_without_prematurely_completing_the_upload(): void
    {
        $sandbox = $this->makeSandbox('upload_range');
        $manager = $sandbox['manager'];
        $metadata = $sandbox['metadata'];
        $token = $sandbox['token'];

        // A chunk whose index is beyond the declared total is accepted and
        // tracked (the progress model is index-driven), but the upload MUST
        // NOT complete until all heard-of chunks are present.
        $manager->processChunk(new Chunk(
            identifier: 'upload_range',
            token: $token,
            index: 5,
            totalChunks: 3,
            chunkSize: 1,
            totalSize: 3,
            tmpFilePath: $this->temporaryFile('X'),
            originalFilename: 'payload.txt',
        ));

        $state = $metadata->get('upload_range');
        self::assertNotNull($state);
        self::assertFalse($state->isCompleted);
    }
}
