<?php

declare(strict_types=1);

// File: src/Bridge/Symfony/Events/SymfonyEventDispatcher.php

namespace Resumable\ChunkedUploader\Bridge\Symfony\Events;

use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyDispatcherContract;

/**
 * Adapter forwarding core domain events into Symfony's event dispatcher.
 */
final readonly class SymfonyEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly SymfonyDispatcherContract $dispatcher,
    ) {
    }

    public function dispatch(object $event): object
    {
        return $this->dispatcher->dispatch($event);
    }
}
