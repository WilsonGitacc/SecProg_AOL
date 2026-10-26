<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Security\Csrf;
use App\Security\Markdown;
use App\Security\Policy;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\CsvService;
use App\Service\UploadService;
use PDO;

final class CourseController
{
    public const MAX_VISIBLE_SESSIONS = 6;

    public static function index(Request $request): Response
    {
        $user = AuthService::currentUser();
        $pdo = Database::getConnection();

        // Allowlisted sorting columns (prevents SQL injection via ORDER BY)
        $allowedSort = [
            'name' => 'c.name ASC',
            'code' => 'c.code ASC',
            'progress' => 'cls.class_progress_pct DESC',
        ];
        $sortKey = (string) ($request->query['sort'] ?? 'name');
        $orderBySql = $allowedSort[$sortKey] ?? $allowedSort['name'];

        // Strictly fetch LEC classes ONLY; Lecturers only see their 2 assigned courses
        if ($user->role === 'lecturer') {
            $stmt = $pdo->prepare("
                SELECT c.id AS course_id, c.uuid AS course_uuid, c.code, c.name,
                       c.credits_lecture, c.credits_lab, c.category,
                       cls.id AS class_id, cls.class_code, cls.delivery_type, cls.class_progress_pct,
                       ap.name AS period_name, ap.code AS period_code
                FROM classes cls
                JOIN courses c ON c.id = cls.course_id
                JOIN academic_periods ap ON ap.id = cls.academic_period_id
                JOIN class_lecturers cl ON cl.class_id = cls.id AND cl.user_id = :uid
                WHERE ap.code = '2610' AND cls.delivery_type = 'LEC'
                ORDER BY {$orderBySql}
            ");
            $stmt->execute([':uid' => $user->id]);
        } elseif ($user->role === 'student') {
            $stmt = $pdo->prepare("
                SELECT c.id AS course_id, c.uuid AS course_uuid, c.code, c.name,
                       c.credits_lecture, c.credits_lab, c.category,
                       cls.id AS class_id, cls.class_code, cls.delivery_type, cls.class_progress_pct,
                       ap.name AS period_name, ap.code AS period_code
                FROM classes cls
                JOIN courses c ON c.id = cls.course_id
                JOIN academic_periods ap ON ap.id = cls.academic_period_id
                JOIN course_enrollments ce ON ce.class_id = cls.id AND ce.user_id = :uid AND ce.status = 'enrolled'
                WHERE ap.code = '2610' AND cls.delivery_type = 'LEC'
                ORDER BY {$orderBySql}
            ");
            $stmt->execute([':uid' => $user->id]);
        } else {
            $stmt = $pdo->query("
                SELECT c.id AS course_id, c.uuid AS course_uuid, c.code, c.name,
                       c.credits_lecture, c.credits_lab, c.category,
                       cls.id AS class_id, cls.class_code, cls.delivery_type, cls.class_progress_pct,
                       ap.name AS period_name, ap.code AS period_code
                FROM classes cls
                JOIN courses c ON c.id = cls.course_id
                JOIN academic_periods ap ON ap.id = cls.academic_period_id
                WHERE ap.code = '2610' AND cls.delivery_type = 'LEC'
                ORDER BY {$orderBySql}
            ");
        }
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return View::render('courses/index', [
            'pageTitle' => 'My Courses',
            'activeNav' => 'courses',
            'currentUser' => $user,
            'courses' => $courses,
            'sortKey' => $sortKey,
        ]);
    }

    public static function show(Request $request): Response
    {
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        $activeTab = strtolower((string) ($request->query['tab'] ?? 'session'));

        $subRoute = $activeTab === 'gradebook' ? "/courses/{$courseId}/gradebook" : "/courses/{$courseId}";
        $targetStudentId = isset($request->query['student_id']) ? (int) $request->query['student_id'] : null;
        $status = Policy::evaluateRoute($user, $subRoute, 'GET', $targetStudentId, $courseId);
        if ($status !== 200) {
            return Response::html('<h1>403 Forbidden — Access Denied by RBAC / Object-Level Policy</h1>', $status);
        }

        $pdo = Database::getConnection();
        $courseStmt = $pdo->prepare("
            SELECT c.*, cls.id AS class_id, cls.class_code, cls.delivery_type, cls.class_progress_pct
            FROM courses c
            JOIN classes cls ON cls.course_id = c.id AND cls.delivery_type = 'LEC'
            WHERE c.id = :cid
            LIMIT 1
        ");
        $courseStmt->execute([':cid' => $courseId]);
        $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
        if (!$course) {
            return Response::html('<h1>404 Course Not Found</h1>', 404);
        }
        $classId = (int) $course['class_id'];

        if ($user->role === 'lecturer') {
            $lecCheck = $pdo->prepare("SELECT COUNT(*) FROM class_lecturers WHERE class_id = :clid AND user_id = :uid");
            $lecCheck->execute([':clid' => $classId, ':uid' => $user->id]);
            if ((int) $lecCheck->fetchColumn() === 0) {
                return Response::html('<h1>403 Forbidden — You are only assigned to your 2 responsible courses</h1>', 403);
            }
        }

        // Strictly limit visible sessions per course to MAX_VISIBLE_SESSIONS = 3 (Sessions 1, 2, 3)
        $maxSessions = self::MAX_VISIBLE_SESSIONS;
        $sessStmt = $pdo->prepare("
            SELECT * FROM course_sessions
            WHERE class_id = :clid AND session_number IN (1, 2, 3, 4, 5, 6)
            ORDER BY session_number ASC
            LIMIT {$maxSessions}
        ");
        $sessStmt->execute([':clid' => $classId]);
        $sessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

        $selectedSessionNum = (int) ($request->query['session'] ?? 1);
        $currentSession = $sessions[0] ?? null;
        foreach ($sessions as $s) {
            if ((int) $s['session_number'] === $selectedSessionNum) {
                $currentSession = $s;
                break;
            }
        }

        $outcomes = [];
        $subTopics = [];
        $materials = [];
        if ($currentSession) {
            $sid = (int) $currentSession['id'];
            $loStmt = $pdo->prepare("SELECT * FROM session_learning_outcomes WHERE session_id = :sid ORDER BY sort_order ASC");
            $loStmt->execute([':sid' => $sid]);
            $outcomes = $loStmt->fetchAll(PDO::FETCH_ASSOC);

            $stStmt = $pdo->prepare("SELECT * FROM session_sub_topics WHERE session_id = :sid ORDER BY sort_order ASC");
            $stStmt->execute([':sid' => $sid]);
            $subTopics = $stStmt->fetchAll(PDO::FETCH_ASSOC);

            $matStmt = $pdo->prepare("SELECT * FROM session_materials WHERE session_id = :sid ORDER BY sort_order ASC");
            $matStmt->execute([':sid' => $sid]);
            $materials = $matStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Tab 1: Forum Threads & Posts (Scoped to the selected Session 1..6)
        $currentSessionId = $currentSession ? (int) $currentSession['id'] : 0;

        $cntStmt = $pdo->prepare("
            SELECT cs.session_number, COUNT(ft.id) AS thread_count
            FROM course_sessions cs
            LEFT JOIN forum_threads ft ON ft.session_id = cs.id AND ft.deleted_at IS NULL
            WHERE cs.class_id = :clid
            GROUP BY cs.session_number
        ");
        $cntStmt->execute([':clid' => $classId]);
        $threadCountsBySessionNum = [];
        foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
            $threadCountsBySessionNum[(int) $cRow['session_number']] = (int) $cRow['thread_count'];
        }

        $searchQ = trim((string) ($request->query['q'] ?? ''));
        if ($searchQ !== '') {
            $fStmt = $pdo->prepare("
                SELECT ft.*, u.full_name AS author_name, u.role AS author_role
                FROM forum_threads ft
                JOIN users u ON u.id = ft.author_id
                WHERE ft.class_id = :clid AND ft.session_id = :sid AND ft.deleted_at IS NULL AND ft.title LIKE :q
                ORDER BY ft.is_pinned DESC, ft.created_at DESC
            ");
            $fStmt->execute([':clid' => $classId, ':sid' => $currentSessionId, ':q' => '%' . $searchQ . '%']);
        } else {
            $fStmt = $pdo->prepare("
                SELECT ft.*, u.full_name AS author_name, u.role AS author_role
                FROM forum_threads ft
                JOIN users u ON u.id = ft.author_id
                WHERE ft.class_id = :clid AND ft.session_id = :sid AND ft.deleted_at IS NULL
                ORDER BY ft.is_pinned DESC, ft.created_at DESC
            ");
            $fStmt->execute([':clid' => $classId, ':sid' => $currentSessionId]);
        }
        $threads = $fStmt->fetchAll(PDO::FETCH_ASSOC);

        $postsByThread = [];
        foreach ($threads as $t) {
            $pStmt = $pdo->prepare("
                SELECT fp.*, u.full_name AS author_name, u.role AS author_role
                FROM forum_posts fp
                JOIN users u ON u.id = fp.author_id
                WHERE fp.thread_id = :tid AND fp.deleted_at IS NULL
                ORDER BY fp.created_at ASC
            ");
            $pStmt->execute([':tid' => (int) $t['id']]);
            $postsByThread[(int) $t['id']] = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Tab 2: Gradebook (Separation of Duties: Operator never sees gradebook; Student sees own published only)
        $grades = [];
        if ($user->role === 'student') {
            $gStmt = $pdo->prepare("
                SELECT gc.id AS component_id, gc.name, gc.weight_pct, gc.is_published, sg.score
                FROM grade_components gc
                LEFT JOIN student_grades sg ON sg.component_id = gc.id AND sg.student_id = :uid
                WHERE gc.class_id = :clid AND gc.is_published = 1
                ORDER BY gc.id ASC
            ");
            $gStmt->execute([':uid' => $user->id, ':clid' => $classId]);
            $grades = $gStmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($user->role === 'lecturer') {
            $gStmt = $pdo->prepare("
                SELECT gc.id AS component_id, gc.name, gc.weight_pct, gc.is_published, gc.is_locked,
                       u.id AS student_id, u.full_name AS student_name, u.student_id_alias, sg.score, sg.notes
                FROM grade_components gc
                CROSS JOIN course_enrollments ce ON ce.class_id = gc.class_id AND ce.status = 'enrolled'
                JOIN users u ON u.id = ce.user_id
                LEFT JOIN student_grades sg ON sg.component_id = gc.id AND sg.student_id = u.id
                WHERE gc.class_id = :clid
                ORDER BY gc.id ASC, u.full_name ASC
            ");
            $gStmt->execute([':clid' => $classId]);
            $grades = $gStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Tab 3: People (Pseudonymized Classmates & Facilitators)
        $peopleStmt = $pdo->prepare("
            SELECT u.full_name, u.role, u.student_id_alias, u.lecturer_code_alias, u.academic_title
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            WHERE ce.class_id = :clid1 AND ce.status = 'enrolled'
            UNION ALL
            SELECT u.full_name, u.role, u.student_id_alias, u.lecturer_code_alias, u.academic_title
            FROM class_lecturers cl
            JOIN users u ON u.id = cl.user_id
            WHERE cl.class_id = :clid2
        ");
        $peopleStmt->execute([':clid1' => $classId, ':clid2' => $classId]);
        $people = $peopleStmt->fetchAll(PDO::FETCH_ASSOC);

        // Tab 4: Grouping
        $grpStmt = $pdo->prepare("
            SELECT sg.*, gs.name AS set_name, gs.min_members, gs.max_members, gs.window_close_at,
                   (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = sg.id) AS member_count
            FROM student_groups sg
            JOIN grouping_sets gs ON gs.id = sg.grouping_set_id
            WHERE gs.class_id = :clid
            ORDER BY sg.id ASC
        ");
        $grpStmt->execute([':clid' => $classId]);
        $groups = $grpStmt->fetchAll(PDO::FETCH_ASSOC);

        $groupMembers = [];
        $myGroupId = null;
        foreach ($groups as $g) {
            $gid = (int) $g['id'];
            $gmStmt = $pdo->prepare("
                SELECT u.id AS student_id, u.full_name, u.student_id_alias, gm.role
                FROM group_members gm
                JOIN users u ON u.id = gm.student_id
                WHERE gm.group_id = :gid
                ORDER BY CASE WHEN gm.role = 'leader' THEN 0 ELSE 1 END, u.full_name ASC
            ");
            $gmStmt->execute([':gid' => $gid]);
            $members = $gmStmt->fetchAll(PDO::FETCH_ASSOC);
            $groupMembers[$gid] = $members;
            foreach ($members as $m) {
                if ((int) $m['student_id'] === $user->id) {
                    $myGroupId = $gid;
                }
            }
        }

        // Tab 5: Attendance (status is NULL until session starts and lecturer marks it)
        if ($user->role === 'student') {
            $attStmt = $pdo->prepare("
                SELECT cs.id AS session_id, cs.session_number, cs.title, cs.start_time,
                       sa.status
                FROM course_sessions cs
                LEFT JOIN session_attendance sa ON sa.session_id = cs.id AND sa.student_id = :uid
                WHERE cs.class_id = :clid
                ORDER BY cs.session_number ASC
            ");
            $attStmt->execute([':uid' => $user->id, ':clid' => $classId]);
        } else {
            $attStmt = $pdo->prepare("
                SELECT cs.id AS session_id, cs.session_number, cs.title, cs.start_time,
                       u.id AS student_id, u.full_name AS student_name,
                       sa.status, sa.edit_reason
                FROM course_sessions cs
                CROSS JOIN course_enrollments ce ON ce.class_id = cs.class_id AND ce.status = 'enrolled'
                JOIN users u ON u.id = ce.user_id
                LEFT JOIN session_attendance sa ON sa.session_id = cs.id AND sa.student_id = u.id
                WHERE cs.class_id = :clid
                ORDER BY cs.session_number ASC, u.full_name ASC
            ");
            $attStmt->execute([':clid' => $classId]);
        }
        $attendanceList = $attStmt->fetchAll(PDO::FETCH_ASSOC);

        // Tab 6: Assignments
        $assignStmt = $pdo->prepare("
            SELECT a.*, u.full_name AS creator_name, u.academic_title
            FROM assignments a
            JOIN users u ON u.id = a.created_by
            WHERE a.class_id = :clid
            ORDER BY a.created_at DESC, a.due_date ASC
        ");
        $assignStmt->execute([':clid' => $classId]);
        $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

        $mySubmissions = [];
        $submissionsByAssignment = [];
        foreach ($assignments as $a) {
            $aid = (int) $a['id'];
            if ($user->role === 'student') {
                $subStmt = $pdo->prepare("
                    SELECT asub.*, uf.uuid AS file_uuid, uf.original_filename AS file_name
                    FROM assignment_submissions asub
                    LEFT JOIN uploaded_files uf ON uf.id = asub.file_id
                    WHERE asub.assignment_id = :aid AND asub.student_id = :sid
                    LIMIT 1
                ");
                $subStmt->execute([':aid' => $aid, ':sid' => $user->id]);
                $subRow = $subStmt->fetch(PDO::FETCH_ASSOC);
                if ($subRow) {
                    $mySubmissions[$aid] = $subRow;
                }
            } elseif ($user->role === 'lecturer') {
                $subStmt = $pdo->prepare("
                    SELECT asub.*, u.full_name AS student_name, u.student_id_alias,
                           uf.uuid AS file_uuid, uf.original_filename AS file_name
                    FROM assignment_submissions asub
                    JOIN users u ON u.id = asub.student_id
                    LEFT JOIN uploaded_files uf ON uf.id = asub.file_id
                    WHERE asub.assignment_id = :aid
                    ORDER BY asub.submitted_at DESC
                ");
                $subStmt->execute([':aid' => $aid]);
                $submissionsByAssignment[$aid] = $subStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        return View::render('courses/show', [
            'pageTitle' => $course['name'] . ' (' . $course['code'] . ')',
            'activeNav' => 'courses',
            'currentUser' => $user,
            'course' => $course,
            'sessions' => $sessions,
            'currentSession' => $currentSession,
            'outcomes' => $outcomes,
            'subTopics' => $subTopics,
            'materials' => $materials,
            'activeTab' => $activeTab,
            'searchQ' => $searchQ,
            'threads' => $threads,
            'threadCountsBySessionNum' => $threadCountsBySessionNum,
            'postsByThread' => $postsByThread,
            'grades' => $grades,
            'people' => $people,
            'groups' => $groups,
            'groupMembers' => $groupMembers,
            'myGroupId' => $myGroupId,
            'attendanceList' => $attendanceList,
            'assignments' => $assignments,
            'mySubmissions' => $mySubmissions,
            'submissionsByAssignment' => $submissionsByAssignment,
        ]);
    }

    private static function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-4%03x-%04x-%012x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0x8000, 0xbfff),
            random_int(0, 0xffffffffffff)
        );
    }

    private static function resolveSessionNumByThreadId(PDO $pdo, int $threadId): int
    {
        $stmt = $pdo->prepare("
            SELECT COALESCE(cs.session_number, 1)
            FROM forum_threads ft
            LEFT JOIN course_sessions cs ON cs.id = ft.session_id
            WHERE ft.id = :tid
            LIMIT 1
        ");
        $stmt->execute([':tid' => $threadId]);
        $num = (int) $stmt->fetchColumn();
        return $num >= 1 && $num <= 6 ? $num : 1;
    }

    public static function createForumThread(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/forum/threads", 'POST')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $sessionNum = max(1, min(6, (int) ($request->post['session_number'] ?? $request->query['session'] ?? 1)));
        $title = trim((string) ($request->post['title'] ?? ''));
        $bodyRaw = trim((string) ($request->post['body_raw'] ?? ''));
        if ($title === '' || $bodyRaw === '') {
            Session::flash('error', 'Thread title and content are required.');
            return Response::redirect("/courses/{$courseId}?tab=forum&session={$sessionNum}");
        }

        $pdo = Database::getConnection();
        $classId = (int) $pdo->query("SELECT id FROM classes WHERE course_id = {$courseId} AND delivery_type = 'LEC' LIMIT 1")->fetchColumn();

        $sessStmt = $pdo->prepare("SELECT id FROM course_sessions WHERE class_id = :clid AND session_number = :snum LIMIT 1");
        $sessStmt->execute([':clid' => $classId, ':snum' => $sessionNum]);
        $sessionId = (int) $sessStmt->fetchColumn();

        $tUuid = self::generateUuid();
        $pUuid = self::generateUuid();
        $safeHtml = Markdown::render($bodyRaw);

        $pdo->prepare("
            INSERT INTO forum_threads (uuid, class_id, session_id, author_id, title, is_pinned, is_locked)
            VALUES (:u, :clid, :sid, :aid, :title, 0, 0)
        ")->execute([
            ':u' => $tUuid,
            ':clid' => $classId,
            ':sid' => $sessionId > 0 ? $sessionId : null,
            ':aid' => $user->id,
            ':title' => substr($title, 0, 255),
        ]);
        $tid = (int) $pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO forum_posts (uuid, thread_id, author_id, body_raw, body_html)
            VALUES (:u, :tid, :aid, :raw, :html)
        ")->execute([':u' => $pUuid, ':tid' => $tid, ':aid' => $user->id, ':raw' => $bodyRaw, ':html' => $safeHtml]);

        AuditService::log($user->id, 'forum.thread.created', 'forum_threads', (string) $tid, [
            'course_id' => $courseId,
            'session_number' => $sessionNum,
        ]);
        Session::flash('success', "Forum thread posted to Session {$sessionNum} Forum.");
        return Response::redirect("/courses/{$courseId}?tab=forum&session={$sessionNum}");
    }

    public static function replyForumThread(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        $threadId = (int) ($request->params['threadId'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/forum/posts", 'POST')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $pdo = Database::getConnection();
        $sessionNum = self::resolveSessionNumByThreadId($pdo, $threadId);

        $bodyRaw = trim((string) ($request->post['body_raw'] ?? ''));
        if ($bodyRaw === '') {
            Session::flash('error', 'Reply content cannot be empty.');
            return Response::redirect("/courses/{$courseId}?tab=forum&session={$sessionNum}");
        }

        $tStmt = $pdo->prepare("SELECT id, is_locked, deleted_at FROM forum_threads WHERE id = :tid LIMIT 1");
        $tStmt->execute([':tid' => $threadId]);
        $thread = $tStmt->fetch(PDO::FETCH_ASSOC);

        if (!$thread || $thread['deleted_at'] !== null) {
            Session::flash('error', 'Thread not found.');
            return Response::redirect("/courses/{$courseId}?tab=forum&session={$sessionNum}");
        }
        if ((int) $thread['is_locked'] === 1 && $user->role !== 'lecturer') {
            Session::flash('error', 'This thread is locked by the lecturer.');
            return Response::redirect("/courses/{$courseId}?tab=forum&session={$sessionNum}");
        }

        $pUuid = self::generateUuid();
        $safeHtml = Markdown::render($bodyRaw);
        $pdo->prepare("
            INSERT INTO forum_posts (uuid, thread_id, author_id, body_raw, body_html)
            VALUES (:u, :tid, :aid, :raw, :html)
        ")->execute([':u' => $pUuid, ':tid' => $threadId, ':aid' => $user->id, ':raw' => $bodyRaw, ':html' => $safeHtml]);
        $postId = (int) $pdo->lastInsertId();

        AuditService::log($user->id, 'forum.post.replied', 'forum_posts', (string) $postId, ['thread_id' => $threadId]);
        Session::flash('success', "Reply posted to Session {$sessionNum} forum thread.");
        return Response::redirect("/courses/{$courseId}?tab=forum&session={$sessionNum}");
    }

    public static function deleteForumPost(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        $postId = (int) ($request->params['postId'] ?? 0);

        $pdo = Database::getConnection();
        $pStmt = $pdo->prepare("SELECT id, thread_id, author_id, created_at FROM forum_posts WHERE id = :pid AND deleted_at IS NULL LIMIT 1");
        $pStmt->execute([':pid' => $postId]);
        $post = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$post) {
            Session::flash('error', 'Post not found.');
            return Response::redirect("/courses/{$courseId}?tab=forum");
        }

        $ownerId = (int) $post['author_id'];
        $threadId = (int) $post['thread_id'];
        $sessionNum = self::resolveSessionNumByThreadId($pdo, $threadId);
        $ageSeconds = max(0, time() - strtotime((string) $post['created_at']));

        if (!Policy::authorize($user, "/courses/{$courseId}/forum/posts/{$postId}", 'DELETE', $ownerId, $courseId, $ageSeconds)) {
            Session::flash('error', 'Forbidden: You can only delete your own post within 15 minutes of posting.');
            return Response::redirect("/courses/{$courseId}?tab=forum&session={$sessionNum}");
        }

        // Check if this post is the opening post (Main Thread post) of the thread
        $firstPostStmt = $pdo->prepare("SELECT MIN(id) FROM forum_posts WHERE thread_id = :tid");
        $firstPostStmt->execute([':tid' => $threadId]);
        $firstPostId = (int) $firstPostStmt->fetchColumn();

        $pdo->prepare("
            UPDATE forum_posts
            SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :uid, delete_reason = 'Deleted by author/moderator'
            WHERE id = :pid
        ")->execute([':uid' => $user->id, ':pid' => $postId]);

        if ($postId === $firstPostId) {
            $pdo->prepare("
                UPDATE forum_threads
                SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :uid, delete_reason = 'Main thread post deleted'
                WHERE id = :tid
            ")->execute([':uid' => $user->id, ':tid' => $threadId]);
        }

        AuditService::log($user->id, 'forum.post.deleted', 'forum_posts', (string) $postId);
        Session::flash('success', 'Forum post soft-deleted and audited.');
        return Response::redirect("/courses/{$courseId}?tab=forum&session={$sessionNum}");
    }

    public static function moderateForumThread(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        $threadId = (int) ($request->params['threadId'] ?? 0);

        if (!Policy::authorize($user, "/courses/{$courseId}/forum/moderate", 'POST')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $modAction = (string) ($request->post['mod_action'] ?? 'pin');
        $pdo = Database::getConnection();
        $sessionNum = self::resolveSessionNumByThreadId($pdo, $threadId);

        if ($modAction === 'toggle_pin') {
            $pdo->prepare("UPDATE forum_threads SET is_pinned = IF(is_pinned = 1, 0, 1) WHERE id = :tid")
                ->execute([':tid' => $threadId]);
        } elseif ($modAction === 'toggle_lock') {
            $pdo->prepare("UPDATE forum_threads SET is_locked = IF(is_locked = 1, 0, 1) WHERE id = :tid")
                ->execute([':tid' => $threadId]);
        } elseif ($modAction === 'soft_delete') {
            $pdo->prepare("UPDATE forum_threads SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :uid, delete_reason = 'Lecturer moderation' WHERE id = :tid")
                ->execute([':uid' => $user->id, ':tid' => $threadId]);
        }

        AuditService::log($user->id, 'forum.thread.moderated', 'forum_threads', (string) $threadId, ['action' => $modAction]);
        Session::flash('success', "Forum moderation action ({$modAction}) applied.");
        return Response::redirect("/courses/{$courseId}?tab=forum&session={$sessionNum}");
    }

    public static function reportForumPost(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        $postId = (int) ($request->params['postId'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/forum/posts/{$postId}/report", 'POST')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $reason = trim((string) ($request->post['reason'] ?? 'Inappropriate content'));
        $pdo = Database::getConnection();
        $tStmt = $pdo->prepare("SELECT thread_id FROM forum_posts WHERE id = :pid LIMIT 1");
        $tStmt->execute([':pid' => $postId]);
        $threadId = (int) $tStmt->fetchColumn();
        $sessionNum = $threadId > 0 ? self::resolveSessionNumByThreadId($pdo, $threadId) : 1;

        $pdo->prepare("
            INSERT INTO forum_post_reports (post_id, reporter_id, reason, status)
            VALUES (:pid, :uid, :reason, 'pending')
        ")->execute([':pid' => $postId, ':uid' => $user->id, ':reason' => substr($reason, 0, 500)]);

        AuditService::log($user->id, 'forum.post.reported', 'forum_posts', (string) $postId);
        Session::flash('success', 'Forum post reported for moderation.');
        return Response::redirect("/courses/{$courseId}?tab=forum&session={$sessionNum}");
    }

    public static function updateGrade(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/gradebook/update", 'POST')) {
            return Response::html('<h1>403 Forbidden — Separation of Duties / RBAC Violation</h1>', 403);
        }

        $componentId = (int) ($request->post['component_id'] ?? 0);
        $studentId = (int) ($request->post['student_id'] ?? 0);
        $score = (float) ($request->post['score'] ?? -1);
        $reason = trim((string) ($request->post['reason'] ?? 'Lecturer grade entry'));

        if ($score < 0.0 || $score > 100.0) {
            Session::flash('error', 'Grade score must be strictly within 0.00 to 100.00.');
            return Response::redirect("/courses/{$courseId}?tab=gradebook");
        }

        $pdo = Database::getConnection();
        $existingStmt = $pdo->prepare("SELECT id, score FROM student_grades WHERE component_id = :cid AND student_id = :sid LIMIT 1");
        $existingStmt->execute([':cid' => $componentId, ':sid' => $studentId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $gradeId = (int) $existing['id'];
            $oldScore = (float) $existing['score'];
            $pdo->prepare("UPDATE student_grades SET score = :s, created_by = :u WHERE id = :id")
                ->execute([':s' => $score, ':u' => $user->id, ':id' => $gradeId]);
        } else {
            $oldScore = null;
            $pdo->prepare("INSERT INTO student_grades (component_id, student_id, score, created_by) VALUES (:c, :st, :sc, :u)")
                ->execute([':c' => $componentId, ':st' => $studentId, ':sc' => $score, ':u' => $user->id]);
            $gradeId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare("
            INSERT INTO grade_change_history (grade_id, old_score, new_score, change_reason, changed_by)
            VALUES (:gid, :old, :new, :reason, :by)
        ")->execute([
            ':gid' => $gradeId,
            ':old' => $oldScore,
            ':new' => $score,
            ':reason' => substr($reason, 0, 500),
            ':by' => $user->id,
        ]);

        AuditService::log($user->id, 'grade.updated', 'student_grades', (string) $gradeId, [
            'old_score' => $oldScore,
            'new_score' => $score,
            'reason' => $reason,
        ]);
        Session::flash('success', 'Grade recorded and logged to immutable grade change history.');
        return Response::redirect("/courses/{$courseId}?tab=gradebook");
    }

    public static function exportGradesCsv(Request $request): Response
    {
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/gradebook/export", 'GET')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT u.student_id_alias, u.full_name, gc.name AS component, sg.score
            FROM student_grades sg
            JOIN grade_components gc ON gc.id = sg.component_id
            JOIN classes cls ON cls.id = gc.class_id
            JOIN users u ON u.id = sg.student_id
            WHERE cls.course_id = :cid AND cls.delivery_type = 'LEC'
            ORDER BY u.full_name ASC, gc.id ASC
        ");
        $stmt->execute([':cid' => $courseId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [$r['student_id_alias'], $r['full_name'], $r['component'], $r['score']];
        }

        $csv = CsvService::exportTable(['Student_Alias', 'Pseudonym_Name', 'Component', 'Score'], $rows);
        AuditService::log($user->id, 'gradebook.csv.exported', 'courses', (string) $courseId);

        return new Response(200, $csv, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="grades-course-' . $courseId . '.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Joins a student group using a database transaction and SELECT ... FOR UPDATE row lock
     * so max_members is never exceeded and a student cannot join more than 1 group per course.
     */
    public static function joinGroup(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/grouping/join", 'POST')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $groupId = (int) ($request->post['group_id'] ?? 0);
        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare("
                SELECT sg.id, sg.name, gs.class_id, gs.max_members, gs.window_close_at, gs.status AS set_status
                FROM student_groups sg
                JOIN grouping_sets gs ON gs.id = sg.grouping_set_id
                WHERE sg.id = :gid
                FOR UPDATE
            ");
            $lockStmt->execute([':gid' => $groupId]);
            $grp = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$grp || $grp['set_status'] !== 'open') {
                $pdo->rollBack();
                Session::flash('error', 'Group is closed or does not exist.');
                return Response::redirect("/courses/{$courseId}?tab=grouping");
            }

            // Enforce 1-group-per-course invariant under pessimistic row lock
            $existingStmt = $pdo->prepare("
                SELECT sg.id, sg.name
                FROM group_members gm
                JOIN student_groups sg ON sg.id = gm.group_id
                JOIN grouping_sets gs ON gs.id = sg.grouping_set_id
                WHERE gs.class_id = :clid AND gm.student_id = :sid
                LIMIT 1
                FOR UPDATE
            ");
            $existingStmt->execute([':clid' => (int) $grp['class_id'], ':sid' => $user->id]);
            $existingGroup = $existingStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingGroup) {
                $pdo->rollBack();
                Session::flash('error', 'Cannot join group: You are already in "' . $existingGroup['name'] . '". You cannot join more than 1 group per course (leave your current group first to switch).');
                return Response::redirect("/courses/{$courseId}?tab=grouping");
            }

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = :gid FOR UPDATE");
            $cntStmt->execute([':gid' => $groupId]);
            $currentCount = (int) $cntStmt->fetchColumn();

            if ($currentCount >= (int) $grp['max_members']) {
                $pdo->rollBack();
                Session::flash('error', 'Conflict (409): Group has reached maximum member capacity.');
                return Response::redirect("/courses/{$courseId}?tab=grouping");
            }

            $role = $currentCount === 0 ? 'leader' : 'member';
            $ins = $pdo->prepare("INSERT INTO group_members (group_id, student_id, role) VALUES (:gid, :sid, :role)");
            $ins->execute([':gid' => $groupId, ':sid' => $user->id, ':role' => $role]);

            $pdo->commit();
            AuditService::log($user->id, 'group.member.joined', 'student_groups', (string) $groupId);
            Session::flash('success', 'Successfully joined "' . $grp['name'] . '" with transactional row-lock verification.');
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Session::flash('error', 'Unable to join group (already in a group or capacity full).');
        }

        return Response::redirect("/courses/{$courseId}?tab=grouping");
    }

    public static function leaveGroup(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/grouping/leave", 'POST')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $groupId = (int) ($request->post['group_id'] ?? 0);
        $pdo = Database::getConnection();

        $delStmt = $pdo->prepare("DELETE FROM group_members WHERE group_id = :gid AND student_id = :sid");
        $delStmt->execute([':gid' => $groupId, ':sid' => $user->id]);

        if ($delStmt->rowCount() > 0) {
            AuditService::log($user->id, 'group.member.left', 'student_groups', (string) $groupId);
            Session::flash('success', 'You have left the group. You may now join another group in this course.');
        } else {
            Session::flash('error', 'You are not a member of that group.');
        }

        return Response::redirect("/courses/{$courseId}?tab=grouping");
    }

    public static function createGroupSlot(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/grouping/create", 'POST')) {
            return Response::html('<h1>403 Forbidden — Only Lecturer can create group slots</h1>', 403);
        }

        $groupName = trim((string) ($request->post['group_name'] ?? ''));
        if ($groupName === '') {
            Session::flash('error', 'Group name is required to create a new group slot.');
            return Response::redirect("/courses/{$courseId}?tab=grouping");
        }

        $pdo = Database::getConnection();
        $classId = (int) $pdo->query("SELECT id FROM classes WHERE course_id = {$courseId} AND delivery_type = 'LEC' LIMIT 1")->fetchColumn();
        $lecCheck = $pdo->prepare("SELECT COUNT(*) FROM class_lecturers WHERE class_id = :clid AND user_id = :uid");
        $lecCheck->execute([':clid' => $classId, ':uid' => $user->id]);
        if ((int) $lecCheck->fetchColumn() === 0) {
            return Response::html('<h1>403 Forbidden — You are not assigned to this course</h1>', 403);
        }

        $setIdStmt = $pdo->prepare("SELECT id FROM grouping_sets WHERE class_id = :clid LIMIT 1");
        $setIdStmt->execute([':clid' => $classId]);
        $groupingSetId = (int) $setIdStmt->fetchColumn();
        if ($groupingSetId <= 0) {
            $gsUuid = self::generateUuid();
            $pdo->prepare("
                INSERT INTO grouping_sets (uuid, class_id, name, min_members, max_members, window_open_at, window_close_at, is_self_formed, status, created_by)
                VALUES (:u, :clid, 'Semester Project Teams', 2, 3, '2026-10-01 00:00:00', '2026-12-15 23:59:59', 1, 'open', :uid)
            ")->execute([':u' => $gsUuid, ':clid' => $classId, ':uid' => $user->id]);
            $groupingSetId = (int) $pdo->lastInsertId();
        }

        $grpUuid = self::generateUuid();
        $pdo->prepare("
            INSERT INTO student_groups (uuid, grouping_set_id, name, created_by, status, approved_by, approved_at)
            VALUES (:u, :sid, :name, :uid, 'approved', :app, CURRENT_TIMESTAMP)
        ")->execute([
            ':u' => $grpUuid,
            ':sid' => $groupingSetId,
            ':name' => substr($groupName, 0, 120),
            ':uid' => $user->id,
            ':app' => $user->id,
        ]);
        $newGroupId = (int) $pdo->lastInsertId();

        AuditService::log($user->id, 'group.slot.created', 'student_groups', (string) $newGroupId, [
            'course_id' => $courseId,
            'group_name' => $groupName,
        ]);
        Session::flash('success', 'New group slot "' . $groupName . '" created for students to join.');
        return Response::redirect("/courses/{$courseId}?tab=grouping");
    }

    public static function markAttendance(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/attendance/mark", 'POST')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $sessionId = (int) ($request->post['session_id'] ?? 0);
        $studentId = (int) ($request->post['student_id'] ?? 0);
        $status = trim((string) ($request->post['status'] ?? ''));
        $editReason = trim((string) ($request->post['edit_reason'] ?? 'Manual lecturer attendance update'));

        if (!in_array($status, ['present', 'late', 'absent', 'excused'], true)) {
            Session::flash('error', 'Please select a valid attendance status (present, late, absent, or excused).');
            return Response::redirect("/courses/{$courseId}?tab=attendance");
        }

        $pdo = Database::getConnection();
        $sessStmt = $pdo->prepare("SELECT start_time FROM course_sessions WHERE id = :sid LIMIT 1");
        $sessStmt->execute([':sid' => $sessionId]);
        $startTime = $sessStmt->fetchColumn();

        if (!$startTime || strtotime((string) $startTime) > time()) {
            Session::flash('error', 'Cannot mark attendance: the scheduled session date and time (' . (string) $startTime . ') has not arrived yet.');
            return Response::redirect("/courses/{$courseId}?tab=attendance");
        }

        $pdo->prepare("
            INSERT INTO session_attendance (session_id, student_id, status, marked_by, marked_at, edit_reason)
            VALUES (:sid, :st, :status, :by, CURRENT_TIMESTAMP, :reason)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                marked_by = VALUES(marked_by),
                marked_at = CURRENT_TIMESTAMP,
                edit_reason = VALUES(edit_reason)
        ")->execute([
            ':sid' => $sessionId,
            ':st' => $studentId,
            ':status' => $status,
            ':by' => $user->id,
            ':reason' => substr($editReason, 0, 255),
        ]);

        AuditService::log($user->id, 'attendance.marked', 'session_attendance', "{$sessionId}:{$studentId}", [
            'status' => $status,
            'reason' => $editReason,
        ]);
        Session::flash('success', 'Attendance record updated and audited.');
        return Response::redirect("/courses/{$courseId}?tab=attendance");
    }

    public static function createAssignment(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/assignments/create", 'POST')) {
            return Response::html('<h1>403 Forbidden — Only assigned Lecturer can create assignments</h1>', 403);
        }

        $title = trim((string) ($request->post['title'] ?? ''));
        $description = trim((string) ($request->post['description'] ?? ''));
        $dueDateRaw = trim((string) ($request->post['due_date'] ?? ''));
        $latePolicy = trim((string) ($request->post['late_policy'] ?? 'Strict deadline — no late submissions without approved excuse'));

        if ($title === '' || $description === '' || $dueDateRaw === '') {
            Session::flash('error', 'Assignment title, description, and due date are required.');
            return Response::redirect("/courses/{$courseId}?tab=assignment");
        }

        $dueDate = str_replace('T', ' ', $dueDateRaw);
        if (strlen($dueDate) === 16) {
            $dueDate .= ':00';
        }

        $pdo = Database::getConnection();
        $classId = (int) $pdo->query("SELECT id FROM classes WHERE course_id = {$courseId} AND delivery_type = 'LEC' LIMIT 1")->fetchColumn();
        $aUuid = self::generateUuid();

        $pdo->prepare("
            INSERT INTO assignments (uuid, class_id, title, description, due_date, late_policy, max_score, created_by)
            VALUES (:u, :clid, :title, :desc, :due, :late, 100.00, :uid)
        ")->execute([
            ':u' => $aUuid,
            ':clid' => $classId,
            ':title' => substr($title, 0, 255),
            ':desc' => $description,
            ':due' => $dueDate,
            ':late' => $latePolicy,
            ':uid' => $user->id,
        ]);
        $assignmentId = (int) $pdo->lastInsertId();

        // Also add to schedule_entries so it appears in Schedule Calendar & ICS Export
        $sUuid = self::generateUuid();
        $pdo->prepare("
            INSERT INTO schedule_entries (
                uuid, class_id, session_id, assignment_id, entry_type, badge_label,
                title, start_time, end_time, timezone, details
            ) VALUES (
                :u, :clid, NULL, :aid, 'assignment', 'Assignment',
                :title, :due1, :due2, 'Asia/Jakarta', :details
            )
        ")->execute([
            ':u' => $sUuid,
            ':clid' => $classId,
            ':aid' => $assignmentId,
            ':title' => substr($title, 0, 255),
            ':due1' => $dueDate,
            ':due2' => $dueDate,
            ':details' => 'Due Date: ' . $dueDate . ' GMT+7',
        ]);

        AuditService::log($user->id, 'assignment.created', 'assignments', (string) $assignmentId, [
            'course_id' => $courseId,
            'title' => $title,
            'due_date' => $dueDate,
        ]);
        Session::flash('success', 'New assignment published! It is now visible in Course Assignments, Schedule, and the Notification Bell.');
        return Response::redirect("/courses/{$courseId}?tab=assignment");
    }

    public static function submitAssignment(Request $request): Response
    {
        if (!Csrf::validate($request->method, (string) ($request->post['csrf_token'] ?? ''))) {
            return Response::html('<h1>403 Forbidden — Invalid CSRF Token</h1>', 403);
        }
        $user = AuthService::currentUser();
        $courseId = (int) ($request->params['id'] ?? 0);
        $assignmentId = (int) ($request->params['assignmentId'] ?? 0);
        if (!Policy::authorize($user, "/courses/{$courseId}/assignments/submit", 'POST')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $fileId = null;
        if (!empty($request->files['attachment']['name'])) {
            $up = UploadService::handleUpload($request->files['attachment'], $user->id);
            if (!$up['success']) {
                Session::flash('error', $up['error'] ?? 'Upload rejected.');
                return Response::redirect("/courses/{$courseId}?tab=assignment");
            }
            $fileId = $up['file_id'];
        }

        $pdo = Database::getConnection();
        $pdo->prepare("
            INSERT INTO assignment_submissions (assignment_id, student_id, submitted_at, file_id)
            VALUES (:aid, :sid, CURRENT_TIMESTAMP, :fid)
            ON DUPLICATE KEY UPDATE
                submitted_at = CURRENT_TIMESTAMP,
                file_id = COALESCE(VALUES(file_id), file_id)
        ")->execute([
            ':aid' => $assignmentId,
            ':sid' => $user->id,
            ':fid' => $fileId,
        ]);

        AuditService::log($user->id, 'assignment.submitted', 'assignment_submissions', "{$assignmentId}:{$user->id}");
        Session::flash('success', 'Assignment submission uploaded and recorded.');
        return Response::redirect("/courses/{$courseId}?tab=assignment");
    }
}
