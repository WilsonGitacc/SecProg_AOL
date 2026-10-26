<?php

declare(strict_types=1);

namespace Tests\RoleMatrix;

use Tests\Support\TestCase;
use Tests\Support\PolicyContract;
use Tests\Support\UserContext;
use Tests\Support\ResourceContext;

class AnonymousAccessTest extends TestCase
{
    private UserContext $anonymous;

    protected function setUp(): void
    {
        $this->anonymous = new UserContext(id: null, role: 'anonymous');
    }

    /**
     * @test
     * Verifies that the login page is publicly accessible without authentication.
     */
    public function testLoginPageIsPubliclyAccessible(): void
    {
        $status = PolicyContract::evaluate(
            $this->anonymous,
            new ResourceContext(route: '/login', method: 'GET')
        );
        $this->assertSame(200, $status, "Login GET must be publicly accessible (200).");

        $statusPost = PolicyContract::evaluate(
            $this->anonymous,
            new ResourceContext(route: '/login', method: 'POST')
        );
        $this->assertSame(200, $statusPost, "Login POST must be publicly accessible (200).");
    }

    /**
     * @test
     * Verifies that anonymous access to core application views returns 401 Unauthorized.
     */
    public function testProtectedCoreViewsReturn401ForAnonymous(): void
    {
        $protectedRoutes = [
            '/dashboard',
            '/courses',
            '/schedule',
            '/profile',
            '/security/sessions',
            '/profile/export-data',
        ];

        foreach ($protectedRoutes as $route) {
            $status = PolicyContract::evaluate(
                $this->anonymous,
                new ResourceContext(route: $route, method: 'GET')
            );
            $this->assertSame(
                401,
                $status,
                sprintf("Protected route [%s] must return 401 for anonymous user.", $route)
            );
        }
    }

    /**
     * @test
     * Verifies that anonymous access to course tabs returns 401 Unauthorized.
     */
    public function testCourseTabsReturn401ForAnonymous(): void
    {
        $courseRoutes = [
            '/courses/1',
            '/courses/1/forum',
            '/courses/1/gradebook',
            '/courses/1/people',
            '/courses/1/grouping',
            '/courses/1/attendance',
            '/courses/1/analytics',
            '/courses/1/lecturer-notes',
        ];

        foreach ($courseRoutes as $route) {
            $status = PolicyContract::evaluate(
                $this->anonymous,
                new ResourceContext(route: $route, method: 'GET', courseId: 1)
            );
            $this->assertSame(
                401,
                $status,
                sprintf("Course sub-tab [%s] must return 401 for anonymous user.", $route)
            );
        }
    }

    /**
     * @test
     * Verifies that state-changing actions return 401 for unauthenticated users.
     */
    public function testStateChangingActionsReturn401ForAnonymous(): void
    {
        $actions = [
            ['/logout', 'POST'],
            ['/courses/1/forum/threads', 'POST'],
            ['/courses/1/gradebook/update', 'POST'],
            ['/courses/1/attendance/mark', 'POST'],
            ['/courses/1/grouping/join', 'POST'],
            ['/schedule/manage', 'POST'],
            ['/operator/users', 'POST'],
        ];

        foreach ($actions as [$route, $method]) {
            $status = PolicyContract::evaluate(
                $this->anonymous,
                new ResourceContext(route: $route, method: $method, courseId: 1)
            );
            $this->assertSame(
                401,
                $status,
                sprintf("Action [%s %s] must return 401 for anonymous user.", $method, $route)
            );
        }
    }

    /**
     * @test
     * Verifies that file downloads return 401 for anonymous users.
     */
    public function testFileDownloadsReturn401ForAnonymous(): void
    {
        $status = PolicyContract::evaluate(
            $this->anonymous,
            new ResourceContext(route: '/files/d9b2d67d-9bf9-478e-908d-8a562ef2a7d4', method: 'GET', courseId: 1)
        );
        $this->assertSame(401, $status, "File download must return 401 for anonymous user.");
    }
}
