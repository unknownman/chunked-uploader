<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Feature;

use Resumable\ChunkedUploader\Core\Drivers\Security\UploadTokenManager;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\TestCase;

final class ResumableUploadTest extends TestCase
{
    public function test_it_handles_out_of_order_chunks_and_completes_when_all_are_uploaded(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $manager = $this->createManager($storage, $metadata);
        $token = (new UploadTokenManager('test-secret'))->generateToken('upload_ordered');

        $state = $manager->processChunk(new Chunk('upload_ordered', $token, 2, 3, 1, 3, $this->temporaryFile('C'), 'payload.txt'));
        self::assertFalse($state->isCompleted);
        self::assertSame([0, 1], $metadata->getMissingChunkIndices($state));

        $state = $manager->processChunk(new Chunk('upload_ordered', $token, 0, 3, 1, 3, $this->temporaryFile('A'), 'payload.txt'));
        self::assertFalse($state->isCompleted);
        self::assertSame([1], $metadata->getMissingChunkIndices($state));

        $state = $manager->processChunk(new Chunk('upload_ordered', $token, 1, 3, 1, 3, $this->temporaryFile('B'), 'payload.txt'));
        self::assertTrue($state->isCompleted);
        self::assertSame('ABC', file_get_contents($state->finalPath));
    }
}
