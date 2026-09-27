<?php

declare(strict_types=1);

// File: src/Core/Contracts/LockManagerInterface.php

namespace Resumable\ChunkedUploader\Core\Contracts;

/**
 * Coordinates a short critical section across several application servers.
 *
 * The uploader needs one narrowly-scoped mutual-exclusion primitive: while a
 * single upload is being assembled into its final object, no other node may
 * start the same assembly. Without it, two nodes that receive the final chunks
 * of a file simultaneously both observe a complete upload and both run the
 * assembler, which for S3 means two concurrent CompleteMultipartUpload calls on
 * one upload id and for local storage means two writers on one destination.
 *
 * Every implementation must guarantee three properties, because they are what
 * make the lock safe rather than merely advisory:
 *
 *  1. **Mutual exclusion** -- at most one holder at a time, decided atomically
 *     by the datastore.
 *  2. **Bounded leases** -- every lock carries a TTL so a node that crashes,
 *     is killed mid-assembly, or loses its network cannot wedge the upload
 *     forever. A lock is therefore *advisory with a deadline*, not a
 *     distributed transaction: correctness must not depend on releasing.
 *  3. **Owner-verified release** -- `release()` is a no-op unless the caller
 *     still owns the lock. A node whose lease already expired and was taken
 *     over by another must not delete the new owner's lock on its way out.
 *
 * Implementations are expected to be safe for concurrent use across processes
 * and to degrade toward "lock unavailable" rather than throwing when the
 * datastore is reachable but the lock is simply held.
 */
interface LockManagerInterface
{
    /**
     * Attempts to take the lock, without blocking.
     *
     * Never waits: a caller that cannot proceed should decide what to do rather
     * than block a request thread on an unknown holder.
     *
     * @param string $key        Namespaced lock key, unique per contended resource
     * @param int    $ttlSeconds Lease in seconds. Bounds how long a crashed holder
     *                           can block the resource, so it must comfortably
     *                           exceed the expected critical section
     *
     * @return bool True when the lock is now held by this caller
     */
    public function acquire(string $key, int $ttlSeconds = 60): bool;

    /**
     * Releases a previously acquired lock.
     *
     * Idempotent, and a no-op when the lock is held by another owner or has
     * already expired. Never throws for a lock this caller does not hold, since
     * it is normally invoked from a `finally` block where an exception would
     * mask the original failure.
     */
    public function release(string $key): void;
}
