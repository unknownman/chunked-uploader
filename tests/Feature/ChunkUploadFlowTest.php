<?php

declare(strict_types=1);

// File: tests/Feature/ChunkUploadFlowTest.php

namespace Resumable\ChunkedUploader\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Contracts\ChunkStorageInterface;
use Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Resumable\ChunkedUploader\Core\Contracts\FileAssemblerInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\SecurityViolationException;
use Resumable\ChunkedUploader\Core\Exceptions\UploadFailedException;
use Resumable\ChunkedUploader\Core\Models\Chunk;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Core\UploadManager;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\NullEventDispatcher;
use Resumable\ChunkedUploader\Tests\TestCase;

final class ChunkUploadFlowTest extends TestCase
{
    #[Test]
    public function test_it_uploads_all_chunks_sequentially_assembles_the_file_and_deletes_temporary_chunks(): void
    {
        $sandbox = $this->makeSandbox('upload_seq');
        $storage = $sandbox['storage'];
        $metadata = $sandbox['metadata'];
        $manager = $sandbox['manager'];
        $tokenFactory = $sandbox['tokenFactory'];

        $parts = ['hello ', 'world'];
        $expected = 'hello world';
        $token = $tokenFactory(count($parts), strlen($expected));
        $lastState = null;

        foreach ($parts as $index => $part) {
            $path = $this->temporaryFile($part);
            $lastState = $manager->processChunk(new Chunk(
                identifier: 'upload_seq',
                token: $token,
                index: $index,
                totalChunks: count($parts),
                chunkSize: strlen($part),
                totalSize: strlen($expected),
                tmpFilePath: $path,
                originalFilename: 'payload.txt',
            ));
        }

        self::assertNotNull($lastState);
        self::assertTrue($lastState->isCompleted);
        self::assertNotNull($lastState->finalPath);
        self::assertSame($expected, file_get_contents($lastState->finalPath));
        self::assertSame(hash('sha256', $expected), hash_file('sha256', $lastState->finalPath));
        self::assertFalse($storage->hasChunks('upload_seq'), 'Temporary chunks must be cleaned up after assembly.');
        self::assertTrue($metadata->get('upload_seq')?->isCompleted);
    }

    #[Test]
    public function test_it_assembles_exactly_once_and_deletes_chunks_immediately_following_assembly(): void
    {
        $metadata = new InMemoryMetadataRepository();
        $storage = $this->createMock(ChunkStorageInterface::class);
        $assembler = $this->createMock(FileAssemblerInterface::class);
        $validator = $this->createValidator();

        $assembled = false;
        $storage->expects(self::once())->method('store');
        $storage->expects(self::once())->method('deleteChunks')
            ->willReturnCallback(function () use (&$assembled): void {
                self::assertTrue($assembled, 'deleteChunks must be called after assemble completes.');
            });
        $assembler->expects(self::once())->method('assemble')->willReturnCallback(
            function () use (&$assembled): string {
                $assembled = true;
                return '/tmp/final-upload.txt';
            },
        );

        $manager = new UploadManager(
            storage: $storage,
            metadata: $metadata,
            progress: $metadata,
            assembler: $assembler,
            validator: $validator,
            dispatcher: new NullEventDispatcher(),
        );
        $token = $this->issueToken('upload_spy', 1, 4);
        $state = $manager->processChunk(new Chunk('upload_spy', $token, 0, 1, 4, 4, $this->temporaryFile('data'), 'payload.txt'));

        self::assertTrue($state->isCompleted);
        self::assertTrue($assembled);
    }

    #[Test]
    public function test_it_rejects_a_tampered_token_before_persisting_any_chunk(): void
    {
        $storage = $this->createMock(ChunkStorageInterface::class);
        $metadata = new InMemoryMetadataRepository();
        $storage->expects(self::never())->method('store');
        $storage->expects(self::never())->method('deleteChunks');

        $manager = new UploadManager(
            storage: $storage,
            metadata: $metadata,
            progress: $metadata,
            assembler: $this->createMock(FileAssemblerInterface::class),
            validator: $this->createValidator('test-secret'),
            dispatcher: new NullEventDispatcher(),
        );

        $wrongToken = hash_hmac('sha256', 'upload_tamper', 'wrong-secret');
        $chunk = new Chunk(
            identifier: 'upload_tamper',
            token: $wrongToken,
            index: 0,
            totalChunks: 1,
            chunkSize: 4,
            totalSize: 4,
            tmpFilePath: $this->temporaryFile('data'),
            originalFilename: 'payload.txt',
        );

        $this->expectException(SecurityViolationException::class);
        $manager->processChunk($chunk);
    }

    #[Test]
    public function test_failed_assembly_throws_and_does_not_persist_corrupt_state(): void
    {
        $metadata = new InMemoryMetadataRepository();
        $storage = $this->createMock(ChunkStorageInterface::class);
        $assembler = $this->createMock(FileAssemblerInterface::class);

        $storage->method('store');
        $assembler->expects(self::once())->method('assemble')
            ->willThrowException(new \RuntimeException('disk full'));

        $manager = new UploadManager(
            storage: $storage,
            metadata: $metadata,
            progress: $metadata,
            assembler: $assembler,
            validator: $this->createValidator('test-secret'),
            dispatcher: new NullEventDispatcher(),
        );
        $token = $this->issueToken('upload_fail', 1, 4);

        $this->expectException(UploadFailedException::class);
        $manager->processChunk(new Chunk('upload_fail', $token, 0, 1, 4, 4, $this->temporaryFile('data'), 'payload.txt'));
    }

    #[Test]
    public function test_it_tracks_progress_percentage_through_a_full_upload(): void
    {
        $sandbox = $this->makeSandbox('upload_progress');
        $manager = $sandbox['manager'];
        $metadata = $sandbox['metadata'];
        $tokenFactory = $sandbox['tokenFactory'];

        $parts = ['a', 'b', 'c', 'd'];
        $states = [];
        $token = $tokenFactory(count($parts), count($parts));

        foreach ($parts as $index => $part) {
            $states[] = $manager->processChunk(new Chunk(
                identifier: 'upload_progress',
                token: $token,
                index: $index,
                totalChunks: count($parts),
                chunkSize: strlen($part),
                totalSize: count($parts),
                tmpFilePath: $this->temporaryFile($part),
                originalFilename: 'payload.txt',
            ));
        }

        self::assertSame(25.0, $metadata->getPercentage($states[0]));
        self::assertSame(50.0, $metadata->getPercentage($states[1]));
        self::assertSame(75.0, $metadata->getPercentage($states[2]));
        self::assertSame(100.0, $metadata->getPercentage($states[3]));
    }

    #[Test]
    public function test_it_cancels_an_in_progress_upload_and_cleans_up_all_resources(): void
    {
        $sandbox = $this->makeSandbox('upload_cancel');
        $manager = $sandbox['manager'];
        $storage = $sandbox['storage'];
        $metadata = $sandbox['metadata'];
        $tokenFactory = $sandbox['tokenFactory'];

        $token = $tokenFactory(3, 3);
        $manager->processChunk(new Chunk('upload_cancel', $token, 0, 3, 1, 3, $this->temporaryFile('a'), 'payload.txt'));
        self::assertTrue($storage->hasChunks('upload_cancel'));

        $manager->cancelUpload('upload_cancel');

        self::assertFalse($storage->hasChunks('upload_cancel'));
        self::assertNull($metadata->get('upload_cancel'));
    }

    #[Test]
    public function test_cancel_is_idempotent_for_an_unknown_upload(): void
    {
        $sandbox = $this->makeSandbox('upload_never_started');
        $manager = $sandbox['manager'];

        $manager->cancelUpload('upload_never_started');
        $manager->cancelUpload('upload_never_started');

        self::assertTrue(true);
    }

    #[Test]
    public function test_it_dispatches_chunk_uploaded_and_file_assembled_events_on_completion(): void
    {
        $sandbox = $this->makeSandbox('upload_events');
        $manager = $sandbox['manager'];
        $dispatcher = $sandbox['dispatcher'];
        $tokenFactory = $sandbox['tokenFactory'];

        $token = $tokenFactory(2, 2);
        $manager->processChunk(new Chunk('upload_events', $token, 0, 2, 1, 2, $this->temporaryFile('A'), 'payload.txt'));
        $manager->processChunk(new Chunk('upload_events', $token, 1, 2, 1, 2, $this->temporaryFile('B'), 'payload.txt'));

        $eventClasses = array_map('get_class', $dispatcher->events);
        self::assertContains(\Resumable\ChunkedUploader\Core\Events\ChunkUploadedEvent::class, $eventClasses);
        self::assertContains(\Resumable\ChunkedUploader\Core\Events\FileAssembledEvent::class, $eventClasses);
    }
}
