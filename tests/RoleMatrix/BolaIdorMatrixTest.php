<?php

declare(strict_types=1);

namespace Tests\RoleMatrix;

use Tests\Support\TestCase;
use Tests\Support\PolicyContract;
use Tests\Support\UserContext;
use Tests\Support\ResourceContext;

class BolaIdorMatrixTest extends TestCase
{
    private UserContext $studentA;
    private UserContext $studentB;
    private UserContext $lecturerA;
    private UserContext $lecturerB;

    protected function setUp(): void
    {
        $this->studentA = new UserContext(id: 101, role: 'student', enrolledCourseIds: [1]);
        $this->studentB = new UserContext(id: 102, role: 'student', enrolledCourseIds: [2]);
        $this->lecturerA = new UserContext(id: 201, role: 'lecturer', assignedCourseIds: [1]);
        $this->lecturerB = new UserContext(id: 202, role: 'lecturer', assignedCourseIds: [2]);
    }

    /**
     * @test
     * BOLA / IDOR Defense on Student Grades:
     * Student A attempting to query Student B's grades must receive 403 Forbidden.
     */
    public function testStudentCannotAccessAnotherStudentsGradebook(): void
    {
        $status = PolicyContract::evaluate(
            $this->studentA,
            new ResourceContext(
                route: '/courses/1/gradebook',
                method: 'GET',
                courseId: 1,
                ownerId: 102 // Belongs to Student B
            )
        );

        $this->assertSame(403, $status, "BOLA attempt on gradebook must be blocked with 403 Forbidden.");
    }

    /**
     * @test
     * BOLA Defense on Personal ICS Calendar Export:
     * Student A parameter tampering to export Student B's calendar must receive 403 Forbidden.
     */
    public function testStudentCannotExportAnotherUsersCalendar(): void
    {
        $status = PolicyContract::evaluate(
            $this->studentA,
            new ResourceContext(
                route: '/schedule/export.ics',
                method: 'GET',
                ownerId: 102 // Tampered ID
            )
        );

        $this->assertSame(403, $status, "BOLA attempt on personal ICS export must be rejected with 403.");
    }

    /**
     * @test
     * BOLA Defense on User Profile & Active Sessions:
     * Cross-user inspection of profiles or session tokens must be rejected with 403.
     */
    public function testUserCannotAccessAnotherUsersProfileOrSessions(): void
    {
        $endpoints = ['/profile', '/security/sessions', '/profile/export-data'];

        foreach ($endpoints as $route) {
            $status = PolicyContract::evaluate(
                $this->studentA,
                new ResourceContext(route: $route, method: 'GET', ownerId: 102)
            );
            $this->assertSame(
                403,
                $status,
                sprintf("BOLA attempt on [%s] with another user's ID must return 403.", $route)
            );
        }
    }

    /**
     * @test
     * Forum Ownership & 15-Minute Expiration Window:
     * - Editing another student's post -> 403
     * - Editing own post after 15 minutes (> 900s) -> 403
     * - Editing own post within 15 minutes (<= 900s) -> 200
     */
    public function testForumPostEditOwnershipAndTimeWindow(): void
    {
        // 1. Attempting to edit another student's post
        $statusOther = PolicyContract::evaluate(
            $this->studentA,
            new ResourceContext(
                route: '/courses/1/forum/posts/42',
                method: 'PUT',
                courseId: 1,
                ownerId: 102, // Student B
                postAgeSeconds: 300
            )
        );
        $this->assertSame(403, $statusOther, "Editing another user's forum post must return 403.");

        // 2. Attempting to edit own post after 15 minutes (901 seconds)
        $statusExpired = PolicyContract::evaluate(
            $this->studentA,
            new ResourceContext(
                route: '/courses/1/forum/posts/42',
                method: 'PUT',
                courseId: 1,
                ownerId: 101, // Student A (self)
                postAgeSeconds: 901 // Expired window
            )
        );
        $this->assertSame(403, $statusExpired, "Editing own post after 15 minutes must return 403 Forbidden.");

        // 3. Editing own post within 15 minutes (600 seconds)
        $statusValid = PolicyContract::evaluate(
            $this->studentA,
            new ResourceContext(
                route: '/courses/1/forum/posts/42',
                method: 'PUT',
                courseId: 1,
                ownerId: 101, // Student A (self)
                postAgeSeconds: 600 // Valid window
            )
        );
        $this->assertSame(200, $statusValid, "Editing own post within 15 minutes must return 200 OK.");
    }

    /**
     * @test
     * Course Material & File Download BOLA Protection:
     * Students and lecturers must not download course materials for courses they are not part of.
     */
    public function testFileDownloadAccessEnforcesCourseAuthorization(): void
    {
        // Student A downloads file from enrolled course 1 -> 200
        $statusStudentEnrolled = PolicyContract::evaluate(
            $this->studentA,
            new ResourceContext(route: '/files/file-uuid-1', method: 'GET', courseId: 1)
        );
        $this->assertSame(200, $statusStudentEnrolled, "Student should be able to download file from enrolled course.");

        // Student A attempts to download file from unenrolled course 2 -> 403
        $statusStudentUnenrolled = PolicyContract::evaluate(
            $this->studentA,
            new ResourceContext(route: '/files/file-uuid-2', method: 'GET', courseId: 2)
        );
        $this->assertSame(403, $statusStudentUnenrolled, "Student must receive 403 for file in unenrolled course.");

        // Lecturer A attempts to download file from unassigned course 2 -> 403
        $statusLecturerUnassigned = PolicyContract::evaluate(
            $this->lecturerA,
            new ResourceContext(route: '/files/file-uuid-2', method: 'GET', courseId: 2)
        );
        $this->assertSame(403, $statusLecturerUnassigned, "Lecturer must receive 403 for file in unassigned course.");
    }
}
