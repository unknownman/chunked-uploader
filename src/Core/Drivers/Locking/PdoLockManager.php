<?php

declare(strict_types=1);

// File: src/Core/Drivers/Locking/PdoLockManager.php

namespace Resumable\ChunkedUploader\Core\Drivers\Locking;

use PDO;
use PDOException;
use Resumable\ChunkedUploader\Core\Contracts\LockManagerInterface;

/**
 * Distributed lock backed by a relational database via PDO.
 *
 * Uses a dedicated `chunked_uploader_locks` table rather than database-native
 * advisory locks (`pg_advisory_lock`, `GET_LOCK`). Advisory locks are tied to a
 * physical connection: any reconnect, pool rotation, or failover silently drops
 * them, and MySQL's `GET_LOCK` has no lease at all, so a crashed holder would
 * wedge the upload permanently. A leased table row survives connection churn
 * and carries its own expiry, which is what the uploader actually needs.
 *
 * Atomicity comes from the primary key on `lock_key`, which makes the
 * datastore itself the arbiter: the winner is whichever `INSERT` commits, and
 * every other caller loses on the unique violation. There is no
 * check-then-insert window, so no transaction isolation level or advisory-lock
 * primitive is required, and the same code is correct on MySQL, PostgreSQL and
 * SQLite.
 *
 * Release is scoped by owner token (`DELETE ... WHERE lock_key = ? AND token = ?`)
 * so a node whose lease lapsed cannot delete the lock a successor now holds.
 */
class PdoLockManager implements LockManagerInterface
{
    private readonly string $token;

    /**
     * @var array<string, true>
     */
    private array $held = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tableName = 'chunked_uploader_locks',
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->token = self::generateToken();

        if ($this->isSqlite()) {
            // Contended writers otherwise fail immediately with "database is
            // locked", which would surface as a spurious lock failure rather
            // than as a brief wait.
            try {
                $this->pdo->exec('PRAGMA busy_timeout = 5000');
            } catch (PDOException) {
                // busy_timeout is advisory; construction must not fail without it.
            }
        }
    }

    public function acquire(string $key, int $ttlSeconds = 60): bool
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Lock TTL must be at least one second.');
        }

        if (isset($this->held[$key])) {
            return true;
        }

        $expiresAt = time() + $ttlSeconds;

        // The insert is the atomic step. On a clean table it always wins; the
        // unique violation is precisely the "someone else got there first"
        // signal, which is why no SELECT-then-INSERT check is needed.
        if ($this->tryInsert($key, $expiresAt)) {
            $this->held[$key] = true;

            return true;
        }

        // The row exists, so either a live holder owns it or its lease lapsed
        // (crash, kill, lost connection). A single conditional UPDATE steals an
        // expired lock atomically: two nodes racing to reclaim the same dead
        // lock cannot both succeed, because the second finds expires_at already
        // in the future.
        if ($this->tryStealExpired($key, $expiresAt)) {
            $this->held[$key] = true;

            return true;
        }

        return false;
    }

    public function release(string $key): void
    {
        if (!isset($this->held[$key])) {
            return;
        }

        unset($this->held[$key]);

        $this->deleteOwnedRow($key);
    }

    /**
     * Provisions the lock table when absent.
     *
     * Kept explicit, mirroring {@see \Resumable\ChunkedUploader\Core\Drivers\Metadata\PdoMetadataRepository::ensureSchema()},
     * so a deployment can manage schema with migrations instead of granting
     * DDL rights to the application.
     */
    public function ensureSchema(): void
    {
        $table = $this->quoteIdent($this->tableName);

        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
                . 'lock_key VARCHAR(190) NOT NULL, '
                // The primary key is the mutual-exclusion primitive.
                . 'token VARCHAR(64) NOT NULL, '
                . 'expires_at BIGINT NOT NULL, '
                . 'PRIMARY KEY (lock_key)'
                . ')',
            );
        } catch (PDOException $e) {
            throw new \RuntimeException('Unable to create the upload lock schema.', 0, $e);
        }
    }

    /**
     * Deletes rows whose lease has lapsed, so the table cannot grow without bound.
     *
     * @return int Number of rows removed
     */
    public function purgeExpired(): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . $this->quoteIdent($this->tableName) . ' WHERE expires_at <= :now',
            );
            $stmt->execute([':now' => time()]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new \RuntimeException('Unable to purge expired upload locks.', 0, $e);
        }
    }

    /**
     * @return bool True when this caller inserted and therefore owns the lock
     */
    private function tryInsert(string $key, int $expiresAt): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . $this->quoteIdent($this->tableName) . ' (lock_key, token, expires_at) '
                . 'VALUES (:key, :token, :expires)',
            );
            $stmt->execute([':key' => $key, ':token' => $this->token, ':expires' => $expiresAt]);

            return true;
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                return false;
            }

            throw new \RuntimeException('Unable to acquire the upload lock.', 0, $e);
        }
    }

    /**
     * @return bool True when an expired row was reclaimed by this caller
     */
    private function tryStealExpired(string $key, int $expiresAt): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . $this->quoteIdent($this->tableName) . ' SET token = :token, expires_at = :expires '
                . 'WHERE lock_key = :key AND expires_at <= :now',
            );
            $stmt->execute([
                ':token' => $this->token,
                ':expires' => $expiresAt,
                ':key' => $key,
                ':now' => time(),
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException('Unable to reclaim the expired upload lock.', 0, $e);
        }
    }

    private function deleteOwnedRow(string $key): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . $this->quoteIdent($this->tableName) . ' WHERE lock_key = :key AND token = :token',
            );
            $stmt->execute([':key' => $key, ':token' => $this->token]);
        } catch (PDOException $e) {
            // A lock that outlives its owner is reclaimed by the TTL, so a
            // failed delete is never correctness-critical. It must not throw,
            // because release() runs in a finally block and would otherwise mask
            // whatever exception is already unwinding.
            return;
        }
    }

    /**
     * Detects the unique-constraint violation signalling a lost race.
     *
     * SQLSTATE 23000 is the standard integrity-constraint class and is reported
     * by MySQL, PostgreSQL and SQLite alike; the message check covers drivers
     * that surface a vendor code instead.
     */
    private function isDuplicateKey(PDOException $e): bool
    {
        if (($e->getCode() === '23000') || ($e->errorInfo[0] ?? null) === '23000') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'primary key');
    }

    private function isSqlite(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    private function quoteIdent(string $identifier): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql' || $driver === 'sqlsrv') {
            return '"' . str_replace('"', '""', $identifier) . '"';
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function generateToken(): string
    {
        $pid = getmypid();

        return ($pid === false ? 'na' : (string) $pid) . '-' . bin2hex(random_bytes(16));
    }
}
