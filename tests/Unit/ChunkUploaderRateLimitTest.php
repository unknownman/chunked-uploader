<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Assembler\StreamAssembler;
use Resumable\ChunkedUploader\Core\ChunkUploader;
use Resumable\ChunkedUploader\Core\Contracts\RateLimiterInterface;
use Resumable\ChunkedUploader\Core\Contracts\VirusScannerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\RateLimitExceededException;
use Resumable\ChunkedUploader\Core\Exceptions\VirusDetectedException;
use Resumable\ChunkedUploader\Core\Security\PathSanitizer;
use Resumable\ChunkedUploader\Core\Security\RateLimiting\RedisRateLimiter;
use Resumable\ChunkedUploader\Core\Security\UploadTokenService;
use Resumable\ChunkedUploader\Core\Validation\ChunkSecurityValidator;
use Resumable\ChunkedUploader\Core\Validation\ValidationPipeline;
use Resumable\ChunkedUploader\Tests\InMemoryChunkStorage;
use Resumable\ChunkedUploader\Tests\InMemoryMetadataRepository;
use Resumable\ChunkedUploader\Tests\NullEventDispatcher;
use Resumable\ChunkedUploader\Tests\Support\FakeRedis;
use Resumable\ChunkedUploader\Tests\TestCase;

final class ChunkUploaderRateLimitTest extends TestCase
{
    #[Test]
    public function test_rate_limiter_rejects_a_chunk_flood_after_the_ceiling(): void
    {
        $manager = $this->createManager(new InMemoryChunkStorage(), new InMemoryMetadataRepository(), rateLimiter: new RedisRateLimiter(new FakeRedis()), maxChunkAttempts: 3);
        $chunk = $this->chunk($this->temporaryFile('A'), $this->issueToken('flood', 1, 1), 0, 1, 1, 'flood');
        $manager->processChunk($chunk);
        $manager->processChunk($chunk);
        $this->expectException(RateLimitExceededException::class);
        $manager->processChunk($chunk);
    }

    #[Test]
    public function test_rate_limiting_does_not_block_a_legitimate_upload(): void
    {
        $manager = $this->createManager(new InMemoryChunkStorage(), new InMemoryMetadataRepository(), rateLimiter: new RedisRateLimiter(new FakeRedis()), maxChunkAttempts: 100);
        $state = null;
        for ($index = 0; $index < 3; $index++) {
            $state = $manager->processChunk($this->chunk($this->temporaryFile((string) $index), $this->issueToken('legit', 3, 3), $index, 3, 3, 'legit'));
        }
        self::assertNotNull($state);
        self::assertTrue($state->isCompleted);
    }

    #[Test]
    public function test_rate_limit_is_enforced_before_validation_and_persistence(): void
    {
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->method('tooManyAttempts')->willReturn(true);
        $validator = $this->createMock(\Resumable\ChunkedUploader\Core\Contracts\ChunkValidatorInterface::class);
        $validator->expects(self::never())->method('validate');
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $manager = new ChunkUploader(storage: $storage, metadata: $metadata, progress: $metadata, assembler: new StreamAssembler($this->tempDir() . '/final'), validator: $validator, dispatcher: new NullEventDispatcher(), rateLimiter: $rateLimiter, maxChunkAttempts: 5);

        $this->expectException(RateLimitExceededException::class);
        $manager->processChunk($this->chunk($this->temporaryFile('X'), 'unused', 0, 1, 1));
    }

    #[Test]
    public function test_virus_scan_rejection_prevents_persistence(): void
    {
        $scanner = $this->createMock(VirusScannerInterface::class);
        $scanner->method('scan')->willThrowException(new VirusDetectedException('Virus detected: EICAR-Test'));
        $validator = new ChunkSecurityValidator(sanitizer: new PathSanitizer(), pipeline: new ValidationPipeline([]), tokenService: new UploadTokenService('test-secret'), scanner: $scanner);
        $storage = new InMemoryChunkStorage();
        $metadata = new InMemoryMetadataRepository();
        $manager = new ChunkUploader(storage: $storage, metadata: $metadata, progress: $metadata, assembler: new StreamAssembler($this->tempDir() . '/final'), validator: $validator, dispatcher: new NullEventDispatcher());

        $this->expectException(VirusDetectedException::class);
        $manager->processChunk($this->chunk($this->temporaryFile('X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR'), $this->issueToken('virus', 1, 1), 0, 1, 1, 'virus'));
    }
}
