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

    /**
     * @template T of object
     *
     * @param T $event
     *
     * @return T
     *
     * The template is repeated from {@see EventDispatcherInterface} so callers
     * get their own event type back instead of a bare `object`. Laravel's
     * Dispatcher is not generic in every supported version, hence the local
     * assertion; the runtime contract is "the event you passed in, after
     * propagation", so this reattaches nothing that was not already true.
     */
    public function dispatch(object $event): object
    {
        $dispatched = $this->dispatcher->dispatch($event);

        /** @var T $dispatched */
        return $dispatched;
    }
}
