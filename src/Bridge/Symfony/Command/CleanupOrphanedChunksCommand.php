<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/Command/CleanupOrphanedChunksCommand.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\Command;

use Resumable\ChunkedUploader\Core\GarbageCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Symfony console command wrapping the core garbage collector.
 */
#[AsCommand(name: 'chunk-uploader:cleanup', description: 'Clean up orphaned chunk artifacts and expired upload metadata.')]
final class CleanupOrphanedChunksCommand extends Command
{
    public function __construct(
        private readonly GarbageCollector $collector,
        private readonly int $defaultTtl = 3600,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'ttl',
            null,
            InputOption::VALUE_REQUIRED,
            'Staleness threshold in seconds',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ttl = $input->getOption('ttl');

        if (null !== $ttl && ((int) $ttl) < 1) {
            $io->error('The TTL must be a positive number of seconds.');

            return Command::FAILURE;
        }

        $result = $this->collector->collect($ttl !== null ? (int) $ttl : $this->defaultTtl);

        $io->success(sprintf('Removed %d orphaned chunks and %d expired metadata records.', $result['chunks'], $result['metadata']));

        return Command::SUCCESS;
    }
}
