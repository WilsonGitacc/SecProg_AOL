<?php

declare(strict_types=1);

namespace Tests\RoleMatrix;

use Tests\Support\TestCase;
use Tests\Support\PolicyContract;
use Tests\Support\UserContext;
use Tests\Support\ResourceContext;

class LecturerRoleMatrixTest extends TestCase
{
    private UserContext $assignedLecturer;
    private int $assignedCourseId = 1;
    private int $unassignedCourseId = 2;

    protected function setUp(): void
    {
        $this->assignedLecturer = new UserContext(
            id: 201,
            role: 'lecturer',
            enrolledCourseIds: [],
            assignedCourseIds: [$this->assignedCourseId]
        );
    }

    /**
     * @test
     * Verifies that an assigned lecturer has full academic management rights on their assigned course.
     */
    public function testAssignedLecturerHasAccessToAssignedCourseManagement(): void
    {
        $allowedManagementEndpoints = [
            ['/courses/1', 'GET'],
            ['/courses/1/forum', 'GET'],
            ['/courses/1/gradebook', 'GET'],
            ['/courses/1/gradebook/update', 'POST'],
            ['/courses/1/gradebook/import', 'POST'],
            ['/courses/1/gradebook/publish', 'POST'],
            ['/courses/1/gradebook/export', 'GET'],
            ['/courses/1/people', 'GET'],
            ['/courses/1/grouping', 'GET'],
            ['/courses/1/grouping/manage', 'POST'],
            ['/courses/1/attendance', 'GET'],
            ['/courses/1/attendance/mark', 'POST'],
            ['/courses/1/attendance/edit', 'POST'],
            ['/courses/1/analytics', 'GET'],
            ['/courses/1/lecturer-notes', 'GET'],
            ['/courses/1/lecturer-notes', 'POST'],
            ['/courses/1/forum/moderate', 'POST'],
        ];

        foreach ($allowedManagementEndpoints as [$route, $method]) {
            $status = PolicyContract::evaluate(
                $this->assignedLecturer,
                new ResourceContext(route: $route, method: $method, courseId: $this->assignedCourseId)
            );
            $this->assertSame(
                200,
                $status,
                sprintf("Assigned lecturer must have access (200) to [%s %s].", $method, $route)
            );
        }
    }

    /**
     * @test
     * Verifies that a lecturer is strictly forbidden (403) from accessing unassigned courses (BOLA/IDOR prevention).
     */
    public function testLecturerCannotAccessUnassignedCourseWorkspaces(): void
    {
        $unassignedEndpoints = [
            ['/courses/2', 'GET'],
            ['/courses/2/forum', 'GET'],
            ['/courses/2/gradebook', 'GET'],
            ['/courses/2/gradebook/update', 'POST'],
            ['/courses/2/gradebook/import', 'POST'],
            ['/courses/2/attendance', 'GET'],
            ['/courses/2/attendance/mark', 'POST'],
            ['/courses/2/lecturer-notes', 'GET'],
            ['/courses/2/analytics', 'GET'],
        ];

        foreach ($unassignedEndpoints as [$route, $method]) {
            $status = PolicyContract::evaluate(
                $this->assignedLecturer,
                new ResourceContext(route: $route, method: $method, courseId: $this->unassignedCourseId)
            );
            $this->assertSame(
                403,
                $status,
                sprintf("Lecturer must receive 403 Forbidden when attempting to access unassigned course [%s %s].", $method, $route)
            );
        }
    }

    /**
     * @test
     * Verifies that a lecturer is forbidden from student-only actions.
     */
    public function testLecturerIsForbiddenFromStudentSpecificActions(): void
    {
        $studentActions = [
            ['/courses/1/grouping/join', 'POST'],
            ['/courses/1/grouping/leave', 'POST'],
            ['/courses/1/attendance/excuse', 'POST'],
            ['/courses/1/forum/posts/5/report', 'POST'],
        ];

        foreach ($studentActions as [$route, $method]) {
            $status = PolicyContract::evaluate(
                $this->assignedLecturer,
                new ResourceContext(route: $route, method: $method, courseId: $this->assignedCourseId)
            );
            $this->assertSame(
                403,
                $status,
                sprintf("Lecturer must receive 403 when attempting student action [%s %s].", $method, $route)
            );
        }
    }

    /**
     * @test
     * Verifies that a lecturer is strictly blocked from Operator administration routes.
     */
    public function testLecturerIsForbiddenFromOperatorRoutes(): void
    {
        $operatorRoutes = [
            '/operator/users',
            '/operator/tickets',
            '/operator/audit-logs',
            '/schedule/manage',
        ];

        foreach ($operatorRoutes as $route) {
            $status = PolicyContract::evaluate(
                $this->assignedLecturer,
                new ResourceContext(route: $route, method: 'GET')
            );
            $this->assertSame(
                403,
                $status,
                sprintf("Lecturer must receive 403 when attempting operator route [%s].", $route)
            );
        }
    }
}
