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

    private bool $inTransactionFlag = false;

    public function __construct(private readonly string $driverName)
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return $this->driverName;
        }

        return parent::getAttribute($attribute);
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
    public function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }
}
