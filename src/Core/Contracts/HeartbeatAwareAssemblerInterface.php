<?php

declare(strict_types=1);

// File: src/Core/Contracts/HeartbeatAwareAssemblerInterface.php

namespace Resumable\ChunkedUploader\Core\Contracts;

use Resumable\ChunkedUploader\Core\Locking\AssemblyHeartbeat;

/**
 * An assembler that can checkpoint a long-running assembly.
 *
 * Optional capability, not a requirement: {@see FileAssemblerInterface} stays
 * unchanged so existing custom assemblers keep working, and
 * {@see \Resumable\ChunkedUploader\Core\ChunkUploader} treats the absence of
 * this interface as "no renewal possible" rather than as an error.
 *
 * An implementation calls {@see AssemblyHeartbeat::tick()} at a point where
 * control returns to PHP between units of work, so that
 * {@see LockManagerInterface::renew()} can run. A streaming assembler ticking
 * once per part is the intended pattern.
 *
 * Implementations must tolerate a null heartbeat: it is detached in a `finally`
 * block when the critical section ends, and an assembler retained after that
 * point must not renew a lease it no longer holds.
 */
interface HeartbeatAwareAssemblerInterface extends FileAssemblerInterface
{
    /**
     * Attaches or detaches the heartbeat guarding the current critical section.
     *
     * @param AssemblyHeartbeat|null $heartbeat Null detaches; called from
     *                                          `finally` blocks
     */
    public function setHeartbeat(?AssemblyHeartbeat $heartbeat): void;
}
