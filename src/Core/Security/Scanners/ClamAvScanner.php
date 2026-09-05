<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Security\Scanners;

use Closure;
use Resumable\ChunkedUploader\Core\Contracts\VirusScannerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\VirusDetectedException;

final class ClamAvScanner implements VirusScannerInterface
{
    /**
     * @param string $endpoint UNIX socket path or tcp://host:port endpoint.
     * @param Closure|null $socketFactory Test seam returning a socket stream resource.
     */
    public function __construct(private readonly string $endpoint = 'tcp://127.0.0.1:3310', private readonly ?Closure $socketFactory = null)
    {
    }

    /**
     * Streams a file to ClamAV using the INSTREAM protocol.
     *
     * @param string $filePath Absolute file path to scan.
     * @return void
     * @throws VirusDetectedException When ClamAV reports an infection.
     * @throws \RuntimeException When the daemon or file cannot be accessed.
     */
    public function scan(string $filePath): void
    {
        $input = @fopen($filePath, 'rb');
        if ($input === false) {
            throw new \RuntimeException('Unable to open file for virus scanning.');
        }

        $socket = null;
        try {
            $socket = $this->openSocket();
            if (!is_resource($socket)) {
                throw new \RuntimeException('ClamAV socket factory did not return a stream.');
            }

            stream_set_timeout($socket, 30);
            $this->writeAll($socket, "zINSTREAM\0");

            while (!feof($input)) {
                $data = fread($input, 1024 * 1024);
                if ($data === false) {
                    throw new \RuntimeException('Unable to read file during virus scan.');
                }
                if ($data === '') {
                    break;
                }

                $length = strlen($data);
                $this->writeAll($socket, pack('N', $length) . $data);
            }

            $this->writeAll($socket, pack('N', 0));

            $response = stream_get_contents($socket);
            if ($response === false) {
                throw new \RuntimeException('Unable to read ClamAV response.');
            }

            $response = trim($response);
            if (str_ends_with($response, 'FOUND')) {
                $virusName = trim((string) preg_replace('/:\s*FOUND$/', '', $response));
                throw new VirusDetectedException('Virus detected: ' . $virusName);
            }
            if (!str_ends_with($response, 'OK')) {
                throw new \RuntimeException('Unexpected ClamAV response: ' . $response);
            }
        } finally {
            fclose($input);
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * Opens the configured daemon socket.
     *
     * @return mixed Native stream resource.
     */
    private function openSocket(): mixed
    {
        if ($this->socketFactory !== null) {
            return ($this->socketFactory)($this->endpoint);
        }

        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client($this->endpoint, $errorCode, $errorMessage, 30);
        if ($socket === false) {
            throw new \RuntimeException('Unable to connect to ClamAV: ' . $errorMessage);
        }

        return $socket;
    }

    /**
     * Writes all bytes, handling short writes from stream sockets.
     *
     * @param mixed $socket Native stream resource.
     * @param string $data Bytes to write.
     * @return void
     */
    private function writeAll(mixed $socket, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write complete ClamAV request.');
            }
            $offset += $written;
        }
    }
}
