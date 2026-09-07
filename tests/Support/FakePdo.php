<?php

declare(strict_types=1);

namespace Resumable\ChunkedUploader\Tests\Support;

use PDO;
use PDOStatement;

/**
 * PDO double that reports a configurable driver name and captures every
 * statement issued, so the SQL generator can be verified for each dialect
 * without contacting a real database server.
 */
final class FakePdo extends PDO
{
    /** @param list<string> */
    public array $executed = [];

    /** @param list<string> */
    public array $prepared = [];

    /**
     * Identifiers returned by the next identifier-scan during a paged
     * cleanExpired() run; replayed once so an empty follow-up page ends the
     * sweep.
     *
     * @var list<string>
     */
    public array $nextFetchRows = [];

    /** Number of rows a DELETE statement reports via rowCount(). */
    public int $deleteRowCount = 0;

    private bool $inTransactionFlag = false;

    private string $serverVersion = '3.45.1';

    public function __construct(private readonly string $driverName)
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return $this->driverName;
        }

        if ($attribute === PDO::ATTR_SERVER_VERSION) {
            return $this->serverVersion;
        }

        return parent::getAttribute($attribute);
    }

    /**
     * Overrides the reported server version so SQLite version-gated SQL can be
     * exercised in both the modern and legacy branches.
     */
    public function setServerVersion(string $version): void
    {
        $this->serverVersion = $version;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return true;
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;
        return 0;
    }

    public function prepare(string $statement, array $options = []): PDOStatement|false
    {
        $this->prepared[] = $statement;

        if (str_contains($statement, 'DELETE FROM')) {
            return new FakePdoStatement([], $this->deleteRowCount);
        }

        if (str_contains($statement, 'SELECT identifier FROM')) {
            $rows = $this->nextFetchRows;
            $this->nextFetchRows = [];
            return new FakePdoStatement($rows);
        }

        return new FakePdoStatement();
    }

    public function beginTransaction(): bool
    {
        $this->inTransactionFlag = true;
        return true;
    }

    public function commit(): bool
    {
        $this->inTransactionFlag = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->inTransactionFlag = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransactionFlag;
    }
}

/**
 * Minimal PDOStatement double returning success and no rows for the SQL
 * generator dialect tests.
 */
final class FakePdoStatement extends PDOStatement
{
    /** @var list<string> */
    private array $remainingRows;

    public function __construct(array $rows = [], private readonly int $affectedRows = 0)
    {
        $this->remainingRows = $rows;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->remainingRows) ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = $this->remainingRows;
        $this->remainingRows = [];

        return $rows;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }
}
