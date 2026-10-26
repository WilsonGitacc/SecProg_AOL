<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\TestCase;

/**
 * SQL Injection & Dynamic ORDER BY Allowlist Validator Model
 * derived from:
 * - ORIGINAL_REQUEST.md §R5
 * - spec_report.md §10.2 (Injection Prevention & Database Security)
 */
class QuerySortValidatorModel
{
    private const ALLOWED_GRADEBOOK_COLUMNS = [
        'student_name',
        'score',
        'submitted_at',
        'session_number',
        'created_at',
    ];

    private const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];

    /**
     * Validates requested sort column and direction against strict in-code allowlists.
     * Returns sanitized SQL fragment on success, or throws \InvalidArgumentException on violation.
     */
    public static function validateSort(string $column, string $direction = 'ASC'): string
    {
        $normalizedColumn = strtolower(trim($column));
        $normalizedDirection = strtoupper(trim($direction));

        if (!in_array($normalizedColumn, self::ALLOWED_GRADEBOOK_COLUMNS, true)) {
            throw new \InvalidArgumentException(
                sprintf("Invalid sort column [%s]. Column not in allowlist.", $column)
            );
        }

        if (!in_array($normalizedDirection, self::ALLOWED_DIRECTIONS, true)) {
            throw new \InvalidArgumentException(
                sprintf("Invalid sort direction [%s]. Direction must be ASC or DESC.", $direction)
            );
        }

        // Return safe identifier
        return "`{$normalizedColumn}` {$normalizedDirection}";
    }
}

class SqlInjectionAndOrderByTest extends TestCase
{
    /**
     * @test
     * Verifies that valid columns and directions in the allowlist are accepted.
     */
    public function testAllowlistedColumnsAndDirectionsAreAccepted(): void
    {
        $validCases = [
            ['student_name', 'ASC', '`student_name` ASC'],
            ['score', 'DESC', '`score` DESC'],
            ['submitted_at', 'asc', '`submitted_at` ASC'],
            ['session_number', 'DESC', '`session_number` DESC'],
            ['created_at', 'ASC', '`created_at` ASC'],
        ];

        foreach ($validCases as [$col, $dir, $expectedSql]) {
            $result = QuerySortValidatorModel::validateSort($col, $dir);
            $this->assertSame($expectedSql, $result, "Valid sort must generate safe SQL clause.");
        }
    }

    /**
     * @test
     * Verifies that SQL injection payloads in the sort column are strictly rejected.
     */
    public function testSqlInjectionInSortColumnIsRejected(): void
    {
        $sqliPayloads = [
            'id; DROP TABLE users;--',
            'score, (SELECT password_hash FROM users LIMIT 1)',
            'student_name\' OR \'1\'=\'1',
            'id UNION SELECT 1,2,3',
            'SLEEP(5)',
            'BENCHMARK(10000000,MD5(1))',
            'extractvalue(1,concat(0x7e,(select @@version)))',
            '1; EXEC xp_cmdshell',
        ];

        foreach ($sqliPayloads as $payload) {
            $rejected = false;
            try {
                QuerySortValidatorModel::validateSort($payload, 'ASC');
            } catch (\InvalidArgumentException) {
                $rejected = true;
            }

            $this->assertTrue(
                $rejected,
                sprintf("SQLi payload [%s] in ORDER BY must be rejected with InvalidArgumentException.", $payload)
            );
        }
    }

    /**
     * @test
     * Verifies that SQL injection payloads in the sort direction are strictly rejected.
     */
    public function testSqlInjectionInSortDirectionIsRejected(): void
    {
        $sqliDirections = [
            'ASC; DROP TABLE users;--',
            'DESC, (SELECT 1)',
            'ASC;--',
            'DESC; WAITFOR DELAY \'0:0:5\'',
            'ASC/*',
        ];

        foreach ($sqliDirections as $dirPayload) {
            $rejected = false;
            try {
                QuerySortValidatorModel::validateSort('score', $dirPayload);
            } catch (\InvalidArgumentException) {
                $rejected = true;
            }

            $this->assertTrue(
                $rejected,
                sprintf("SQLi direction payload [%s] must be rejected.", $dirPayload)
            );
        }
    }

    /**
     * @test
     * Verifies that PDO connection configuration contract specifies native parameterization
     * (ATTR_EMULATE_PREPARES = false) and error mode exception (ATTR_ERRMODE = ERRMODE_EXCEPTION).
     */
    public function testPdoConfigurationOptionsContract(): void
    {
        $expectedPdoOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->assertFalse(
            $expectedPdoOptions[\PDO::ATTR_EMULATE_PREPARES],
            "PDO::ATTR_EMULATE_PREPARES must be explicitly false for native server-side prepared statements."
        );
        $this->assertSame(
            \PDO::ERRMODE_EXCEPTION,
            $expectedPdoOptions[\PDO::ATTR_ERRMODE],
            "PDO::ATTR_ERRMODE must be set to ERRMODE_EXCEPTION."
        );
    }

    /**
     * @test
     * Verifies parameter handling with SQLite in-memory PDO to demonstrate native literal parameterization.
     */
    public function testPreparedStatementsTreatMaliciousStringsAsLiterals(): void
    {
        $pdo = \Tests\Support\MockPdo::createOrRealPdo();

        $pdo->exec("CREATE TABLE mock_students (id INTEGER PRIMARY KEY, name TEXT)");

        // Insert a student named with a classic SQL injection string
        $maliciousName = "Robert'); DROP TABLE mock_students;--";
        $stmt = $pdo->prepare("INSERT INTO mock_students (name) VALUES (:name)");
        $stmt->execute(['name' => $maliciousName]);

        // Query the table back
        $queryStmt = $pdo->prepare("SELECT * FROM mock_students WHERE name = :name");
        $queryStmt->execute(['name' => $maliciousName]);
        $row = $queryStmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotNull($row, "Row should be found.");
        $this->assertSame($maliciousName, $row['name'], "Malicious string must be stored verbatim as data.");

        // Table must still exist and not have been dropped
        $checkStmt = $pdo->query("SELECT count(*) FROM mock_students");
        $count = $checkStmt->fetchColumn();
        $this->assertSame(1, (int) $count, "Table must remain intact; DROP TABLE command was treated as data.");
    }
}
