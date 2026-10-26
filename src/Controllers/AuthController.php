<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Security\Csrf;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\TotpService;
use PDO;

final class AuthController
{
    public static function showLogin(Request $request): Response
    {
        $user = AuthService::currentUser();
        if (!$user->isAnonymous()) {
            return Response::redirect('/dashboard');
        }
        return View::render('auth/login', [
            'pageTitle' => 'Sign In',
            'activeNav' => 'login',
        ]);
    }

    public static function login(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }

        $identifier = trim((string) ($request->post['email'] ?? $request->post['username'] ?? ''));
        $password = (string) ($request->post['password'] ?? '');

        $result = AuthService::authenticate($identifier, $password);
        if (!$result['success']) {
            return View::render('auth/login', [
                'pageTitle' => 'Sign In',
                'error' => $result['error'] ?? 'Authentication failed.',
                'submittedEmail' => $identifier,
            ], 401);
        }

        return Response::redirect('/dashboard');
    }

    public static function logout(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        if (!$user->isAnonymous()) {
            AuditService::log($user->id, 'auth.logout', 'users', (string) $user->id);
        }
        Session::destroy();
        return Response::redirect('/login');
    }

    public static function profile(Request $request): Response
    {
        $user = AuthService::currentUser();

        return View::render('auth/profile', [
            'pageTitle' => 'My Profile & Change Password',
            'activeNav' => 'profile',
            'currentUser' => $user,
        ]);
    }

    public static function notifications(Request $request): Response
    {
        $user = AuthService::currentUser();
        $pdo = Database::getConnection();

        // Strictly fetch ONLY Lecturer-created Forum Threads and Lecturer-created Assignments
        $notifStmt = $pdo->query("
            SELECT 'thread' AS notif_type, ft.id AS item_id, ft.title, ft.created_at,
                   u.full_name AS lecturer_name, u.academic_title,
                   c.id AS course_id, c.code AS course_code, c.name AS course_name,
                   cls.class_code, NULL AS due_date, COALESCE(cs.session_number, 1) AS session_number
            FROM forum_threads ft
            JOIN users u ON u.id = ft.author_id
            JOIN classes cls ON cls.id = ft.class_id
            JOIN courses c ON c.id = cls.course_id
            LEFT JOIN course_sessions cs ON cs.id = ft.session_id
            WHERE ft.deleted_at IS NULL AND u.role = 'lecturer'
            UNION ALL
            SELECT 'assignment' AS notif_type, a.id AS item_id, a.title, a.created_at,
                   u.full_name AS lecturer_name, u.academic_title,
                   c.id AS course_id, c.code AS course_code, c.name AS course_name,
                   cls.class_code, a.due_date, NULL AS session_number
            FROM assignments a
            JOIN users u ON u.id = a.created_by
            JOIN classes cls ON cls.id = a.class_id
            JOIN courses c ON c.id = cls.course_id
            WHERE u.role = 'lecturer'
            ORDER BY created_at DESC
        ");
        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

        return View::render('auth/notifications', [
            'pageTitle' => 'Notifications — Lecturer Threads & Assignments',
            'activeNav' => 'notifications',
            'currentUser' => $user,
            'notifications' => $notifications,
        ]);
    }

    public static function changePassword(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $currentPassword = (string) ($request->post['current_password'] ?? '');
        $newPassword = (string) ($request->post['new_password'] ?? '');

        if (!AuthService::verifyPasswordForReauth($user->id, $currentPassword)) {
            Session::flash('error', 'Re-authentication failed: current password is incorrect.');
            return Response::redirect('/profile');
        }
        if (strlen($newPassword) < 10) {
            Session::flash('error', 'New password must be at least 10 characters long.');
            return Response::redirect('/profile');
        }

        $pdo = Database::getConnection();
        $hash = AuthService::hashPassword($newPassword);
        $pdo->prepare("UPDATE users SET password_hash = :h, force_password_change = 0 WHERE id = :id")
            ->execute([':h' => $hash, ':id' => $user->id]);

        Session::regenerate();
        AuditService::log($user->id, 'security.password.changed', 'users', (string) $user->id);
        Session::flash('success', 'Password updated with Argon2id and session ID regenerated.');
        return Response::redirect('/profile');
    }

    public static function exportOwnData(Request $request): Response
    {
        $user = AuthService::currentUser();
        $targetOwnerId = isset($request->query['user_id']) ? (int) $request->query['user_id'] : $user->id;
        if ($targetOwnerId !== $user->id) {
            return Response::json(['error' => 'Forbidden (BOLA): Cannot export another user\'s data'], 403);
        }

        $pdo = Database::getConnection();
        $gradesStmt = $pdo->prepare("
            SELECT c.code AS course_code, gc.name AS component, sg.score
            FROM student_grades sg
            JOIN grade_components gc ON gc.id = sg.component_id
            JOIN classes cls ON cls.id = gc.class_id
            JOIN courses c ON c.id = cls.course_id
            WHERE sg.student_id = :uid AND gc.is_published = 1
        ");
        $gradesStmt->execute([':uid' => $user->id]);

        $attStmt = $pdo->prepare("
            SELECT cs.session_number, cs.title, sa.status, sa.marked_at
            FROM session_attendance sa
            JOIN course_sessions cs ON cs.id = sa.session_id
            WHERE sa.student_id = :uid
        ");
        $attStmt->execute([':uid' => $user->id]);

        AuditService::log($user->id, 'privacy.data.exported', 'users', (string) $user->id);

        return Response::json([
            'exported_at' => gmdate('c'),
            'profile' => [
                'id' => $user->id,
                'uuid' => $user->uuid,
                'username' => $user->username,
                'role' => $user->role,
                'pseudonym' => $user->fullName,
                'email' => $user->email,
                'student_id_alias' => $user->studentIdAlias,
                'lecturer_code_alias' => $user->lecturerCodeAlias,
            ],
            'enrolled_course_ids' => $user->enrolledCourseIds,
            'published_grades' => $gradesStmt->fetchAll(PDO::FETCH_ASSOC),
            'attendance_records' => $attStmt->fetchAll(PDO::FETCH_ASSOC),
        ], 200, [
            'Content-Disposition' => 'attachment; filename="my-lms-data-' . $user->username . '.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
