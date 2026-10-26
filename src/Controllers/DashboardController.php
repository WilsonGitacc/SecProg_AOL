<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Service\AuthService;
use PDO;

final class DashboardController
{
    public static function index(Request $request): Response
    {
        $user = AuthService::currentUser();
        $pdo = Database::getConnection();

        // 1. Upcoming Class (filtered to assigned courses when logged in as lecturer)
        if ($user->role === 'lecturer') {
            $upcomingStmt = $pdo->prepare("
                SELECT se.*, cls.class_code, cls.delivery_type,
                       c.id AS course_id, c.code AS course_code, c.name AS course_name,
                       cs.session_number, u.full_name AS lecturer_name, u.academic_title
                FROM schedule_entries se
                JOIN classes cls ON cls.id = se.class_id
                JOIN courses c ON c.id = cls.course_id
                LEFT JOIN course_sessions cs ON cs.id = se.session_id
                JOIN class_lecturers cl ON cl.class_id = cls.id AND cl.user_id = :uid
                JOIN users u ON u.id = cl.user_id
                WHERE se.entry_type IN ('onsite_class', 'virtual_class', 'online')
                ORDER BY CASE WHEN se.start_time >= '2026-10-26 09:00:00' THEN 0 ELSE 1 END, se.start_time ASC
                LIMIT 1
            ");
            $upcomingStmt->execute([':uid' => $user->id]);
        } else {
            $upcomingStmt = $pdo->prepare("
                SELECT se.*, cls.class_code, cls.delivery_type,
                       c.id AS course_id, c.code AS course_code, c.name AS course_name,
                       cs.session_number, u.full_name AS lecturer_name, u.academic_title
                FROM schedule_entries se
                JOIN classes cls ON cls.id = se.class_id
                JOIN courses c ON c.id = cls.course_id
                LEFT JOIN course_sessions cs ON cs.id = se.session_id
                LEFT JOIN class_lecturers cl ON cl.class_id = cls.id AND cl.role = 'lead_lecturer'
                LEFT JOIN users u ON u.id = cl.user_id
                WHERE se.entry_type IN ('onsite_class', 'virtual_class', 'online')
                ORDER BY CASE WHEN se.start_time >= '2026-10-26 09:00:00' THEN 0 ELSE 1 END, se.start_time ASC
                LIMIT 1
            ");
            $upcomingStmt->execute();
        }
        $upcomingClass = $upcomingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 2. Latest Forum Posts (with pseudonymized author alias, role badge, date, title, course, session_number)
        $forumStmt = $pdo->query("
            SELECT ft.id, ft.title, ft.created_at, COALESCE(cs.session_number, 1) AS session_number,
                   u.full_name AS author_alias, u.role AS author_role,
                   c.id AS course_id, c.code AS course_code, c.name AS course_name,
                   cls.delivery_type
            FROM forum_threads ft
            JOIN users u ON u.id = ft.author_id
            JOIN classes cls ON cls.id = ft.class_id
            JOIN courses c ON c.id = cls.course_id
            LEFT JOIN course_sessions cs ON cs.id = ft.session_id
            WHERE ft.deleted_at IS NULL
            ORDER BY ft.created_at DESC
            LIMIT 5
        ");
        $latestForumPosts = $forumStmt->fetchAll(PDO::FETCH_ASSOC);

        $lecturerAttention = [];
        $operatorUsers = [];
        $operatorSessions = [];

        if ($user->role === 'lecturer') {
            $lecturerAttention = [
                'ungraded_submissions' => (int) $pdo->query("SELECT COUNT(*) FROM assignment_submissions WHERE score IS NULL")->fetchColumn(),
                'reported_posts' => (int) $pdo->query("SELECT COUNT(*) FROM forum_post_reports WHERE status = 'pending'")->fetchColumn(),
            ];
        } elseif ($user->role === 'operator') {
            $operatorUsers = $pdo->query("
                SELECT id, uuid, role, username, email, full_name,
                       student_id_alias, lecturer_code_alias, academic_title, is_active
                FROM users
                WHERE is_active = 1 AND role IN ('student', 'lecturer')
                ORDER BY FIELD(role, 'student', 'lecturer'), id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $operatorSessions = $pdo->query("
                SELECT cs.id, cs.session_number, cs.title, cs.delivery_mode,
                       cs.start_time, cs.end_time, cs.room, cs.status,
                       c.id AS course_id, c.code AS course_code, c.name AS course_name,
                       cls.class_code
                FROM course_sessions cs
                JOIN classes cls ON cls.id = cs.class_id
                JOIN courses c ON c.id = cls.course_id
                WHERE cls.delivery_type = 'LEC'
                ORDER BY c.code ASC, cs.session_number ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        $selectedCourseCode = trim((string) ($request->query['course_code'] ?? ''));

        return View::render('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'activeNav' => 'dashboard',
            'currentUser' => $user,
            'upcomingClass' => $upcomingClass,
            'latestForumPosts' => $latestForumPosts,
            'lecturerAttention' => $lecturerAttention,
            'users' => $operatorUsers,
            'sessions' => $operatorSessions,
            'selectedCourseCode' => $selectedCourseCode,
        ]);
    }
}
