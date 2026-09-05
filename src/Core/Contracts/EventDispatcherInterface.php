<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Core\Contracts;

/**
 * Decouples the core domain from any framework-specific event system.
 *
 * The Core dispatches strongly typed event objects through this interface;
 * framework bridges provide concrete implementations that forward these events
 * to the host application's dispatcher (PSR-14, Laravel's Dispatcher, or
 * Symfony's EventDispatcher).
 */
interface EventDispatcherInterface
{
    /**
     * Dispatches an event object to all registered listeners.
     *
     * @template T of object
     *
     * @param T $event The event object to dispatch
     *
     * @return T The event after it has been handled by listeners; listeners
     *           may mutate or replace the event before it is returned
     */
    public function dispatch(object $event): object;
}