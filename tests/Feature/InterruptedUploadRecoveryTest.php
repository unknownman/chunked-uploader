<?php

declare(strict_types=1);

// File: tests/Feature/InterruptedUploadRecoveryTest.php

namespace Resumable\ChunkedUploader\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\TestCase;

final class InterruptedUploadRecoveryTest extends TestCase
{
    #[Test]
    public function test_it_safely_resumes_upload_after_network_interruption(): void
    {
        $sandbox = $this->makeSandbox('upload_retry');
        $manager = $sandbox['manager'];
        $storage = $sandbox['storage'];
        $metadata = $sandbox['metadata'];
        $tokenFactory = $sandbox['tokenFactory'];

        $token = $tokenFactory(3, 3);

        // Chunk 0 sent, then the connection drops before the acknowledgment
        // reaches the client. The client retries chunk 0.
        $first = new Chunk(
            identifier: 'upload_retry',
            token: $token,
            index: 0,
            totalChunks: 3,
            chunkSize: 1,
            totalSize: 3,
            tmpFilePath: $this->temporaryFile('A'),
            originalFilename: 'payload.txt',
        );

        $initial = $manager->processChunk($first);
        $retry = $manager->processChunk($first);

        // Idempotency: the retried chunk must not duplicate the stored byte.
        self::assertSame([0], $initial->uploadedChunks);
        self::assertSame([0], $retry->uploadedChunks);
        self::assertFalse($retry->isCompleted);
        self::assertSame([1, 2], $metadata->getMissingChunkIndices($retry));

        // Complete the remaining upload.
        $manager->processChunk(new Chunk('upload_retry', $token, 1, 3, 1, 3, $this->temporaryFile('B'), 'payload.txt'));
        $complete = $manager->processChunk(new Chunk('upload_retry', $token, 2, 3, 1, 3, $this->temporaryFile('C'), 'payload.txt'));

        self::assertTrue($complete->isCompleted);
        self::assertSame('ABC', file_get_contents($complete->finalPath ?? ''));
        self::assertNotNull($complete->finalPath);
        // The retried chunk must never corrupt the final assembly.
        self::assertSame(hash('sha256', 'ABC'), hash_file('sha256', $complete->finalPath));
        self::assertFalse($storage->hasChunks('upload_retry'));
    }

    #[Test]
    public function test_it_resumes_from_the_last_missing_index_after_a_partial_drop(): void
    {
        $sandbox = $this->makeSandbox('upload_drop');
        $manager = $sandbox['manager'];
        $metadata = $sandbox['metadata'];
        $tokenFactory = $sandbox['tokenFactory'];

        $token = $tokenFactory(4, 4);

        // Chunks 0, 1, 3 (of 4) arrive, then the connection drops before chunk 2.
        foreach ([0, 1, 3] as $index) {
            $manager->processChunk(new Chunk(
                identifier: 'upload_drop',
                token: $token,
                index: $index,
                totalChunks: 4,
                chunkSize: 1,
                totalSize: 4,
                tmpFilePath: $this->temporaryFile(strtoupper(chr(65 + $index))),
                originalFilename: 'payload.txt',
            ));
        }

        // A status query mid-upload reveals exactly which index is missing.
        $state = $metadata->get('upload_drop');
        self::assertNotNull($state);
        self::assertFalse($state->isCompleted);
        self::assertSame([2], $metadata->getMissingChunkIndices($state));

        // Resume from the last missing index (2).
        $manager->processChunk(new Chunk(
            identifier: 'upload_drop',
            token: $token,
            index: 2,
            totalChunks: 4,
            chunkSize: 1,
            totalSize: 4,
            tmpFilePath: $this->temporaryFile('C'),
            originalFilename: 'payload.txt',
        ));

        $final = $metadata->get('upload_drop');
        self::assertNotNull($final);
        self::assertTrue($final->isCompleted);
        self::assertSame([], $metadata->getMissingChunkIndices($final));
        self::assertSame('ABCD', file_get_contents($final->finalPath ?? ''));
    }

    #[Test]
    public function test_it_recovers_from_a_service_restart_mid_upload_by_reading_persisted_state(): void
    {
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $secret = 'test-secret';

        // First request cycle: chunk 0 arrives.
        $manager = $this->createManager($storage, $metadata, $secret);
        $token = $this->issueToken('upload_restart', 3, 3, '', $secret);
        $manager->processChunk(new Chunk('upload_restart', $token, 0, 3, 1, 3, $this->temporaryFile('A'), 'payload.txt'));

        // Simulate a service restart: a brand-new manager is wired over the
        // SAME storage and metadata doubles, mirroring a process that rebuilt
        // its repository connection from persisted state.
        $freshManager = $this->createManager($storage, $metadata, $secret);

        // New request cycle: chunk 1 arrives after the restart.
        $state = $freshManager->processChunk(new Chunk('upload_restart', $token, 1, 3, 1, 3, $this->temporaryFile('B'), 'payload.txt'));

        self::assertFalse($state->isCompleted);
        self::assertSame([2], $metadata->getMissingChunkIndices($state));
        self::assertSame([0, 1], $state->uploadedChunks);

        // Final chunk completes the upload across the restart boundary.
        $final = $freshManager->processChunk(new Chunk('upload_restart', $token, 2, 3, 1, 3, $this->temporaryFile('C'), 'payload.txt'));
        self::assertTrue($final->isCompleted);
        self::assertSame('ABC', file_get_contents($final->finalPath ?? ''));
    }

    #[Test]
    public function test_re_sending_multiple_already_uploaded_chunks_does_not_duplicate_bytes(): void
    {
        $sandbox = $this->makeSandbox('upload_dupe');
        $manager = $sandbox['manager'];
        $metadata = $sandbox['metadata'];
        $tokenFactory = $sandbox['tokenFactory'];

        $token = $tokenFactory(3, 3);
        $chunks = [
            0 => 'A',
            1 => 'B',
            2 => 'C',
        ];

        // Upload all chunks.
        foreach ($chunks as $index => $data) {
            $manager->processChunk(new Chunk('upload_dupe', $token, $index, 3, 1, 3, $this->temporaryFile($data), 'payload.txt'));
        }

        // Simulate a network retry storm: re-send every chunk a second time.
        foreach ($chunks as $index => $data) {
            $retried = $manager->processChunk(new Chunk('upload_dupe', $token, $index, 3, 1, 3, $this->temporaryFile($data), 'payload.txt'));
            self::assertTrue($retried->isCompleted);
            self::assertSame([0, 1, 2], $retried->uploadedChunks);
        }

        // The final file must byte-for-byte match after duplicates.
        $final = $metadata->get('upload_dupe');
        self::assertNotNull($final);
        self::assertSame('ABC', file_get_contents($final->finalPath ?? ''));
        self::assertSame(hash('sha256', 'ABC'), hash_file('sha256', $final->finalPath));
    }
}
