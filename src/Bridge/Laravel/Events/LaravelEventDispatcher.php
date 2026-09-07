<?php

declare(strict_types=1);

// File: src/Bridge/Laravel/Events/LaravelEventDispatcher.php

namespace Resumable\ChunkedUploader\Bridge\Laravel\Events;

use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcherContract;
use Resumable\ChunkedUploader\Core\Contracts\EventDispatcherInterface;

/**
 * Adapter forwarding core domain events into Laravel's event system.
 *
 * Laravel's Dispatcher implements PSR-14, so core events dispatched through
 * this adapter are receivable by regular framework listeners via
 * `Event::listen()`, `#[Listen]` attributes, or observers.
 */
final readonly class LaravelEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly LaravelDispatcherContract $dispatcher,
    ) {
    }

    public function dispatch(object $event): object
    {
        /** @var object $result */
        $result = $this->dispatcher->dispatch($event);

        return $result;
    }
}
