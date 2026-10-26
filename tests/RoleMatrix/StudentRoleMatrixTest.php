<?php

declare(strict_types=1);

namespace Tests\RoleMatrix;

use Tests\Support\TestCase;
use Tests\Support\PolicyContract;
use Tests\Support\UserContext;
use Tests\Support\ResourceContext;

class StudentRoleMatrixTest extends TestCase
{
    private UserContext $enrolledStudent;
    private int $enrolledCourseId = 1;
    private int $unenrolledCourseId = 99;

    protected function setUp(): void
    {
        $this->enrolledStudent = new UserContext(
            id: 101,
            role: 'student',
            enrolledCourseIds: [$this->enrolledCourseId],
            assignedCourseIds: []
        );
    }

    /**
     * @test
     * Verifies that an enrolled student can access authorized tabs of their enrolled course.
     */
    public function testEnrolledStudentCanAccessEnrolledCourseTabs(): void
    {
        $allowedTabs = [
            '/courses/1',
            '/courses/1/forum',
            '/courses/1/people',
            '/courses/1/grouping',
            '/courses/1/attendance',
        ];

        foreach ($allowedTabs as $tab) {
            $status = PolicyContract::evaluate(
                $this->enrolledStudent,
                new ResourceContext(route: $tab, method: 'GET', courseId: $this->enrolledCourseId)
            );
            $this->assertSame(
                200,
                $status,
                sprintf("Enrolled student should have access to [%s] (expected 200).", $tab)
            );
        }
    }

    /**
     * @test
     * Verifies that a student can only view their own gradebook, and BOLA is blocked on other students.
     */
    public function testStudentGradebookAccessIsScopedToOwnRecord(): void
    {
        // Own gradebook
        $ownStatus = PolicyContract::evaluate(
            $this->enrolledStudent,
            new ResourceContext(
                route: '/courses/1/gradebook',
                method: 'GET',
                courseId: $this->enrolledCourseId,
                ownerId: 101
            )
        );
        $this->assertSame(200, $ownStatus, "Student must be allowed to view own gradebook.");

        // Other student's gradebook (BOLA attempt)
        $otherStatus = PolicyContract::evaluate(
            $this->enrolledStudent,
            new ResourceContext(
                route: '/courses/1/gradebook',
                method: 'GET',
                courseId: $this->enrolledCourseId,
                ownerId: 202
            )
        );
        $this->assertSame(403, $otherStatus, "Student viewing another student's grades must receive 403 Forbidden.");
    }

    /**
     * @test
     * Verifies that an enrolled student CANNOT access un-enrolled courses (IDOR prevention).
     */
    public function testStudentCannotAccessUnenrolledCourseTabs(): void
    {
        $unenrolledTabs = [
            '/courses/99',
            '/courses/99/forum',
            '/courses/99/gradebook',
            '/courses/99/people',
            '/courses/99/grouping',
            '/courses/99/attendance',
        ];

        foreach ($unenrolledTabs as $tab) {
            $status = PolicyContract::evaluate(
                $this->enrolledStudent,
                new ResourceContext(route: $tab, method: 'GET', courseId: $this->unenrolledCourseId)
            );
            $this->assertSame(
                403,
                $status,
                sprintf("Student must receive 403 Forbidden when accessing unenrolled course tab [%s].", $tab)
            );
        }
    }

    /**
     * @test
     * Verifies that students are strictly blocked (403) from instructor grade management actions.
     */
    public function testStudentIsForbiddenFromInstructorGradeActions(): void
    {
        $instructorActions = [
            ['/courses/1/gradebook/update', 'POST'],
            ['/courses/1/gradebook/import', 'POST'],
            ['/courses/1/gradebook/publish', 'POST'],
            ['/courses/1/gradebook/export', 'GET'],
        ];

        foreach ($instructorActions as [$route, $method]) {
            $status = PolicyContract::evaluate(
                $this->enrolledStudent,
                new ResourceContext(route: $route, method: $method, courseId: $this->enrolledCourseId)
            );
            $this->assertSame(
                403,
                $status,
                sprintf("Student must receive 403 when attempting instructor action [%s %s].", $method, $route)
            );
        }
    }

    /**
     * @test
     * Verifies that students are strictly blocked from instructor attendance manipulation and private notes.
     */
    public function testStudentIsForbiddenFromInstructorAttendanceAndPrivateNotes(): void
    {
        $forbiddenEndpoints = [
            ['/courses/1/attendance/mark', 'POST'],
            ['/courses/1/attendance/edit', 'POST'],
            ['/courses/1/grouping/manage', 'POST'],
            ['/courses/1/analytics', 'GET'],
            ['/courses/1/lecturer-notes', 'GET'],
            ['/courses/1/lecturer-notes', 'POST'],
            ['/courses/1/forum/moderate', 'POST'],
        ];

        foreach ($forbiddenEndpoints as [$route, $method]) {
            $status = PolicyContract::evaluate(
                $this->enrolledStudent,
                new ResourceContext(route: $route, method: $method, courseId: $this->enrolledCourseId)
            );
            $this->assertSame(
                403,
                $status,
                sprintf("Student must receive 403 when accessing [%s %s].", $method, $route)
            );
        }
    }

    /**
     * @test
     * Verifies that students are strictly blocked from Operator administration routes.
     */
    public function testStudentIsForbiddenFromOperatorRoutes(): void
    {
        $operatorRoutes = [
            '/operator/users',
            '/operator/users/101/force-logout',
            '/operator/enrollments/import',
            '/operator/tickets',
            '/operator/audit-logs',
            '/schedule/manage',
        ];

        foreach ($operatorRoutes as $route) {
            $status = PolicyContract::evaluate(
                $this->enrolledStudent,
                new ResourceContext(route: $route, method: 'GET')
            );
            $this->assertSame(
                403,
                $status,
                sprintf("Student must receive 403 when accessing operator route [%s].", $route)
            );
        }
    }
}
