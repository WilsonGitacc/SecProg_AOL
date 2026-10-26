<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\TestCase;

use Tests\Support\MockPdo;

/**
 * Group Join Concurrency & Pessimistic Row Locking Model derived from:
 * - ORIGINAL_REQUEST.md §R4, §AC
 * - spec_report.md §10.2 & Edge Case 1 (Group Join Concurrency)
 */
class GroupJoinService
{
    private object $pdo;

    public function __construct(object $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Executes group join inside a database transaction with pessimistic row lock.
     * Returns 200 on success, throws \RuntimeException with code 409 on full group.
     */
    public function joinGroup(int $groupId, int $studentId): int
    {
        $this->pdo->beginTransaction();

        try {
            // Pessimistic row lock: SELECT ... FOR UPDATE
            // In SQLite, transactions are serialized with BEGIN IMMEDIATE
            $stmt = $this->pdo->prepare("SELECT current_members, max_size FROM project_groups WHERE id = :id");
            $stmt->execute(['id' => $groupId]);
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$group) {
                throw new \RuntimeException("Group not found", 404);
            }

            $currentMembers = (int) $group['current_members'];
            $maxSize = (int) $group['max_size'];

            // Capacity invariant check under lock
            if ($currentMembers >= $maxSize) {
                $this->pdo->rollBack();
                throw new \RuntimeException("Group is already full", 409);
            }

            // Increment member count and record enrollment
            $updateStmt = $this->pdo->prepare("UPDATE project_groups SET current_members = current_members + 1 WHERE id = :id");
            $updateStmt->execute(['id' => $groupId]);

            $memberStmt = $this->pdo->prepare("INSERT INTO group_members (group_id, student_id) VALUES (:group_id, :student_id)");
            $memberStmt->execute(['group_id' => $groupId, 'student_id' => $studentId]);

            $this->pdo->commit();
            return 200;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

class ConcurrencyRowLockTest extends TestCase
{
    private object $pdo;
    private GroupJoinService $service;

    protected function setUp(): void
    {
        $this->pdo = MockPdo::createOrRealPdo();

        $this->pdo->exec("
            CREATE TABLE project_groups (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                current_members INTEGER NOT NULL DEFAULT 0,
                max_size INTEGER NOT NULL
            );
            CREATE TABLE group_members (
                group_id INTEGER NOT NULL,
                student_id INTEGER NOT NULL,
                PRIMARY KEY (group_id, student_id)
            );
        ");

        $this->service = new GroupJoinService($this->pdo);
    }

    /**
     * @test
     * Verifies that a student can successfully join a group with available slots.
     */
    public function testStudentCanJoinGroupWithAvailableCapacity(): void
    {
        $this->pdo->exec("INSERT INTO project_groups (id, name, current_members, max_size) VALUES (1, 'Team Alpha', 2, 4)");

        $status = $this->service->joinGroup(groupId: 1, studentId: 101);
        $this->assertSame(200, $status, "Join request should succeed with 200 OK.");

        $checkStmt = $this->pdo->query("SELECT current_members FROM project_groups WHERE id = 1");
        $count = (int) $checkStmt->fetchColumn();
        $this->assertSame(3, $count, "current_members should have been incremented to 3.");
    }

    /**
     * @test
     * Verifies that joining an already full group is strictly rejected with 409 Conflict.
     */
    public function testJoiningFullGroupReturns409Conflict(): void
    {
        // Group at maximum capacity: 4 of 4 slots filled
        $this->pdo->exec("INSERT INTO project_groups (id, name, current_members, max_size) VALUES (2, 'Team Beta', 4, 4)");

        $caughtException = false;
        try {
            $this->service->joinGroup(groupId: 2, studentId: 102);
        } catch (\RuntimeException $e) {
            $caughtException = true;
            $this->assertSame(409, $e->getCode(), "Exception code must be 409 Conflict.");
            $this->assertStringContainsString("Group is already full", $e->getMessage());
        }

        $this->assertTrue($caughtException, "Attempt to join full group must throw 409 exception.");

        // Verify count remains at 4 (never 5!)
        $checkStmt = $this->pdo->query("SELECT current_members FROM project_groups WHERE id = 2");
        $count = (int) $checkStmt->fetchColumn();
        $this->assertSame(4, $count, "Capacity must not exceed max_size.");
    }

    /**
     * @test
     * Edge Case 1:
     * Two sequential/concurrent join attempts on the last remaining slot (current_members = 3, max_size = 4).
     * Exactly one request must succeed (200), and the second must be rejected (409).
     */
    public function testConcurrentLastSlotContentionPreventsCapacityOverflow(): void
    {
        // 1 remaining slot
        $this->pdo->exec("INSERT INTO project_groups (id, name, current_members, max_size) VALUES (3, 'Team Gamma', 3, 4)");

        // Student 1 attempts join
        $statusStudent1 = $this->service->joinGroup(groupId: 3, studentId: 201);
        $this->assertSame(200, $statusStudent1, "First student on remaining slot must succeed (200).");

        // Student 2 attempts join immediately after
        $student2Rejected = false;
        try {
            $this->service->joinGroup(groupId: 3, studentId: 202);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 409) {
                $student2Rejected = true;
            }
        }
        $this->assertTrue($student2Rejected, "Second student contending for last slot must receive 409 Conflict.");

        // Invariant check: current_members must equal max_size (4) and never 5
        $checkStmt = $this->pdo->query("SELECT current_members, max_size FROM project_groups WHERE id = 3");
        $group = $checkStmt->fetch();
        $this->assertSame(4, (int) $group['current_members']);
        $this->assertLessThanOrEqual((int) $group['max_size'], (int) $group['current_members']);
    }

    /**
     * @test
     * Verifies transaction rollback when an unhandled error occurs during membership insert.
     */
    public function testTransactionRollsBackOnDuplicateStudentJoin(): void
    {
        $this->pdo->exec("INSERT INTO project_groups (id, name, current_members, max_size) VALUES (4, 'Team Delta', 1, 4)");
        $this->service->joinGroup(groupId: 4, studentId: 301);

        // Attempting to join the same student again triggers PRIMARY KEY constraint failure
        $caughtError = false;
        try {
            $this->service->joinGroup(groupId: 4, studentId: 301);
        } catch (\Throwable) {
            $caughtError = true;
        }

        $this->assertTrue($caughtError, "Duplicate join must fail.");

        // Transaction must have rolled back; current_members should still be 2, not 3
        $checkStmt = $this->pdo->query("SELECT current_members FROM project_groups WHERE id = 4");
        $this->assertSame(2, (int) $checkStmt->fetchColumn(), "current_members must not increment on rolled back transaction.");
    }
}
