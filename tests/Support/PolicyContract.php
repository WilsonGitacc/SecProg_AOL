<?php

declare(strict_types=1);

namespace Tests\Support;

require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ResourceContext.php';

/**
 * Authoritative Policy Specification Oracle derived strictly from:
 * - ORIGINAL_REQUEST.md §R4, §AC
 * - PROJECT.md § Security & Authorization Model, § Interface Contracts
 * - spec_report.md § 9. Role Capabilities & BOLA/IDOR Authorization Matrix
 */
class PolicyContract
{
    /**
     * Evaluates access request and returns expected HTTP Status Code:
     * 200/201 (Allow), 401 (Unauthorized), 403 (Forbidden), 405 (Method Not Allowed).
     */
    public static function evaluate(UserContext $user, ResourceContext $resource): int
    {
        $route = $resource->route;
        $method = strtoupper($resource->method);

        // 1. Audit log immutability: modification or deletion is strictly forbidden for ALL roles
        if (str_starts_with($route, '/audit-logs')) {
            if (in_array($method, ['PUT', 'DELETE', 'PATCH'], true)) {
                return 403; // Immutable
            }
        }

        // 2. Public / Unauthenticated routes
        if ($route === '/login') {
            return 200;
        }

        // 3. Anonymous checks: All protected endpoints reject with 401
        if ($user->isAnonymous()) {
            return 401;
        }

        // 4. Logout: accessible by any authenticated user
        if ($route === '/logout') {
            return 200;
        }

        // 5. User Profile & Security Settings (Avatar Popover hub)
        if (in_array($route, ['/profile', '/security/password', '/security/2fa/setup', '/security/sessions', '/profile/export-data'], true)) {
            if ($resource->ownerId !== null && $resource->ownerId !== $user->id) {
                return 403; // BOLA: cannot inspect or mutate another user's profile/sessions
            }
            return 200;
        }

        // 6. Schedule Routes
        if ($route === '/schedule') {
            return 200;
        }
        if ($route === '/schedule/export.ics') {
            if ($resource->ownerId !== null && $resource->ownerId !== $user->id) {
                return 403; // BOLA: cannot export other user's personal ICS
            }
            return 200;
        }
        if ($route === '/schedule/manage') {
            return $user->role === 'operator' ? 200 : 403;
        }

        // 7. Operator Administration Routes
        if (str_starts_with($route, '/operator/')) {
            return $user->role === 'operator' ? 200 : 403;
        }

        // 8. Courses Directory
        if ($route === '/courses') {
            return 200;
        }

        // 9. Course Workspace & Tabs (/courses/{id}/...)
        if (preg_match('#^/courses/(\d+)(.*)$#', $route, $matches)) {
            $courseId = (int) $matches[1];
            $subPath = $matches[2];

            // Separation of Duties: Operator is strictly blocked from gradebook and private lecturer notes
            if ($user->role === 'operator') {
                if (str_starts_with($subPath, '/gradebook') || str_starts_with($subPath, '/lecturer-notes')) {
                    return 403; // Strict Separation of Duties
                }
                if ($subPath === '/attendance/mark' || $subPath === '/attendance/edit' || $subPath === '/attendance/excuse') {
                    return 403;
                }
                if ($subPath === '/grouping/join' || $subPath === '/grouping/leave' || $subPath === '/grouping/manage') {
                    return 403;
                }
                if ($subPath === '/forum/threads' || $subPath === '/forum/posts' || str_ends_with($subPath, '/report') || $subPath === '/forum/moderate') {
                    return 403;
                }
                // Operator can view courses catalog, session overview, people, read-only forum
                return 200;
            }

            // Student authorization rules
            if ($user->role === 'student') {
                if (!in_array($courseId, $user->enrolledCourseIds, true)) {
                    return 403; // Unenrolled student blocked (BOLA / IDOR)
                }

                // Forbidden for students:
                if (
                    $subPath === '/gradebook/update' ||
                    $subPath === '/gradebook/import' ||
                    $subPath === '/gradebook/publish' ||
                    $subPath === '/gradebook/export' ||
                    $subPath === '/grouping/manage' ||
                    $subPath === '/attendance/mark' ||
                    $subPath === '/attendance/edit' ||
                    $subPath === '/analytics' ||
                    str_starts_with($subPath, '/lecturer-notes') ||
                    $subPath === '/forum/moderate'
                ) {
                    return 403;
                }

                // Gradebook BOLA: Student can only view own grades
                if ($subPath === '/gradebook') {
                    if ($resource->ownerId !== null && $resource->ownerId !== $user->id) {
                        return 403; // BOLA: cannot view other student's grades
                    }
                    return 200;
                }

                // Forum Post Edit/Delete 15-minute window rule
                if (preg_match('#^/forum/posts/\d+$#', $subPath) && in_array($method, ['PUT', 'DELETE'], true)) {
                    if ($resource->ownerId !== null && $resource->ownerId !== $user->id) {
                        return 403; // Not author
                    }
                    if ($resource->postAgeSeconds !== null && $resource->postAgeSeconds > 900) {
                        return 403; // Expired 15m window
                    }
                    return 200;
                }

                return 200;
            }

            // Lecturer authorization rules
            if ($user->role === 'lecturer') {
                if (!in_array($courseId, $user->assignedCourseIds, true)) {
                    return 403; // Unassigned lecturer blocked (BOLA / IDOR)
                }

                // Forbidden for lecturers:
                if (
                    $subPath === '/grouping/join' ||
                    $subPath === '/grouping/leave' ||
                    $subPath === '/attendance/excuse' ||
                    str_ends_with($subPath, '/report')
                ) {
                    return 403;
                }

                return 200;
            }
        }

        // 10. File downloads (/files/{uuid})
        if (str_starts_with($route, '/files/')) {
            if ($user->role === 'operator') {
                return 200;
            }
            if ($resource->courseId !== null) {
                if ($user->role === 'student' && !in_array($resource->courseId, $user->enrolledCourseIds, true)) {
                    return 403;
                }
                if ($user->role === 'lecturer' && !in_array($resource->courseId, $user->assignedCourseIds, true)) {
                    return 403;
                }
            }
            return 200;
        }

        // 11. Dashboard
        if ($route === '/dashboard') {
            return 200;
        }

        // Deny by default
        return 403;
    }
}
