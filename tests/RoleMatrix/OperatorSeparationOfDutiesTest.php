<?php

declare(strict_types=1);

namespace Tests\RoleMatrix;

use Tests\Support\TestCase;
use Tests\Support\PolicyContract;
use Tests\Support\UserContext;
use Tests\Support\ResourceContext;

class OperatorSeparationOfDutiesTest extends TestCase
{
    private UserContext $operator;

    protected function setUp(): void
    {
        $this->operator = new UserContext(
            id: 301,
            role: 'operator',
            enrolledCourseIds: [],
            assignedCourseIds: []
        );
    }

    /**
     * @test
     * Verifies that the Student Service Operator has access to system administrative views.
     */
    public function testOperatorCanAccessAdministrativePortals(): void
    {
        $allowedOperatorRoutes = [
            ['/dashboard', 'GET'],
            ['/operator/users', 'GET'],
            ['/operator/users', 'POST'],
            ['/operator/users/101/force-logout', 'POST'],
            ['/operator/enrollments/import', 'POST'],
            ['/operator/tickets', 'GET'],
            ['/operator/tickets', 'POST'],
            ['/operator/audit-logs', 'GET'],
            ['/schedule/manage', 'POST'],
            ['/courses', 'GET'],
            ['/courses/1', 'GET'],
            ['/courses/1/people', 'GET'],
        ];

        foreach ($allowedOperatorRoutes as [$route, $method]) {
            $status = PolicyContract::evaluate(
                $this->operator,
                new ResourceContext(route: $route, method: $method, courseId: 1)
            );
            $this->assertSame(
                200,
                $status,
                sprintf("Operator must have access (200) to administrative route [%s %s].", $method, $route)
            );
        }
    }

    /**
     * @test
     * ASVS V4 & Separation of Duties:
     * Verifies that the Operator is STRICTLY FORBIDDEN (403) from accessing or editing student grades.
     */
    public function testOperatorIsStrictlyForbiddenFromGradebookAndGrading(): void
    {
        $gradingEndpoints = [
            ['/courses/1/gradebook', 'GET'],
            ['/courses/1/gradebook/update', 'POST'],
            ['/courses/1/gradebook/import', 'POST'],
            ['/courses/1/gradebook/publish', 'POST'],
            ['/courses/1/gradebook/export', 'GET'],
        ];

        foreach ($gradingEndpoints as [$route, $method]) {
            $status = PolicyContract::evaluate(
                $this->operator,
                new ResourceContext(route: $route, method: $method, courseId: 1)
            );
            $this->assertSame(
                403,
                $status,
                sprintf(
                    "Separation of Duties VIOLATION: Operator must receive 403 Forbidden on [%s %s].",
                    $method,
                    $route
                )
            );
        }
    }

    /**
     * @test
     * Separation of Duties:
     * Verifies that the Operator is STRICTLY FORBIDDEN (403) from accessing private lecturer notes.
     */
    public function testOperatorIsStrictlyForbiddenFromPrivateLecturerNotes(): void
    {
        $lecturerNotesEndpoints = [
            ['/courses/1/lecturer-notes', 'GET'],
            ['/courses/1/lecturer-notes', 'POST'],
        ];

        foreach ($lecturerNotesEndpoints as [$route, $method]) {
            $status = PolicyContract::evaluate(
                $this->operator,
                new ResourceContext(route: $route, method: $method, courseId: 1)
            );
            $this->assertSame(
                403,
                $status,
                sprintf(
                    "Separation of Duties VIOLATION: Operator must receive 403 Forbidden on private notes [%s %s].",
                    $method,
                    $route
                )
            );
        }
    }

    /**
     * @test
     * Audit Log Immutability:
     * Verifies that neither Operator nor any user can modify or delete audit log records.
     */
    public function testOperatorCannotModifyOrDeleteAuditLogs(): void
    {
        $statusDelete = PolicyContract::evaluate(
            $this->operator,
            new ResourceContext(route: '/audit-logs/1', method: 'DELETE')
        );
        $this->assertSame(
            403,
            $statusDelete,
            "Audit log deletion must be rejected with 403 Forbidden for all roles including Operator."
        );

        $statusPut = PolicyContract::evaluate(
            $this->operator,
            new ResourceContext(route: '/audit-logs/1', method: 'PUT')
        );
        $this->assertSame(
            403,
            $statusPut,
            "Audit log update must be rejected with 403 Forbidden for all roles including Operator."
        );
    }

    /**
     * @test
     * Verifies that an Operator cannot post in course forums or participate in student groups.
     */
    public function testOperatorCannotPerformAcademicStudentActions(): void
    {
        $academicActions = [
            ['/courses/1/forum/threads', 'POST'],
            ['/courses/1/forum/posts', 'POST'],
            ['/courses/1/grouping/join', 'POST'],
            ['/courses/1/grouping/leave', 'POST'],
            ['/courses/1/attendance/mark', 'POST'],
        ];

        foreach ($academicActions as [$route, $method]) {
            $status = PolicyContract::evaluate(
                $this->operator,
                new ResourceContext(route: $route, method: $method, courseId: 1)
            );
            $this->assertSame(
                403,
                $status,
                sprintf("Operator must receive 403 when attempting academic action [%s %s].", $method, $route)
            );
        }
    }
}
