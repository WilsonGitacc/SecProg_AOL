<?php

declare(strict_types=1);

/**
 * Secure Neo-Brutalist LMS — Central Front Controller & Policy Gate
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($uri === '/healthz' || $uri === '/healthz.php') {
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode([
        'status' => 'healthy',
        'timestamp' => gmdate('c'),
        'service' => 'secure-lms-web',
        'uptime_sapi' => PHP_SAPI,
    ], JSON_UNESCAPED_SLASHES);
    exit(0);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\AuthController;
use App\Controllers\CourseController;
use App\Controllers\DashboardController;
use App\Controllers\OperatorController;
use App\Controllers\ScheduleController;
use App\Controllers\UploadController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Security\Policy;
use App\Service\AuthService;

Session::start();
$request = new Request();
$currentUser = AuthService::currentUser();

// Central Deny-by-Default Policy Gate before dispatching
$policyCode = Policy::evaluateRoute($currentUser, $request->path, $request->method);
if ($policyCode === 401) {
    if ($request->wantsJson()) {
        Response::json(['error' => 'Unauthorized — Authentication required'], 401)->send();
        exit;
    }
    Response::redirect('/login')->send();
    exit;
}
if ($policyCode === 403) {
    if ($request->wantsJson()) {
        Response::json(['error' => 'Forbidden — Access denied by central RBAC/BOLA policy'], 403)->send();
        exit;
    }
    Response::html('<h1>403 Forbidden — Access Denied by Security Policy</h1>', 403)->send();
    exit;
}

$router = new Router();

// Authentication & Profile Routes
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/profile', [AuthController::class, 'profile']);
$router->get('/notifications', [AuthController::class, 'notifications']);
$router->post('/security/password', [AuthController::class, 'changePassword']);
$router->get('/profile/export-data', [AuthController::class, 'exportOwnData']);
$router->get('/export-data', [AuthController::class, 'exportOwnData']);

// 1. Dashboard (Root & /dashboard)
$router->get('/', [DashboardController::class, 'index']);
$router->get('/dashboard', [DashboardController::class, 'index']);

// 2. Courses (4 LEC Courses, 3 Visible Sessions, 6 Tabs including Assignment)
$router->get('/courses', [CourseController::class, 'index']);
$router->get('/courses/{id}', [CourseController::class, 'show']);
$router->get('/courses/{id}/gradebook/export', [CourseController::class, 'exportGradesCsv']);
$router->post('/courses/{id}/forum/threads', [CourseController::class, 'createForumThread']);
$router->post('/courses/{id}/forum/threads/{threadId}/reply', [CourseController::class, 'replyForumThread']);
$router->post('/courses/{id}/forum/threads/{threadId}/moderate', [CourseController::class, 'moderateForumThread']);
$router->post('/courses/{id}/forum/posts/{postId}/delete', [CourseController::class, 'deleteForumPost']);
$router->post('/courses/{id}/forum/posts/{postId}/report', [CourseController::class, 'reportForumPost']);
$router->post('/courses/{id}/gradebook/update', [CourseController::class, 'updateGrade']);
$router->post('/courses/{id}/grouping/join', [CourseController::class, 'joinGroup']);
$router->post('/courses/{id}/grouping/leave', [CourseController::class, 'leaveGroup']);
$router->post('/courses/{id}/grouping/create', [CourseController::class, 'createGroupSlot']);
$router->post('/courses/{id}/attendance/mark', [CourseController::class, 'markAttendance']);
$router->post('/courses/{id}/assignments/create', [CourseController::class, 'createAssignment']);
$router->post('/courses/{id}/assignments/{assignmentId}/submit', [CourseController::class, 'submitAssignment']);

// 3. Schedule & ICS Export
$router->get('/schedule', [ScheduleController::class, 'index']);
$router->get('/schedule/export.ics', [ScheduleController::class, 'exportIcs']);
$router->post('/schedule/manage', [ScheduleController::class, 'manageSchedule']);

// Operator Administration (Student/Lecturer Data & Course Session Dates)
$router->get('/operator/users', [OperatorController::class, 'users']);
$router->post('/operator/users/manage', [OperatorController::class, 'manageUser']);
$router->post('/operator/sessions/update', [OperatorController::class, 'updateSessionDate']);

// Safe File Download Controller (Outside Webroot)
$router->get('/files/{uuid}', [UploadController::class, 'download']);

// Role-Scoped Global Search
$router->get('/search', static function (Request $req): Response {
    $q = trim((string) ($req->query['q'] ?? ''));
    $pdo = Database::getConnection();
    $courseResults = [];
    $forumResults = [];
    if ($q !== '') {
        $cStmt = $pdo->prepare("SELECT id, code, name FROM courses WHERE name LIKE :q1 OR code LIKE :q2 ORDER BY name ASC LIMIT 20");
        $cStmt->execute([':q1' => '%' . $q . '%', ':q2' => '%' . $q . '%']);
        $courseResults = $cStmt->fetchAll(\PDO::FETCH_ASSOC);

        $fStmt = $pdo->prepare("
            SELECT ft.id, ft.title, c.id AS course_id, c.code AS course_code
            FROM forum_threads ft
            JOIN classes cls ON cls.id = ft.class_id
            JOIN courses c ON c.id = cls.course_id
            WHERE ft.deleted_at IS NULL AND ft.title LIKE :q
            ORDER BY ft.created_at DESC
            LIMIT 20
        ");
        $fStmt->execute([':q' => '%' . $q . '%']);
        $forumResults = $fStmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    return View::render('search/index', [
        'pageTitle' => 'Global Search',
        'query' => $q,
        'courseResults' => $courseResults,
        'forumResults' => $forumResults,
    ]);
});

$response = $router->dispatch($request);
$response->send();
