<?php

declare(strict_types=1);

// File: tests/Unit/UploadStateTest.php

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Models\UploadState;
use Resumable\ChunkedUploader\Tests\TestCase;

final class UploadStateTest extends TestCase
{
    #[Test]
    public function test_a_fresh_state_has_no_multipart_upload(): void
    {
        $state = new UploadState('a', 2, 8, 'x.bin');

        self::assertFalse($state->hasMultipartUpload());
        self::assertNull($state->multipartUploadId);
        self::assertSame([], $state->partEtags);
    }

    #[Test]
    public function test_recording_a_chunk_preserves_the_multipart_fields(): void
    {
        // Progress and multipart bookkeeping are updated on different code paths;
        // one must never clobber the other.
        $state = (new UploadState('a', 2, 8, 'x.bin'))
            ->withMultipartUploadId('upload-1')
            ->withPartEtag(1, '"e1"')
            ->withUploadedChunk(0);

        self::assertSame('upload-1', $state->multipartUploadId);
        self::assertSame([1 => '"e1"'], $state->partEtags);
        self::assertSame([0], $state->uploadedChunks);
    }

    #[Test]
    public function test_setting_the_final_path_preserves_the_multipart_fields(): void
    {
        $state = (new UploadState('a', 1, 4, 'x.bin'))
            ->withMultipartUploadId('upload-1')
            ->withPartEtag(1, '"e1"')
            ->withFinalPath('/tmp/out.bin');

        self::assertSame('upload-1', $state->multipartUploadId);
        self::assertSame([1 => '"e1"'], $state->partEtags);
        self::assertTrue($state->isCompleted);
    }

    #[Test]
    public function test_restarting_the_multipart_upload_replaces_the_stale_handle(): void
    {
        // A reaped or aborted upload is started over; the old UploadId is dead
        // and must not survive into the next round of part uploads.
        $state = (new UploadState('a', 1, 4, 'x.bin'))
            ->withMultipartUploadId('stale')
            ->withMultipartUploadId('fresh');

        self::assertSame('fresh', $state->multipartUploadId);
    }

    #[Test]
    public function test_recording_the_same_part_twice_keeps_the_latest_etag(): void
    {
        $state = (new UploadState('a', 1, 4, 'x.bin'))
            ->withPartEtag(1, '"first"')
            ->withPartEtag(1, '"second"');

        self::assertSame([1 => '"second"'], $state->partEtags);
    }

    #[Test]
    public function test_part_numbers_must_be_one_based(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UploadState('a', 1, 4, 'x.bin'))->withPartEtag(0, '"e"');
    }

    #[Test]
    public function test_sorted_parts_returns_the_manifest_s3_expects(): void
    {
        $state = (new UploadState('a', 3, 30, 'x.bin'))
            ->withPartEtag(3, '"e3"')
            ->withPartEtag(1, '"e1"')
            ->withPartEtag(2, '"e2"');

        // CompleteMultipartUpload requires strictly ascending PartNumber order.
        self::assertSame([
            ['PartNumber' => 1, 'ETag' => '"e1"'],
            ['PartNumber' => 2, 'ETag' => '"e2"'],
            ['PartNumber' => 3, 'ETag' => '"e3"'],
        ], $state->sortedParts());
    }

    #[Test]
    public function test_sorted_parts_is_empty_when_nothing_was_recorded(): void
    {
        self::assertSame([], (new UploadState('a', 2, 8, 'x.bin'))->sortedParts());
    }
}
