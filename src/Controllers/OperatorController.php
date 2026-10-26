<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Security\Csrf;
use App\Security\Policy;
use App\Service\AuditService;
use App\Service\AuthService;
use PDO;

final class OperatorController
{
    public static function users(Request $request): Response
    {
        $user = AuthService::currentUser();
        if (!Policy::authorize($user, '/operator/users', 'GET')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $pdo = Database::getConnection();
        // Never select password_hash into operator views (Separation of Duties / ASVS L2)
        // Strictly fetch active Student & Lecturer records only
        $users = $pdo->query("
            SELECT id, uuid, role, username, email, full_name,
                   student_id_alias, lecturer_code_alias, academic_title, is_active
            FROM users
            WHERE is_active = 1 AND role IN ('student', 'lecturer')
            ORDER BY FIELD(role, 'student', 'lecturer'), id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $sessions = $pdo->query("
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

        $selectedCourseCode = trim((string) ($request->query['course_code'] ?? ''));

        return View::render('operator/users', [
            'pageTitle' => 'Operator Console — Student/Lecturer Data & Session Dates',
            'activeNav' => 'courses',
            'currentUser' => $user,
            'users' => $users,
            'sessions' => $sessions,
            'selectedCourseCode' => $selectedCourseCode,
        ]);
    }

    public static function manageUser(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        if (!Policy::authorize($user, '/operator/users/manage', 'POST')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $targetUserId = (int) ($request->post['target_user_id'] ?? 0);
        $opAction = (string) ($request->post['op_action'] ?? 'edit_data');
        $redirectTo = (string) ($request->post['redirect_to'] ?? '/operator/users');
        if (!preg_match('#^/(operator/users|dashboard|schedule)(\?course_code=[A-Z0-9]+)?(\#session-dates)?$#', $redirectTo)) {
            $redirectTo = '/operator/users';
        }

        if ($targetUserId === $user->id && in_array($opAction, ['disable', 'role_change'], true)) {
            Session::flash('error', 'Self-elevation or self-disable is prohibited by policy.');
            return Response::redirect($redirectTo);
        }

        $pdo = Database::getConnection();

        $targetStmt = $pdo->prepare("SELECT id, role, full_name, email, student_id_alias, lecturer_code_alias, academic_title FROM users WHERE id = :id AND role IN ('student', 'lecturer') LIMIT 1");
        $targetStmt->execute([':id' => $targetUserId]);
        $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            Session::flash('error', 'Target user must be a valid Student or Lecturer record.');
            return Response::redirect($redirectTo);
        }

        $fullName = trim((string) ($request->post['full_name'] ?? $targetUser['full_name']));
        $email = strtolower(trim((string) ($request->post['email'] ?? $targetUser['email'])));
        $aliasCode = trim((string) ($request->post['alias_code'] ?? ($targetUser['role'] === 'student' ? $targetUser['student_id_alias'] : $targetUser['lecturer_code_alias'])));
        $academicTitle = trim((string) ($request->post['academic_title'] ?? ($targetUser['academic_title'] ?? '')));

        if ($fullName === '' || mb_strlen($fullName) > 120) {
            Session::flash('error', 'Full name is required and must be under 120 characters.');
            return Response::redirect($redirectTo);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'A valid institutional email address is required.');
            return Response::redirect($redirectTo);
        }

        // Check email uniqueness across other users
        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :em AND id != :id");
        $dupStmt->execute([':em' => $email, ':id' => $targetUserId]);
        if ((int) $dupStmt->fetchColumn() > 0) {
            Session::flash('error', "Email '{$email}' is already assigned to another user.");
            return Response::redirect($redirectTo);
        }

        if ($targetUser['role'] === 'student') {
            $upd = $pdo->prepare("
                UPDATE users
                SET full_name = :fn,
                    email = :em,
                    student_id_alias = :al,
                    academic_title = :at
                WHERE id = :id AND role = 'student'
            ");
            $upd->execute([
                ':fn' => $fullName,
                ':em' => $email,
                ':al' => $aliasCode !== '' ? $aliasCode : $targetUser['student_id_alias'],
                ':at' => $academicTitle !== '' ? $academicTitle : null,
                ':id' => $targetUserId,
            ]);
        } else {
            $upd = $pdo->prepare("
                UPDATE users
                SET full_name = :fn,
                    email = :em,
                    lecturer_code_alias = :al,
                    academic_title = :at
                WHERE id = :id AND role = 'lecturer'
            ");
            $upd->execute([
                ':fn' => $fullName,
                ':em' => $email,
                ':al' => $aliasCode !== '' ? $aliasCode : $targetUser['lecturer_code_alias'],
                ':at' => $academicTitle !== '' ? $academicTitle : null,
                ':id' => $targetUserId,
            ]);
        }

        AuditService::log($user->id, 'operator.user.data_updated', 'users', (string) $targetUserId, [
            'role' => $targetUser['role'],
            'full_name' => $fullName,
            'email' => $email,
            'alias' => $aliasCode,
        ]);
        Session::flash('success', sprintf('Updated %s data for %s (%s).', strtoupper((string) $targetUser['role']), $fullName, $email));

        return Response::redirect($redirectTo);
    }

    public static function updateSessionDate(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        if (!Policy::authorize($user, '/operator/sessions/update', 'POST')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $sessionId = (int) ($request->post['session_id'] ?? 0);
        $sessionDate = trim((string) ($request->post['session_date'] ?? ''));
        $sessionSlot = trim((string) ($request->post['session_slot'] ?? ''));
        $room = trim((string) ($request->post['room'] ?? ''));
        $redirectTo = (string) ($request->post['redirect_to'] ?? '/operator/users');
        if (!preg_match('#^/(operator/users|dashboard|schedule)(\?course_code=[A-Z0-9]+)?(\#session-dates)?$#', $redirectTo)) {
            $redirectTo = '/operator/users';
        }

        $allowedSlots = ['09:20', '11:20', '13:20', '15:20', '17:20'];

        $pdo = Database::getConnection();
        $sessStmt = $pdo->prepare("
            SELECT cs.id, cs.session_number, cs.start_time, cs.room, c.code AS course_code, c.name AS course_name
            FROM course_sessions cs
            JOIN classes cls ON cls.id = cs.class_id
            JOIN courses c ON c.id = cls.course_id
            WHERE cs.id = :sid
            LIMIT 1
        ");
        $sessStmt->execute([':sid' => $sessionId]);
        $sessionRow = $sessStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sessionRow) {
            Session::flash('error', 'Course session not found.');
            return Response::redirect($redirectTo);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
            Session::flash('error', 'Please select a valid session date (YYYY-MM-DD).');
            return Response::redirect($redirectTo);
        }

        // Default to existing shift if not provided, and strictly enforce the 5 fixed 100-minute lecture start times
        if ($sessionSlot === '') {
            $existingSlot = date('H:i', strtotime((string) $sessionRow['start_time']));
            $sessionSlot = in_array($existingSlot, $allowedSlots, true) ? $existingSlot : '09:20';
        }
        if (!in_array($sessionSlot, $allowedSlots, true)) {
            Session::flash('error', 'Lecture sessions must start at 09:20, 11:20, 13:20, 15:20, or 17:20 (100 minutes duration).');
            return Response::redirect($redirectTo);
        }

        $startTs = strtotime($sessionDate . ' ' . $sessionSlot . ':00');
        if ($startTs === false) {
            Session::flash('error', 'Invalid session date.');
            return Response::redirect($redirectTo);
        }
        // Every lecture session is strictly 100 minutes long
        $endTs = $startTs + (100 * 60);

        $startFormatted = date('Y-m-d H:i:s', $startTs);
        $endFormatted = date('Y-m-d H:i:s', $endTs);

        $finalRoom = $room !== '' ? $room : (string) $sessionRow['room'];
        $now = time();
        if ($endTs < $now) {
            $status = 'completed';
        } elseif ($startTs <= $now && $endTs >= $now) {
            $status = 'ongoing';
        } else {
            $status = 'planned';
        }

        $pdo->prepare("
            UPDATE course_sessions
            SET start_time = :st, end_time = :et, room = :rm, status = :status
            WHERE id = :sid
        ")->execute([
            ':st' => $startFormatted,
            ':et' => $endFormatted,
            ':rm' => $finalRoom,
            ':status' => $status,
            ':sid' => $sessionId,
        ]);

        // Synchronize linked schedule_entries row so Schedule & Dashboard reflect the updated session date immediately
        $pdo->prepare("
            UPDATE schedule_entries
            SET start_time = :st, end_time = :et, room = :rm
            WHERE session_id = :sid
        ")->execute([
            ':st' => $startFormatted,
            ':et' => $endFormatted,
            ':rm' => $finalRoom,
            ':sid' => $sessionId,
        ]);

        AuditService::log($user->id, 'operator.session.date_updated', 'course_sessions', (string) $sessionId, [
            'course_code' => $sessionRow['course_code'],
            'session_number' => $sessionRow['session_number'],
            'session_date' => $sessionDate,
            'session_slot' => $sessionSlot,
            'start_time' => $startFormatted,
            'end_time' => $endFormatted,
            'room' => $finalRoom,
        ]);

        Session::flash(
            'success',
            sprintf(
                'Updated %s Session %d to %s (%s – %s, 100 mins • %s).',
                $sessionRow['course_code'],
                (int) $sessionRow['session_number'],
                date('d M Y', $startTs),
                date('H:i', $startTs),
                date('H:i', $endTs),
                $finalRoom
            )
        );

        return Response::redirect($redirectTo);
    }
}
