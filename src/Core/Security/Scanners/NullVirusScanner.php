<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Security\Scanners;

use Resumable\ChunkedUploader\Core\Contracts\VirusScannerInterface;

final class NullVirusScanner implements VirusScannerInterface
{
    /**
     * Deliberately performs no scan.
     *
     * @param string $filePath File path accepted for interface compatibility.
     * @return void
     */
    public function scan(string $filePath): void
    {
    }
}
