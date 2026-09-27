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

    /**
     * @template T of object
     *
     * @param T $event
     *
     * @return T
     *
     * The template is repeated from {@see EventDispatcherInterface} on purpose.
     * Without it the parameter degrades to plain `object` and the return is
     * inferred as `object` too, so every caller of a typed event gets `object`
     * back and loses the type it dispatched -- the exact loss the core
     * interface's generics exist to prevent.
     */
    public function dispatch(object $event): object
    {
        $dispatched = $this->dispatcher->dispatch($event);

        // Symfony's contract is not generic in every supported version, so the
        // call resolves to plain `object` here even though the runtime contract is
        // "the event you passed in, after propagation". Reasserting the type
        // keeps callers' own generics intact across the bridge.
        /** @var T $dispatched */
        return $dispatched;
    }
}
