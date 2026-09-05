<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

interface VirusScannerInterface
{
    /**
     * Scans a file and throws when malware is detected.
     *
     * @param string $filePath Absolute path to the file to scan.
     * @return void
     */
    public function scan(string $filePath): void;
}
