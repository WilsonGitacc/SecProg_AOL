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
use App\Service\IcsExporter;
use PDO;

final class ScheduleController
{
    public static function index(Request $request): Response
    {
        $user = AuthService::currentUser();
        $pdo = Database::getConnection();

        $allowedTypes = ['all', 'onsite_class', 'virtual_class', 'online', 'onsite_exam', 'assignment'];
        $typeFilter = strtolower((string) ($request->query['type'] ?? 'all'));
        if (!in_array($typeFilter, $allowedTypes, true)) {
            $typeFilter = 'all';
        }

        $dateFilter = (string) ($request->query['date'] ?? '');
        $monthFilter = (string) ($request->query['month'] ?? '');

        $sql = "
            SELECT se.*, cls.class_code, cls.delivery_type,
                   c.id AS course_id, c.code AS course_code, c.name AS course_name,
                   cs.session_number
            FROM schedule_entries se
            JOIN classes cls ON cls.id = se.class_id
            JOIN courses c ON c.id = cls.course_id
            LEFT JOIN course_sessions cs ON cs.id = se.session_id
        ";
        $params = [];

        if ($user->role === 'lecturer') {
            $sql .= " JOIN class_lecturers cl ON cl.class_id = cls.id AND cl.user_id = :uid WHERE 1=1";
            $params[':uid'] = $user->id;
        } else {
            $sql .= " WHERE 1=1";
        }

        if ($typeFilter !== 'all') {
            $sql .= " AND se.entry_type = :etype";
            $params[':etype'] = $typeFilter;
        }
        if ($dateFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
            $sql .= " AND DATE(se.start_time) = :dt";
            $params[':dt'] = $dateFilter;
        } elseif ($monthFilter !== '' && preg_match('/^\d{4}-\d{2}$/', $monthFilter)) {
            $sql .= " AND se.start_time LIKE :mo";
            $params[':mo'] = $monthFilter . '-%';
        }

        $sql .= " ORDER BY se.start_time ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $classes = $pdo->query("
            SELECT cls.id, cls.class_code, c.code AS course_code, c.name AS course_name
            FROM classes cls
            JOIN courses c ON c.id = cls.course_id
            WHERE cls.delivery_type = 'LEC'
            ORDER BY c.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $sessions = $pdo->query("
            SELECT cs.id, cs.session_number, cs.title, cs.start_time, cs.end_time, cs.room,
                   c.code AS course_code, c.name AS course_name, cls.class_code
            FROM course_sessions cs
            JOIN classes cls ON cls.id = cs.class_id
            JOIN courses c ON c.id = cls.course_id
            WHERE cls.delivery_type = 'LEC'
            ORDER BY c.code ASC, cs.session_number ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all scheduled dates from database to dynamically highlight October & November 2026 calendar cells
        $calSql = "SELECT DATE(se.start_time) AS dt, se.entry_type FROM schedule_entries se JOIN classes cls ON cls.id = se.class_id";
        $calParams = [];
        if ($user->role === 'lecturer') {
            $calSql .= " JOIN class_lecturers cl ON cl.class_id = cls.id AND cl.user_id = :uid";
            $calParams[':uid'] = $user->id;
        }
        $calSql .= " ORDER BY se.start_time ASC";
        $calStmt = $pdo->prepare($calSql);
        $calStmt->execute($calParams);
        $calendarDates = [];
        foreach ($calStmt->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
            $d = (string) $cRow['dt'];
            if (!isset($calendarDates[$d])) {
                $calendarDates[$d] = (string) $cRow['entry_type'];
            }
        }

        $selectedCourseCode = trim((string) ($request->query['course_code'] ?? ''));

        return View::render('schedule/index', [
            'pageTitle' => 'My Schedule (Oct — Nov 30, 2026)',
            'activeNav' => 'schedule',
            'currentUser' => $user,
            'entries' => $entries,
            'typeFilter' => $typeFilter,
            'dateFilter' => $dateFilter,
            'monthFilter' => $monthFilter,
            'classes' => $classes,
            'sessions' => $sessions,
            'calendarDates' => $calendarDates,
            'selectedCourseCode' => $selectedCourseCode,
        ]);
    }

    public static function exportIcs(Request $request): Response
    {
        $user = AuthService::currentUser();
        $targetUserId = isset($request->query['user_id']) ? (int) $request->query['user_id'] : $user->id;
        if (!Policy::authorize($user, '/schedule/export.ics', 'GET', $targetUserId)) {
            return Response::html('<h1>403 Forbidden — Cannot export another user\'s schedule</h1>', 403);
        }

        $pdo = Database::getConnection();
        $entries = $pdo->query("
            SELECT se.*, c.code AS course_code, c.name AS course_name
            FROM schedule_entries se
            JOIN classes cls ON cls.id = se.class_id
            JOIN courses c ON c.id = cls.course_id
            ORDER BY se.start_time ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $icsContent = IcsExporter::generate($entries, $user->username);
        AuditService::log($user->id, 'schedule.ics.exported', 'schedule_entries', (string) $user->id);

        return new Response(200, $icsContent, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="schedule-' . $user->username . '-2610.ics"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function manageSchedule(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        if (!Policy::authorize($user, '/schedule/manage', 'POST')) {
            return Response::html('<h1>403 Forbidden — Only Student Service Operator may allocate rooms/schedules</h1>', 403);
        }

        $classId = (int) ($request->post['class_id'] ?? 0);
        $entryType = (string) ($request->post['entry_type'] ?? 'onsite_class');
        $title = trim((string) ($request->post['title'] ?? 'Scheduled Session'));
        $startTime = trim((string) ($request->post['start_time'] ?? ''));
        $endTime = trim((string) ($request->post['end_time'] ?? ''));
        $room = trim((string) ($request->post['room'] ?? 'Anggrek - 605'));

        $pdo = Database::getConnection();

        // Automatic Room & Class Double-Booking Conflict Detection
        if ($room !== '' && $entryType === 'onsite_class') {
            $confStmt = $pdo->prepare("
                SELECT COUNT(*) FROM schedule_entries
                WHERE room = :room
                  AND start_time < :end_time
                  AND end_time > :start_time
            ");
            $confStmt->execute([':room' => $room, ':start_time' => $startTime, ':end_time' => $endTime]);
            if ((int) $confStmt->fetchColumn() > 0) {
                Session::flash('error', "Conflict Detected: Room '{$room}' is already double-booked during {$startTime} - {$endTime}.");
                return Response::redirect('/schedule');
            }
        }

        $badgeMap = [
            'onsite_class' => 'Onsite Class',
            'virtual_class' => 'Virtual Class',
            'online' => 'Online',
            'onsite_exam' => 'Onsite Exam',
            'assignment' => 'Assignment',
        ];
        $badge = $badgeMap[$entryType] ?? 'Onsite Class';
        $uuid = bin2hex(random_bytes(16));

        $pdo->prepare("
            INSERT INTO schedule_entries (uuid, class_id, entry_type, badge_label, title, start_time, end_time, timezone, room, details)
            VALUES (:u, :clid, :type, :badge, :title, :st, :et, 'Asia/Jakarta', :room, 'Operator scheduled entry')
        ")->execute([
            ':u' => $uuid,
            ':clid' => $classId,
            ':type' => $entryType,
            ':badge' => $badge,
            ':title' => $title,
            ':st' => $startTime,
            ':et' => $endTime,
            ':room' => $room,
        ]);

        AuditService::log($user->id, 'schedule.entry.created', 'schedule_entries', $uuid, ['room' => $room, 'start' => $startTime]);
        Session::flash('success', 'Schedule entry allocated after passing conflict detection.');
        return Response::redirect('/schedule');
    }
}
