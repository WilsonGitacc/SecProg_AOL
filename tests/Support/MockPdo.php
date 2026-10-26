<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * In-memory transactional database engine simulator for testing environments
 * where external PDO drivers (pdo_mysql, pdo_sqlite) are not installed on the CLI host.
 */
class MockPdo
{
    private array $tables = [];
    private bool $inTransaction = false;
    private array $transactionBackup = [];
    private bool $rowLocked = false;

    public function __construct()
    {
    }

    public static function createOrRealPdo(): object
    {
        if (extension_loaded('pdo') && in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            return new \PDO('sqlite::memory:', null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        }

        return new self();
    }

    public function exec(string $sql): int
    {
        $sql = trim($sql);
        if (str_starts_with(strtoupper($sql), 'CREATE TABLE')) {
            if (preg_match('/CREATE TABLE\s+([a-zA-Z0-9_]+)/i', $sql, $m)) {
                $tableName = $m[1];
                if (!isset($this->tables[$tableName])) {
                    $this->tables[$tableName] = [];
                }
            }
            return 0;
        }

        if (str_starts_with(strtoupper($sql), 'INSERT INTO')) {
            if (preg_match('/INSERT INTO\s+([a-zA-Z0-9_]+)\s*\((.*?)\)\s*VALUES\s*\((.*?)\)/i', $sql, $m)) {
                $tableName = $m[1];
                $cols = array_map('trim', explode(',', $m[2]));
                $vals = array_map(function ($v) {
                    $v = trim($v);
                    return is_numeric($v) ? (int) $v : trim($v, "'\"");
                }, explode(',', $m[3]));

                $row = array_combine($cols, $vals);
                $this->tables[$tableName][] = $row;
                return 1;
            }
        }

        return 0;
    }

    public function query(string $sql): MockPdoStatement
    {
        $stmt = $this->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    public function prepare(string $sql): MockPdoStatement
    {
        return new MockPdoStatement($this, $sql);
    }

    public function beginTransaction(): bool
    {
        $this->inTransaction = true;
        $this->transactionBackup = $this->tables;
        $this->rowLocked = false;
        return true;
    }

    public function commit(): bool
    {
        $this->inTransaction = false;
        $this->transactionBackup = [];
        $this->rowLocked = false;
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->inTransaction) {
            $this->tables = $this->transactionBackup;
            $this->inTransaction = false;
            $this->transactionBackup = [];
            $this->rowLocked = false;
        }
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function getTable(string $name): array
    {
        return $this->tables[$name] ?? [];
    }

    public function setTable(string $name, array $rows): void
    {
        $this->tables[$name] = $rows;
    }

    public function setRowLocked(bool $locked): void
    {
        $this->rowLocked = $locked;
    }

    public function isRowLocked(): bool
    {
        return $this->rowLocked;
    }
}

class MockPdoStatement
{
    private MockPdo $pdo;
    private string $sql;
    private array $resultSet = [];
    private int $cursor = 0;

    public function __construct(MockPdo $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function execute(?array $params = null): bool
    {
        $sql = trim($this->sql);

        // Handle SELECT ... FOR UPDATE or SELECT
        if (str_starts_with(strtoupper($sql), 'SELECT')) {
            if (str_contains(strtoupper($sql), 'FOR UPDATE')) {
                $this->pdo->setRowLocked(true);
            }

            if (preg_match('/FROM\s+([a-zA-Z0-9_]+)/i', $sql, $m)) {
                $tableName = $m[1];
                $rows = $this->pdo->getTable($tableName);

                if (preg_match('/WHERE\s+([a-zA-Z0-9_]+)\s*=\s*(:?[a-zA-Z0-9_\'\"]+)/i', $sql, $wm)) {
                    $col = $wm[1];
                    $rawVal = $wm[2];
                    if (str_starts_with($rawVal, ':')) {
                        $paramName = substr($rawVal, 1);
                        $targetVal = $params[$paramName] ?? $params[":{$paramName}"] ?? null;
                    } else {
                        $targetVal = is_numeric($rawVal) ? (int) $rawVal : trim($rawVal, "'\"");
                    }

                    $filtered = [];
                    foreach ($rows as $row) {
                        if (($row[$col] ?? null) == $targetVal) {
                            $filtered[] = $row;
                        }
                    }
                    $this->resultSet = $filtered;
                } elseif (str_contains(strtoupper($sql), 'COUNT(*)')) {
                    $this->resultSet = [['count(*)' => count($rows)]];
                } else {
                    $this->resultSet = $rows;
                }

                // Column projection
                if (preg_match('/SELECT\s+(.*?)\s+FROM/i', $sql, $cm)) {
                    $rawCols = trim($cm[1]);
                    if ($rawCols !== '*' && !str_contains(strtoupper($rawCols), 'COUNT(*)')) {
                        $selectedCols = array_map('trim', explode(',', $rawCols));
                        $projected = [];
                        foreach ($this->resultSet as $row) {
                            $pRow = [];
                            foreach ($selectedCols as $sc) {
                                $pRow[$sc] = $row[$sc] ?? null;
                            }
                            $projected[] = $pRow;
                        }
                        $this->resultSet = $projected;
                    }
                }
            }
            $this->cursor = 0;
            return true;
        }

        // Handle UPDATE
        if (str_starts_with(strtoupper($sql), 'UPDATE')) {
            if (preg_match('/UPDATE\s+([a-zA-Z0-9_]+)\s+SET\s+current_members\s*=\s*current_members\s*\+\s*1\s+WHERE\s+id\s*=\s*:id/i', $sql)) {
                $id = $params['id'] ?? $params[':id'] ?? null;
                $rows = $this->pdo->getTable('project_groups');
                foreach ($rows as &$row) {
                    if ($row['id'] == $id) {
                        $row['current_members'] = (int) $row['current_members'] + 1;
                    }
                }
                unset($row);
                $this->pdo->setTable('project_groups', $rows);
                return true;
            }
        }

        // Handle INSERT
        if (str_starts_with(strtoupper($sql), 'INSERT INTO')) {
            if (preg_match('/INSERT INTO\s+([a-zA-Z0-9_]+)/i', $sql, $m)) {
                $tableName = $m[1];
                $rows = $this->pdo->getTable($tableName);

                $newRow = [];
                if ($params) {
                    foreach ($params as $k => $v) {
                        $col = ltrim($k, ':');
                        $newRow[$col] = $v;
                    }
                }

                // Check primary key constraint for group_members (group_id, student_id)
                if ($tableName === 'group_members') {
                    foreach ($rows as $existing) {
                        if (
                            $existing['group_id'] == $newRow['group_id'] &&
                            $existing['student_id'] == $newRow['student_id']
                        ) {
                            throw new \RuntimeException("Integrity constraint violation: duplicate entry", 23000);
                        }
                    }
                }

                $rows[] = $newRow;
                $this->pdo->setTable($tableName, $rows);
                return true;
            }
        }

        return true;
    }

    public function fetch(int $mode = \PDO::FETCH_ASSOC): mixed
    {
        if (isset($this->resultSet[$this->cursor])) {
            $row = $this->resultSet[$this->cursor];
            $this->cursor++;
            return $row;
        }
        return false;
    }

    public function fetchColumn(int $columnNumber = 0): mixed
    {
        $row = $this->fetch();
        if ($row && is_array($row)) {
            $values = array_values($row);
            return $values[$columnNumber] ?? null;
        }
        return false;
    }
}
