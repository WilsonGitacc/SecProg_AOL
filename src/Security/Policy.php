<?php

declare(strict_types=1);

namespace App\Security;

use App\Models\User;

/**
 * Central Deny-by-Default RBAC + Object-Level Authorization (BOLA/IDOR) Policy Gate.
 */
final class Policy
{
    /**
     * Evaluates route/action authorization for a given User and optional resource context.
     * Returns HTTP status code: 200 (Allowed), 401 (Unauthenticated), 403 (Forbidden).
     */
    public static function evaluateRoute(
        User $user,
        string $route,
        string $method = 'GET',
        ?int $resourceOwnerId = null,
        ?int $courseId = null,
        ?int $postAgeSeconds = null
    ): int {
        $method = strtoupper($method);

        // 1. Audit log immutability: modification or deletion is strictly forbidden for ALL roles
        if (str_starts_with($route, '/audit-logs') || str_starts_with($route, '/operator/audit-logs')) {
            if (in_array($method, ['PUT', 'DELETE', 'PATCH', 'POST'], true)) {
                return 403;
            }
        }

        // 2. Public routes
        if ($route === '/login' || $route === '/healthz' || $route === '/healthz.php') {
            return 200;
        }

        // 3. Anonymous users rejected on all protected routes
        if ($user->isAnonymous()) {
            return 401;
        }

        // 4. Logout & Notifications
        if ($route === '/logout' || str_starts_with($route, '/notifications') || $route === '/search') {
            return 200;
        }

        // 5. Profile & Security Settings (BOLA check on ownerId)
        if (in_array($route, ['/profile', '/security/password', '/security/2fa/setup', '/security/sessions', '/profile/export-data', '/export-data'], true)) {
            if ($resourceOwnerId !== null && $resourceOwnerId !== $user->id) {
                return 403;
            }
            return 200;
        }

        // 6. Schedule Routes (Students & Lecturers only — no schedule for Operator)
        if ($route === '/schedule') {
            return $user->role !== 'operator' ? 200 : 403;
        }
        if ($route === '/schedule/export.ics') {
            if ($user->role === 'operator') {
                return 403;
            }
            if ($resourceOwnerId !== null && $resourceOwnerId !== $user->id) {
                return 403;
            }
            return 200;
        }
        if ($route === '/schedule/request-change') {
            return $user->role === 'lecturer' ? 200 : 403;
        }

        // 7. Operator Administration Routes
        if (str_starts_with($route, '/operator')) {
            return $user->role === 'operator' ? 200 : 403;
        }

        // 8. Courses Catalog
        if ($route === '/courses') {
            return 200;
        }

        // 9. Course Workspace & Sub-routes (/courses/{id}...)
        if (preg_match('#^/courses/(\d+)(.*)$#', $route, $matches)) {
            $targetCourseId = (int) $matches[1];
            $subPath = $matches[2];

            // Separation of Duties: Operator is strictly blocked from gradebook mutations/views and private lecturer notes
            if ($user->role === 'operator') {
                if (str_starts_with($subPath, '/gradebook') || str_starts_with($subPath, '/lecturer-notes')) {
                    return 403;
                }
                if (in_array($subPath, [
                    '/attendance/mark', '/attendance/edit', '/attendance/excuse',
                    '/grouping/join', '/grouping/leave', '/grouping/manage', '/grouping/create',
                    '/forum/threads', '/forum/posts', '/forum/moderate',
                    '/assignments/create', '/assignments/grade', '/assignments/submit',
                ], true) || str_ends_with($subPath, '/report')) {
                    return 403;
                }
                return 200;
            }

            // Student RBAC + BOLA/IDOR enrollment check
            if ($user->role === 'student') {
                if (!in_array($targetCourseId, $user->enrolledCourseIds, true)) {
                    return 403;
                }
                if (
                    in_array($subPath, [
                        '/gradebook/update', '/gradebook/import', '/gradebook/publish', '/gradebook/export',
                        '/grouping/manage', '/grouping/create', '/attendance/mark', '/attendance/edit', '/attendance/window',
                        '/analytics', '/forum/moderate', '/session/edit', '/assignments/create', '/assignments/grade',
                    ], true) ||
                    str_starts_with($subPath, '/lecturer-notes')
                ) {
                    return 403;
                }
                if ($subPath === '/gradebook' && $resourceOwnerId !== null && $resourceOwnerId !== $user->id) {
                    return 403;
                }
                if (preg_match('#^/forum/posts/\d+(/edit|/delete)?$#', $subPath) && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
                    if ($resourceOwnerId !== null && $resourceOwnerId !== $user->id) {
                        return 403;
                    }
                    if ($postAgeSeconds !== null && $postAgeSeconds > 900) {
                        return 403;
                    }
                }
                return 200;
            }

            // Lecturer RBAC + BOLA/IDOR assigned-class check
            if ($user->role === 'lecturer') {
                if (!in_array($targetCourseId, $user->assignedCourseIds, true)) {
                    return 403;
                }
                if (
                    in_array($subPath, ['/grouping/join', '/grouping/leave', '/attendance/excuse', '/assignments/submit'], true) ||
                    str_ends_with($subPath, '/report')
                ) {
                    return 403;
                }
                return 200;
            }
        }

        // 10. File Downloads (/files/{uuid})
        if (str_starts_with($route, '/files/')) {
            if ($user->role === 'operator') {
                return 200;
            }
            if ($courseId !== null) {
                if ($user->role === 'student' && !in_array($courseId, $user->enrolledCourseIds, true)) {
                    return 403;
                }
                if ($user->role === 'lecturer' && !in_array($courseId, $user->assignedCourseIds, true)) {
                    return 403;
                }
            }
            return 200;
        }

        // 11. Dashboard
        if ($route === '/dashboard' || $route === '/') {
            return 200;
        }

        return 403;
    }

    public static function authorize(User $user, string $route, string $method = 'GET', ?int $ownerId = null, ?int $courseId = null, ?int $postAge = null): bool
    {
        return self::evaluateRoute($user, $route, $method, $ownerId, $courseId, $postAge) === 200;
    }
}
