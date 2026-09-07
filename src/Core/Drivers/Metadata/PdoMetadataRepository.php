<?php

declare(strict_types=1);

// File: src/Core/Drivers/Metadata/PdoMetadataRepository.php

namespace Resumable\ChunkedUploader\Core\Drivers\Metadata;

use PDO;
use PDOException;
use Resumable\ChunkedUploader\Core\Contracts\MetadataRepositoryInterface;
use Resumable\ChunkedUploader\Core\Contracts\ProgressTrackerInterface;
use Resumable\ChunkedUploader\Core\Exceptions\MetadataException;
use Resumable\ChunkedUploader\Core\Models\UploadState;

/**
 * Persists upload state in a relational database via PDO.
 *
 * A dedicated table stores one row per upload; the list of uploaded chunk
 * indices is kept in a JSON column. Row-level locking (`FOR UPDATE`) is used
 * inside an explicit transaction to guarantee that concurrent chunk uploads for
 * the same identifier converge instead of clobbering one another.
 *
 * SQLite cannot execute `SELECT ... FOR UPDATE` and instead uses deferred
 * transactions with an exclusive write lock; for in-memory test databases this
 * is handled transparently. Schema creation is exposed via
 * {@see ensureSchema()} so adapters can provision the table externally.
 */
class PdoMetadataRepository implements MetadataRepositoryInterface, ProgressTrackerInterface
{
    /** @var array<string, string> Quoted column name cache keyed by table name. */
    private array $quotedColumns = [];

    /**
     * @param PDO    $pdo       Database connection; exceptions must be enabled
     * @param string $tableName Name of the upload-state table
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tableName = 'chunked_upload_states',
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function get(string $identifier): ?UploadState
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT identifier, total_chunks, total_size, original_filename, uploaded_chunks, is_completed, final_path '
                . 'FROM ' . $this->quoteIdent($this->tableName) . ' WHERE identifier = :id',
            );
            $stmt->execute([':id' => $identifier]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new MetadataException('Unable to read upload state from the database.', 0, $e);
        }

        if ($row === false || $row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function save(UploadState $state): void
    {
        try {
            $this->pdo->beginTransaction();
            try {
                $this->upsert($state);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        } catch (PDOException $e) {
            throw new MetadataException('Unable to write upload state to the database.', 0, $e);
        }
    }

    public function delete(string $identifier): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . $this->quoteIdent($this->tableName) . ' WHERE identifier = :id',
            );
            $stmt->execute([':id' => $identifier]);
        } catch (PDOException $e) {
            throw new MetadataException('Unable to delete upload state from the database.', 0, $e);
        }
    }

    public function markChunkAsUploaded(string $identifier, int $chunkIndex): UploadState
    {
        if ($chunkIndex < 0) {
            throw new MetadataException('Chunk index must be zero or greater.');
        }

        try {
            $this->pdo->beginTransaction();
            try {
                $row = $this->lockForUpdate($identifier);
                if ($row === null) {
                    $this->pdo->rollBack();
                    throw new MetadataException(
                        sprintf('Cannot mark chunk %d uploaded: upload "%s" not found.', $chunkIndex, $identifier),
                    );
                }

                $state = $this->hydrate($row);
                $updated = $state->withUploadedChunk($chunkIndex);

                $this->upsert($updated);
                $this->pdo->commit();

                return $updated;
            } catch (MetadataException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        } catch (PDOException $e) {
            throw new MetadataException('Unable to atomically advance upload state.', 0, $e);
        }
    }

    public function cleanExpired(int $ttlSeconds): int
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('TTL must be a positive number of seconds.');
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite' || $driver === 'sqlsrv'
            ? 'DELETE FROM ' . $this->quoteIdent($this->tableName) . ' WHERE updated_at <= :cutoff'
            : 'DELETE FROM ' . $this->quoteIdent($this->tableName) . ' WHERE is_completed = 0 AND updated_at <= :cutoff';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':cutoff' => time() - $ttlSeconds]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new MetadataException('Unable to purge expired upload states.', 0, $e);
        }
    }

    public function getPercentage(UploadState $state): float
    {
        return $state->totalChunks === 0 ? 0.0 : count($state->uploadedChunks) / $state->totalChunks * 100.0;
    }

    public function isComplete(UploadState $state): bool
    {
        return $state->isComplete();
    }

    public function getMissingChunkIndices(UploadState $state): array
    {
        return array_values(array_diff(range(0, $state->totalChunks - 1), $state->uploadedChunks));
    }

    /**
     * Provisions the upload-state table if it does not already exist.
     *
     * Accounts for the schema differences between SQLite/MySQL/PostgreSQL. The
     * `updated_at` column uses the platform timestamp type where the value is a
     * unix epoch; see {@see writeTimestamp()} for the cross-dialect integer form.
     */
    public function ensureSchema(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $table = $this->quoteIdent($this->tableName);

        try {
            if ($driver === 'sqlite') {
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
                    . 'identifier VARCHAR(128) PRIMARY KEY, '
                    . 'total_chunks INT NOT NULL, '
                    . 'total_size BIGINT NOT NULL, '
                    . 'original_filename VARCHAR(255) NOT NULL, '
                    . 'uploaded_chunks TEXT NOT NULL, '
                    . 'is_completed TINYINT(1) NOT NULL DEFAULT 0, '
                    . 'final_path TEXT NULL, '
                    . 'updated_at INT NOT NULL'
                    . ')',
                );
            } else {
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
                    . 'identifier VARCHAR(128) NOT NULL, '
                    . 'total_chunks INTEGER NOT NULL, '
                    . 'total_size BIGINT NOT NULL, '
                    . 'original_filename VARCHAR(255) NOT NULL, '
                    . 'uploaded_chunks TEXT NOT NULL, '
                    . 'is_completed SMALLINT NOT NULL DEFAULT 0, '
                    . 'final_path TEXT NULL, '
                    . 'updated_at BIGINT NOT NULL, '
                    . 'PRIMARY KEY (identifier)'
                    . ')',
                );
            }
        } catch (PDOException $e) {
            throw new MetadataException('Unable to create upload-state schema.', 0, $e);
        }
    }

    /**
     * Reads and row-locks the state row for a synchronous update.
     *
     * @return array<string, mixed>|null The raw row, or null when it does not exist
     */
    private function lockForUpdate(string $identifier): ?array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = $driver === 'sqlite' ? '' : ' FOR UPDATE';
        $sql = 'SELECT identifier, total_chunks, total_size, original_filename, uploaded_chunks, is_completed, final_path '
            . 'FROM ' . $this->quoteIdent($this->tableName) . ' WHERE identifier = :id' . $forUpdate;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates a state row, touching the updated_at epoch column.
     */
    private function upsert(UploadState $state): void
    {
        $table = $this->quoteIdent($this->tableName);
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $params = [
            ':identifier' => $state->identifier,
            ':totalChunks' => $state->totalChunks,
            ':totalSize' => $state->totalSize,
            ':filename' => $state->originalFilename,
            ':uploaded' => $this->encodeChunks($state->uploadedChunks),
            ':completed' => $state->isCompleted ? 1 : 0,
            ':finalPath' => $state->finalPath,
            ':updatedAt' => time(),
        ];

        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO ' . $table . ' '
                . '(identifier, total_chunks, total_size, original_filename, uploaded_chunks, is_completed, final_path, updated_at) '
                . 'VALUES (:identifier, :totalChunks, :totalSize, :filename, :uploaded, :completed, :finalPath, :updatedAt) '
                . 'ON CONFLICT(identifier) DO UPDATE SET '
                . 'total_chunks = excluded.total_chunks, '
                . 'total_size = excluded.total_size, '
                . 'original_filename = excluded.original_filename, '
                . 'uploaded_chunks = excluded.uploaded_chunks, '
                . 'is_completed = excluded.is_completed, '
                . 'final_path = excluded.final_path, '
                . 'updated_at = excluded.updated_at';
        } elseif ($driver === 'pgsql') {
            $sql = 'INSERT INTO ' . $table . ' '
                . '(identifier, total_chunks, total_size, original_filename, uploaded_chunks, is_completed, final_path, updated_at) '
                . 'VALUES (:identifier, :totalChunks, :totalSize, :filename, :uploaded, :completed, :finalPath, :updatedAt) '
                . 'ON CONFLICT(identifier) DO UPDATE SET '
                . 'total_chunks = EXCLUDED.total_chunks, '
                . 'total_size = EXCLUDED.total_size, '
                . 'original_filename = EXCLUDED.original_filename, '
                . 'uploaded_chunks = EXCLUDED.uploaded_chunks, '
                . 'is_completed = EXCLUDED.is_completed, '
                . 'final_path = EXCLUDED.final_path, '
                . 'updated_at = EXCLUDED.updated_at';
        } else {
            $sql = 'INSERT INTO ' . $table . ' '
                . '(identifier, total_chunks, total_size, original_filename, uploaded_chunks, is_completed, final_path, updated_at) '
                . 'VALUES (:identifier, :totalChunks, :totalSize, :filename, :uploaded, :completed, :finalPath, :updatedAt) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'total_chunks = VALUES(total_chunks), '
                . 'total_size = VALUES(total_size), '
                . 'original_filename = VALUES(original_filename), '
                . 'uploaded_chunks = VALUES(uploaded_chunks), '
                . 'is_completed = VALUES(is_completed), '
                . 'final_path = VALUES(final_path), '
                . 'updated_at = VALUES(updated_at)';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UploadState
    {
        return new UploadState(
            identifier: (string) $row['identifier'],
            totalChunks: (int) $row['total_chunks'],
            totalSize: (int) $row['total_size'],
            originalFilename: (string) $row['original_filename'],
            uploadedChunks: $this->decodeChunks((string) $row['uploaded_chunks']),
            isCompleted: (bool) $row['is_completed'],
            finalPath: $row['final_path'] === null ? null : (string) $row['final_path'],
        );
    }

    /**
     * @param array<int, mixed> $chunks
     */
    private function encodeChunks(array $chunks): string
    {
        $json = json_encode(array_values(array_map('intval', $chunks)));
        if ($json === false) {
            throw new MetadataException('Unable to serialize uploaded-chunk indices.');
        }

        return $json;
    }

    /**
     * @return array<int, int>
     */
    private function decodeChunks(string $raw): array
    {
        if ($raw === '' || $raw === '[]') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new MetadataException('Stored uploaded-chunk indices are corrupt.');
        }

        $indices = array_values(array_unique(array_map('intval', $data)));
        sort($indices, SORT_NUMERIC);

        return $indices;
    }

    private function quoteIdent(string $identifier): string
    {
        return $this->quotedColumns[$identifier] ??= $this->quoteForDriver($identifier);
    }

    private function quoteForDriver(string $identifier): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql' || $driver === 'sqlsrv') {
            return '"' . str_replace('"', '""', $identifier) . '"';
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
