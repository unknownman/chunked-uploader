<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Resumable\ChunkedUploader\Core\Exceptions\VirusDetectedException;
use Resumable\ChunkedUploader\Core\Security\Scanners\ClamAvScanner;
use Resumable\ChunkedUploader\Tests\TestCase;

use function strlen;

final class ClamAvScannerTest extends TestCase
{
    #[Test]
    public function test_scan_accepts_a_clean_file(): void
    {
        $sockets = $this->socketPair();
        fwrite($sockets[0], "stream: OK\n");

        $scanner = new ClamAvScanner('tcp://127.0.0.1:3310', 0.2, static fn () => $sockets[1]);
        $scanner->scan($this->temporaryFile('clean bytes'));
        fclose($sockets[0]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function test_scan_streams_the_instream_protocol_and_reads_the_response(): void
    {
        $sockets = $this->socketPair();
        fwrite($sockets[0], "stream: OK\n");

        $scanner = new ClamAvScanner('tcp://127.0.0.1:3310', 0.2, static fn () => $sockets[1]);
        $content = 'malware-free payload';
        $scanner->scan($this->temporaryFile($content));

        // The scanner closes its end in the finally block, signalling EOF so the
        // peer read below returns the full INSTREAM request.
        $request = stream_get_contents($sockets[0]);
        fclose($sockets[0]);

        self::assertStringStartsWith("zINSTREAM\0", (string) $request);
        self::assertStringContainsString(pack('N', strlen($content)) . $content, (string) $request);
        self::assertStringEndsWith(pack('N', 0), (string) $request);
    }

    #[Test]
    public function test_scan_throws_when_clamav_reports_an_infection(): void
    {
        $sockets = $this->socketPair();
        fwrite($sockets[0], "stream: Eicar-Test-Signature FOUND\n");

        $scanner = new ClamAvScanner('tcp://127.0.0.1:3310', 0.2, static fn () => $sockets[1]);

        try {
            $scanner->scan($this->temporaryFile('X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR'));
        } catch (VirusDetectedException $e) {
            fclose($sockets[0]);
            self::assertStringContainsString('Virus detected: Eicar-Test-Signature', $e->getMessage());
            return;
        }

        fclose($sockets[0]);
        self::fail('Expected a VirusDetectedException.');
    }

    #[Test]
    public function test_scan_rejects_an_unexpected_response(): void
    {
        $sockets = $this->socketPair();
        fwrite($sockets[0], "stream: ??\n");

        $scanner = new ClamAvScanner('tcp://127.0.0.1:3310', 0.2, static fn () => $sockets[1]);

        try {
            $scanner->scan($this->temporaryFile('weird'));
        } catch (\RuntimeException $e) {
            fclose($sockets[0]);
            self::assertStringContainsString('Unexpected ClamAV response', $e->getMessage());
            return;
        }

        fclose($sockets[0]);
        self::fail('Expected a RuntimeException.');
    }

    #[Test]
    public function test_scan_throws_when_the_input_file_is_missing(): void
    {
        $scanner = new ClamAvScanner('tcp://127.0.0.1:3310', 0.2, static fn () => null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to open file');
        $scanner->scan($this->tempDir() . '/definitely-missing.bin');
    }

    #[Test]
    public function test_scan_throws_when_the_socket_factory_returns_no_stream(): void
    {
        $scanner = new ClamAvScanner('tcp://127.0.0.1:3310', 0.2, static fn () => null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not return a stream');
        $scanner->scan($this->temporaryFile('data'));
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);
        self::assertIsResource($pair[0]);
        self::assertIsResource($pair[1]);

        return $pair;
    }
}
