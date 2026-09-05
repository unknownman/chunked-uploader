<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Feature;

use Resumable\ChunkedUploader\Core\Drivers\Security\UploadTokenManager;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\TestCase;

final class InterruptedUploadRecoveryTest extends TestCase
{
    public function test_it_safely_resumes_upload_after_network_interruption(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $manager = $this->createManager($storage, $metadata);
        $token = (new UploadTokenManager('test-secret'))->generateToken('upload_retry');
        $first = new Chunk('upload_retry', $token, 0, 3, 1, 3, $this->temporaryFile('A'), 'payload.txt');

        $initial = $manager->processChunk($first);
        $retry = $manager->processChunk($first);

        self::assertSame([0], $initial->uploadedChunks);
        self::assertSame([0], $retry->uploadedChunks);
        self::assertFalse($retry->isCompleted);
        self::assertSame([1, 2], $metadata->getMissingChunkIndices($retry));

        $manager->processChunk(new Chunk('upload_retry', $token, 1, 3, 1, 3, $this->temporaryFile('B'), 'payload.txt'));
        $complete = $manager->processChunk(new Chunk('upload_retry', $token, 2, 3, 1, 3, $this->temporaryFile('C'), 'payload.txt'));

        self::assertTrue($complete->isCompleted);
        self::assertSame('ABC', file_get_contents($complete->finalPath));
    }
}
