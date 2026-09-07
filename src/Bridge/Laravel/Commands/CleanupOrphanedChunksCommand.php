<?php

declare(strict_types=1);

// File: src/Bridge/Laravel/Commands/CleanupOrphanedChunksCommand.php

namespace Resumable\ChunkedUploader\Bridge\Laravel\Commands;

use Illuminate\Console\Command;
use Resumable\ChunkedUploader\Core\GarbageCollector;

/**
 * Artisan command invoking the core garbage collector to purge orphaned chunks
 * and expired upload metadata.
 */
final class CleanupOrphanedChunksCommand extends Command
{
    /** @var string */
    protected $signature = 'chunk-uploader:cleanup {--ttl= : Staleness threshold in seconds}';

    /** @var string */
    protected $description = 'Clean up orphaned chunks and expired upload metadata.';

    public function __construct(private readonly GarbageCollector $collector)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $ttl = $this->option('ttl') !== null
            ? (int) $this->option('ttl')
            : (int) config('chunk-uploader.garbage_collection_ttl', 3600);

        if ($ttl < 1) {
            $this->error('The TTL must be a positive number of seconds.');

            return self::FAILURE;
        }

        $result = $this->collector->collect($ttl);

        $this->info(sprintf(
            'Removed %d orphaned chunks and %d expired metadata records.',
            $result['chunks'],
            $result['metadata'],
        ));

        return self::SUCCESS;
    }
}
