#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Core/Database.php';
require_once dirname(__DIR__) . '/src/Service/PrivacyService.php';

use App\Core\Database;
use App\Service\PrivacyService;

$baseDir = dirname(__DIR__);
$rawDir = $baseDir . '/data/raw';

$termData = json_decode((string) file_get_contents($rawDir . '/term_2610.json'), true, 512, JSON_THROW_ON_ERROR);
$coursesData = json_decode((string) file_get_contents($rawDir . '/courses_lec.json'), true, 512, JSON_THROW_ON_ERROR);
$scheduleData = json_decode((string) file_get_contents($rawDir . '/schedule_oct2026.json'), true, 512, JSON_THROW_ON_ERROR);
$session7Data = json_decode((string) file_get_contents($rawDir . '/session7_materials.json'), true, 512, JSON_THROW_ON_ERROR);
$usersData = json_decode((string) file_get_contents($rawDir . '/seed_users.json'), true, 512, JSON_THROW_ON_ERROR);

$pdo = Database::getConnection(true);

function makeArgonHash(string $password): string
{
    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 1,
    ]);
}

function generateRandomPassword(): string
{
    return 'Lms#' . bin2hex(random_bytes(6)) . '!9A';
}

$printedCredentials = [];
$forceResetPasswords = in_array('--reset-passwords', $argv ?? [], true);

// Ensure forum_threads has session_id column so each session (1..6) has its own isolated forum
$hasSessionCol = $pdo->query("SHOW COLUMNS FROM forum_threads LIKE 'session_id'")->fetch(PDO::FETCH_ASSOC);
if (!$hasSessionCol) {
    $pdo->exec("ALTER TABLE forum_threads ADD COLUMN session_id BIGINT UNSIGNED NULL AFTER class_id, ADD INDEX idx_forum_thread_session (session_id)");
}

try {
    $pdo->beginTransaction();

    // 1. Academic Period (Term 2610)
    $term = $termData['term'];
    $stmtPeriod = $pdo->prepare("
        INSERT INTO academic_periods (code, name, is_active, start_date, end_date)
        VALUES (:code, :name, :is_active, :start_date, :end_date)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            is_active = VALUES(is_active),
            start_date = VALUES(start_date),
            end_date = VALUES(end_date)
    ");
    $stmtPeriod->execute([
        ':code' => $term['code'],
        ':name' => $term['name'],
        ':is_active' => (int) $term['is_active'],
        ':start_date' => $term['start_date'],
        ':end_date' => $term['end_date'],
    ]);
    $periodId = (int) $pdo->query("SELECT id FROM academic_periods WHERE code = '2610'")->fetchColumn();

    // 2. Seed 6 Core Users (3 Students, 2 Lecturers, 1 Student Support Officer)
    $userIdsByUsername = [];
    $allUsers = array_merge($usersData['core_accounts'], $usersData['pseudonymized_peers_seeds'] ?? []);

    $stmtUser = $pdo->prepare("
        INSERT INTO users (
            uuid, role, username, email, password_hash, full_name,
            student_id_alias, lecturer_code_alias, academic_title, totp_enabled, is_active
        ) VALUES (
            :uuid, :role, :username, :email, :password_hash, :full_name,
            :student_id_alias, :lecturer_code_alias, :academic_title, 0, 1
        )
        ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            email = VALUES(email),
            full_name = VALUES(full_name),
            academic_title = VALUES(academic_title),
            totp_enabled = 0,
            is_active = 1
    ");

    foreach ($allUsers as $u) {
        $username = $u['username'];
        $role = $u['role'];
        $email = $u['email'] ?? PrivacyService::pseudonymizeEmail($username, $role);
        $plainPass = generateRandomPassword();
        $hash = makeArgonHash($plainPass);
        $fullName = PrivacyService::pseudonymizeName($u['seed_key'], $role);
        $studentAlias = $role === 'student' ? PrivacyService::pseudonymizeStudentAlias($u['seed_key']) : null;
        $lecturerAlias = $role === 'lecturer' ? PrivacyService::pseudonymizeLecturerAlias($u['seed_key']) : null;

        $stmtUser->execute([
            ':uuid' => $u['uuid'],
            ':role' => $role,
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => $hash,
            ':full_name' => $fullName,
            ':student_id_alias' => $studentAlias,
            ':lecturer_code_alias' => $lecturerAlias,
            ':academic_title' => $u['academic_title'] ?? null,
        ]);

        if ($forceResetPasswords) {
            $pdo->prepare("UPDATE users SET password_hash = :h, failed_login_attempts = 0, locked_until = NULL WHERE uuid = :u")
                ->execute([':h' => $hash, ':u' => $u['uuid']]);
        }

        $uidStmt = $pdo->prepare("SELECT id FROM users WHERE uuid = :u");
        $uidStmt->execute([':u' => $u['uuid']]);
        $userIdsByUsername[$username] = (int) $uidStmt->fetchColumn();

        $printedCredentials[] = [
            'role' => $role === 'operator' ? 'SUPPORT OFFICER' : strtoupper($role),
            'email' => $email,
            'username' => $username,
            'password' => $plainPass,
            'pseudonym' => $fullName,
        ];
    }

    // Deactivate any old peer accounts not in the 6 core accounts
    $activeUuids = array_map(static fn(array $u): string => $u['uuid'], $allUsers);
    $placeholders = implode(',', array_fill(0, count($activeUuids), '?'));
    $pdo->prepare("UPDATE users SET is_active = 0 WHERE uuid NOT IN ({$placeholders})")->execute($activeUuids);

    $student1Id = $userIdsByUsername['student1'];
    $student2Id = $userIdsByUsername['student2'];
    $student3Id = $userIdsByUsername['student3'];
    $lecturer1Id = $userIdsByUsername['lecturer1'];
    $lecturer2Id = $userIdsByUsername['lecturer2'];
    $operatorId = $userIdsByUsername['operator'];

    // Remove courses not in the 4 selected courses
    $selectedCodes = array_map(static fn(array $c): string => $c['code'], $coursesData['courses']);
    $codePlaceholders = implode(',', array_fill(0, count($selectedCodes), '?'));
    $stmtOldCourses = $pdo->prepare("SELECT id FROM courses WHERE code NOT IN ({$codePlaceholders})");
    $stmtOldCourses->execute($selectedCodes);
    $oldCourseIds = $stmtOldCourses->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($oldCourseIds)) {
        $cidPlaceholders = implode(',', array_fill(0, count($oldCourseIds), '?'));
        $stmtOldClasses = $pdo->prepare("SELECT id FROM classes WHERE course_id IN ({$cidPlaceholders})");
        $stmtOldClasses->execute($oldCourseIds);
        $oldClassIds = $stmtOldClasses->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($oldClassIds)) {
            $clidPlaceholders = implode(',', array_fill(0, count($oldClassIds), '?'));
            $pdo->prepare("DELETE sg FROM student_grades sg JOIN grade_components gc ON gc.id = sg.component_id WHERE gc.class_id IN ({$clidPlaceholders})")->execute($oldClassIds);
            $pdo->prepare("DELETE gm FROM group_members gm JOIN student_groups grp ON grp.id = gm.group_id JOIN grouping_sets gs ON gs.id = grp.grouping_set_id WHERE gs.class_id IN ({$clidPlaceholders})")->execute($oldClassIds);
            $pdo->prepare("DELETE grp FROM student_groups grp JOIN grouping_sets gs ON gs.id = grp.grouping_set_id WHERE gs.class_id IN ({$clidPlaceholders})")->execute($oldClassIds);
            $pdo->prepare("DELETE sa FROM session_attendance sa JOIN course_sessions cs ON cs.id = sa.session_id WHERE cs.class_id IN ({$clidPlaceholders})")->execute($oldClassIds);
            $pdo->prepare("DELETE fp FROM forum_posts fp JOIN forum_threads ft ON ft.id = fp.thread_id WHERE ft.class_id IN ({$clidPlaceholders})")->execute($oldClassIds);
            foreach ([
                'schedule_entries',
                'assignments',
                'forum_threads',
                'grade_components',
                'grouping_sets',
                'course_sessions',
                'course_enrollments',
                'class_lecturers',
            ] as $tbl) {
                $pdo->prepare("DELETE FROM {$tbl} WHERE class_id IN ({$clidPlaceholders})")->execute($oldClassIds);
            }
            $pdo->prepare("DELETE FROM classes WHERE id IN ({$clidPlaceholders})")->execute($oldClassIds);
        }
        $pdo->prepare("DELETE FROM courses WHERE id IN ({$cidPlaceholders})")->execute($oldCourseIds);
    }

    // 3. Seed 4 LEC Courses & Classes for Term 2610 (2 per Lecturer)
    $classIdsByCourseCode = [];
    $courseIdsByCode = [];

    $stmtCourse = $pdo->prepare("
        INSERT INTO courses (uuid, code, name, credits_lecture, credits_lab, category)
        VALUES (:uuid, :code, :name, :credits_lecture, :credits_lab, :category)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            credits_lecture = VALUES(credits_lecture),
            category = VALUES(category)
    ");

    $stmtClass = $pdo->prepare("
        INSERT INTO classes (uuid, course_id, academic_period_id, class_code, delivery_type, class_progress_pct)
        VALUES (:uuid, :course_id, :period_id, :class_code, :delivery_type, :progress)
        ON DUPLICATE KEY UPDATE
            class_progress_pct = VALUES(class_progress_pct)
    ");

    $stmtEnroll = $pdo->prepare("
        INSERT INTO course_enrollments (user_id, class_id, status)
        VALUES (:user_id, :class_id, 'enrolled')
        ON DUPLICATE KEY UPDATE status = 'enrolled'
    ");

    $stmtAssignLecturer = $pdo->prepare("
        INSERT INTO class_lecturers (user_id, class_id, role)
        VALUES (:user_id, :class_id, :role)
        ON DUPLICATE KEY UPDATE role = VALUES(role)
    ");

    $pdo->exec("DELETE FROM course_enrollments");
    $pdo->exec("DELETE FROM class_lecturers");

    foreach ($coursesData['courses'] as $idx => $c) {
        $stmtCourse->execute([
            ':uuid' => $c['uuid'],
            ':code' => $c['code'],
            ':name' => $c['name'],
            ':credits_lecture' => (int) $c['credits_lecture'],
            ':credits_lab' => (int) $c['credits_lab'],
            ':category' => $c['category'],
        ]);

        $cIdStmt = $pdo->prepare("SELECT id FROM courses WHERE code = :code");
        $cIdStmt->execute([':code' => $c['code']]);
        $courseId = (int) $cIdStmt->fetchColumn();
        $courseIdsByCode[$c['code']] = $courseId;

        $classUuid = sprintf('b107cbfd-a934-416d-bcce-d49d068bc0%02d', $idx + 1);
        $stmtClass->execute([
            ':uuid' => $classUuid,
            ':course_id' => $courseId,
            ':period_id' => $periodId,
            ':class_code' => $c['class_code'],
            ':delivery_type' => $c['delivery_type'],
            ':progress' => (int) $c['class_progress_pct'],
        ]);

        $clsStmt = $pdo->prepare("SELECT id FROM classes WHERE course_id = :cid AND academic_period_id = :pid AND class_code = :cc AND delivery_type = 'LEC'");
        $clsStmt->execute([':cid' => $courseId, ':pid' => $periodId, ':cc' => $c['class_code']]);
        $classId = (int) $clsStmt->fetchColumn();
        $classIdsByCourseCode[$c['code']] = $classId;

        // Enroll all 3 students in the 4 courses
        foreach (['student1', 'student2', 'student3'] as $stuUser) {
            $stmtEnroll->execute([
                ':user_id' => $userIdsByUsername[$stuUser],
                ':class_id' => $classId,
            ]);
        }

        // Assign the responsible lecturer (2 courses for lecturer1, 2 courses for lecturer2)
        $pdo->prepare("DELETE FROM class_lecturers WHERE class_id = :clid")->execute([':clid' => $classId]);
        $lecUsername = $c['lecturer_username'] ?? 'lecturer1';
        $responsibleLecturerId = $userIdsByUsername[$lecUsername] ?? $lecturer1Id;
        $stmtAssignLecturer->execute([
            ':user_id' => $responsibleLecturerId,
            ':class_id' => $classId,
            ':role' => 'lead_lecturer',
        ]);
    }

    // Also create 1 LAB class for COMP6800001 to verify UI strictly skips LAB and shows LEC only
    $stmtClass->execute([
        ':uuid' => 'b999cbfd-a934-416d-bcce-d49d068bc099',
        ':course_id' => $courseIdsByCode['COMP6800001'],
        ':period_id' => $periodId,
        ':class_code' => 'LB07',
        ':delivery_type' => 'LAB',
        ':progress' => 25,
    ]);

    // 4. Clean up any old sessions and seed Sessions 1..6 per course with rich content through November 30, 2026
    $pdo->exec("DELETE FROM schedule_entries");
    $pdo->exec("DELETE FROM attendance_excuse_requests");
    $pdo->exec("DELETE FROM session_attendance");
    $pdo->exec("DELETE FROM session_materials");
    $pdo->exec("DELETE FROM session_sub_topics");
    $pdo->exec("DELETE FROM session_learning_outcomes");
    $pdo->exec("DELETE FROM course_sessions");

    $sessionCurricula = [
        'COMP6695001' => [
            1 => [
                'title' => 'Session 1: Review I — Secure Web Architecture & Input Handling',
                'start' => '2026-10-19 09:20:00', 'end' => '2026-10-19 11:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 605',
                'outcomes' => $session7Data['session']['learning_outcomes'],
                'sub_topics' => $session7Data['session']['sub_topics'],
                'materials' => $session7Data['session']['materials'],
            ],
            2 => [
                'title' => 'Session 2: Authentication, Argon2id Hashing & Session Hardening',
                'start' => '2026-10-26 09:20:00', 'end' => '2026-10-26 11:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 605',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Implement Argon2id password hashing and anti-enumeration defenses', 'sort_order' => 1],
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Analyze session fixation, hijacking, and cookie security attributes', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'Password Storage with Argon2id (m=64MB, t=4)',
                    'Account Lockout & Exponential Rate Limiting',
                    'Session ID Regeneration on Privilege Change',
                    'HttpOnly, Secure, and SameSite Cookie Flags',
                    'Server-Side Session Revocation & Active Device Tracking',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'OWASP Authentication Cheat Sheet', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'Argon2id Tuning & Memory Hardening Slide Deck', 'material_type' => 'slide', 'duration_label' => '20m', 'duration_minutes' => 20],
                    ['title' => 'Session Fixation & Cookie Hardening Lab Guide', 'material_type' => 'link', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'Anti-Enumeration Timing Side-Channel Mitigation', 'material_type' => 'cheat_sheet', 'duration_label' => '10m', 'duration_minutes' => 10],
                ],
            ],
            3 => [
                'title' => 'Session 3: RBAC, BOLA/IDOR Defense, CSRF & Content Security Policy',
                'start' => '2026-11-02 09:20:00', 'end' => '2026-11-02 11:00:00',
                'entry_type' => 'virtual_class', 'badge' => 'Virtual Class', 'room' => 'Zoom Virtual Room A',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Enforce deny-by-default RBAC and object-level BOLA/IDOR authorization checks', 'sort_order' => 1],
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Mitigate Stored/Reflected XSS and CSRF using per-request nonces and HMAC tokens', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'Deny-by-Default Access Control & Separation of Duties',
                    'Broken Object Level Authorization (BOLA / IDOR) Prevention',
                    'Cryptographic Synchronizer CSRF Tokens (hash_equals)',
                    'Contextual Output Escaping (HTML, Attr, JS, URL)',
                    'Strict Per-Request Nonce Content Security Policy (CSP)',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'OWASP Authorization & BOLA Prevention Guide', 'material_type' => 'cheat_sheet', 'duration_label' => '20m', 'duration_minutes' => 20],
                    ['title' => 'CSRF Synchronizer Token Pattern Slides', 'material_type' => 'slide', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'Strict CSP Level 3 Nonce Deployment Reference', 'material_type' => 'link', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'Safe File Upload & Finfo MIME Sniffing Lab', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            4 => [
                'title' => 'Session 4: SQL Injection Defense, Prepared Statements & SSRF Mitigation',
                'start' => '2026-11-09 09:20:00', 'end' => '2026-11-09 11:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 605',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Eliminate SQL injection vectors using strict PDO prepared statements and identifier allowlists', 'sort_order' => 1],
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Defend backend services against Server-Side Request Forgery (SSRF) and XML External Entity (XXE) attacks', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'PDO Native Prepared Statements (ATTR_EMULATE_PREPARES = false)',
                    'Dynamic ORDER BY & Column Allowlisting',
                    'Server-Side Request Forgery (SSRF) Egress Filtering & DNS Pinning',
                    'Least-Privilege Database Accounts & Read-Only Containers',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'SQL Injection & Query Parameterization Slides', 'material_type' => 'slide', 'duration_label' => '20m', 'duration_minutes' => 20],
                    ['title' => 'OWASP SSRF Prevention Cheat Sheet', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            5 => [
                'title' => 'Session 5: Mid-Semester Secure Code Audit & Penetration Testing Exam',
                'start' => '2026-11-16 09:20:00', 'end' => '2026-11-16 11:00:00',
                'entry_type' => 'onsite_exam', 'badge' => 'Onsite Exam', 'room' => 'Anggrek - 605',
                'outcomes' => [
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Identify and patch OWASP Top 10 vulnerabilities under timed exam conditions', 'sort_order' => 1],
                ],
                'sub_topics' => [
                    'Practical Vulnerability Triage & Code Review',
                    'Exploit Proof-of-Concept & Defensive Patch Verification',
                    'Automated Security Regression Testing with PHPUnit',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'OWASP ASVS L2 Verification Checklist', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            6 => [
                'title' => 'Session 6: Applied Cryptography, TLS 1.3 Hardening & Tamper-Evident Audit Logging',
                'start' => '2026-11-30 09:20:00', 'end' => '2026-11-30 11:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 605',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Configure TLS 1.2/1.3 cipher suites, HSTS, and authenticated encryption (AES-256-GCM)', 'sort_order' => 1],
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Design append-only, immutable security audit trails for incident response', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'TLS 1.3 Handshake, AEAD Cipher Suites & HSTS Preloading',
                    'Authenticated Encryption with Associated Data (AES-GCM / ChaCha20-Poly1305)',
                    'Append-Only Database Triggers for Immutable Security Audit Logs',
                    'Final Project Security Architecture Review (Nov 30 Milestone)',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Applied Cryptography & TLS 1.3 Slides', 'material_type' => 'slide', 'duration_label' => '25m', 'duration_minutes' => 25],
                    ['title' => 'Cryptographic Key Management Cheat Sheet', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
        ],
        'COMP6646001' => [
            1 => [
                'title' => 'Session 1: Digital Evidence Acquisition & Chain of Custody',
                'start' => '2026-10-21 11:20:00', 'end' => '2026-10-21 13:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 608',
                'outcomes' => [
                    ['bloom_level' => '(C2)', 'bloom_category' => 'Comprehension', 'description' => 'Explain forensic soundness and chain of custody documentation standards', 'sort_order' => 1],
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Acquire bit-stream disk images using hardware write-blockers and SHA-256 verification', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'Principles of Digital Evidence Admissibility (ISO/IEC 27037)',
                    'Chain of Custody Logs & Cryptographic Integrity Hashing',
                    'Bit-Stream Disk Imaging (dd, dcfldd, FTK Imager)',
                    'Hardware & Software Write Blockers',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'ISO/IEC 27037 Digital Evidence Handling Slides', 'material_type' => 'slide', 'duration_label' => '20m', 'duration_minutes' => 20],
                    ['title' => 'Bit-Stream Disk Imaging & SHA-256 Verification Cheat Sheet', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'Chain of Custody Form Template & Case Study', 'material_type' => 'link', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            2 => [
                'title' => 'Session 2: File System Forensics (NTFS, EXT4 & Deleted File Recovery)',
                'start' => '2026-10-28 11:20:00', 'end' => '2026-10-28 13:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 608',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Recover deleted artifacts from Master File Table ($MFT) and journaled inodes', 'sort_order' => 1],
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Analyze file slack space, MACB timestamps, and anti-forensic timestomping', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'NTFS Master File Table ($MFT) & Resident vs Non-Resident Attributes',
                    'EXT4 Inode Tables, Superblocks, and Journal Recovery',
                    'File Carving from Unallocated Clusters (Scalpel / Foremost)',
                    'Detecting Timestomping ($STANDARD_INFORMATION vs $FILE_NAME)',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'NTFS & $MFT Deep Dive Presentation', 'material_type' => 'slide', 'duration_label' => '25m', 'duration_minutes' => 25],
                    ['title' => 'The Sleuth Kit (TSK) & Autopsy Command Reference', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'File Header / Magic Byte Carving Signatures', 'material_type' => 'link', 'duration_label' => '10m', 'duration_minutes' => 10],
                ],
            ],
            3 => [
                'title' => 'Session 3: Volatile Memory Forensics & Network Packet Reconstruction',
                'start' => '2026-11-04 11:20:00', 'end' => '2026-11-04 13:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 608',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Extract running processes, injected DLLs, and sockets from RAM dumps using Volatility 3', 'sort_order' => 1],
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Reconstruct attacker lateral movement from PCAP network captures', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'Order of Volatility (RFC 3227) & Live RAM Acquisition',
                    'Volatility 3 Process Tree, VadInfo & Malfind Analysis',
                    'Windows Registry Hive Forensics (SAM, SYSTEM, NTUSER.DAT)',
                    'PCAP Stream Reconstruction & C2 Beacon Triage',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Volatility 3 Memory Forensics Slide Deck', 'material_type' => 'slide', 'duration_label' => '20m', 'duration_minutes' => 20],
                    ['title' => 'Volatility 3 Plugin Quick Reference Cheat Sheet', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'Wireshark Forensic PCAP Carving Guide', 'material_type' => 'link', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            4 => [
                'title' => 'Session 4: Windows Event Log (EVTX) & Registry Persistence Triage',
                'start' => '2026-11-11 11:20:00', 'end' => '2026-11-11 13:00:00',
                'entry_type' => 'virtual_class', 'badge' => 'Virtual Class', 'room' => 'Forensic Virtual Lab',
                'outcomes' => [
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Correlate Windows Security Event IDs (4624, 4625, 4688, 7045) to timeline compromises', 'sort_order' => 1],
                ],
                'sub_topics' => [
                    'Windows EVTX Log Hunting with Chainsaw & Hayabusa',
                    'Shimcache, Amcache, and Prefetch Execution Artifacts',
                    'Scheduled Tasks & Run Key Registry Persistence',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Windows Artifact Timeline Analysis Slides', 'material_type' => 'slide', 'duration_label' => '20m', 'duration_minutes' => 20],
                ],
            ],
            5 => [
                'title' => 'Session 5: Practical Disk & Memory Forensics Examination',
                'start' => '2026-11-18 11:20:00', 'end' => '2026-11-18 13:00:00',
                'entry_type' => 'onsite_exam', 'badge' => 'Onsite Exam', 'room' => 'Anggrek - 608',
                'outcomes' => [
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Produce a court-admissible forensic investigation report from an incident image', 'sort_order' => 1],
                ],
                'sub_topics' => [
                    'Live Evidence Hash Verification',
                    'Root Cause & Attacker Timeline Reconstruction',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Forensic Exam Submission Guidelines', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            6 => [
                'title' => 'Session 6: Cloud, Container & Linux Server Incident Forensics',
                'start' => '2026-11-25 11:20:00', 'end' => '2026-11-25 13:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 608',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Investigate compromised Docker containers, systemd journals, and cloud audit logs', 'sort_order' => 1],
                ],
                'sub_topics' => [
                    'Docker OverlayFS Diff Inspection & Container Checkpointing',
                    'Linux auditd, journald, and /proc Unlinked Binary Recovery',
                    'CloudTrail / IAM Credential Compromise Investigation',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Linux & Container Forensics Slide Deck', 'material_type' => 'slide', 'duration_label' => '25m', 'duration_minutes' => 25],
                ],
            ],
        ],
        'COMP6800001' => [
            1 => [
                'title' => 'Session 1: Human-Centered Design & Cognitive Psychology Foundations',
                'start' => '2026-10-20 13:20:00', 'end' => '2026-10-20 15:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 413',
                'outcomes' => [
                    ['bloom_level' => '(C2)', 'bloom_category' => 'Comprehension', 'description' => 'Explain Norman\'s Gulf of Execution, Gulf of Evaluation, and mental models', 'sort_order' => 1],
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Apply Fitts\'s Law and Hick\'s Law to interactive interface layout design', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'Affordances, Signifiers, Constraints, and Mappings',
                    'Human Memory Limits, Attention & Cognitive Load',
                    'Fitts\'s Law & Hick-Hyman Decision Latency',
                    'Neo-Brutalist High-Contrast Visual Hierarchy',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Cognitive Psychology in UI Design Slides', 'material_type' => 'slide', 'duration_label' => '20m', 'duration_minutes' => 20],
                    ['title' => 'Laws of UX Reference Cheat Sheet', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'Contextual Inquiry & User Persona Workshop', 'material_type' => 'link', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            2 => [
                'title' => 'Session 2: Prototyping, Information Architecture & WCAG 2.1 AA Accessibility',
                'start' => '2026-10-27 13:20:00', 'end' => '2026-10-27 15:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 413',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Design keyboard-navigable, high-contrast interfaces meeting WCAG 2.1 AA standards', 'sort_order' => 1],
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Validate information architecture using card sorting and tree testing', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'Information Architecture & 3-Menu Navigation Discipline',
                    'Low-Fidelity Wireframing vs High-Fidelity Interactive Prototypes',
                    'WCAG 2.1 AA Contrast Ratios (4.5:1+), Focus Rings & Semantic ARIA',
                    'Inclusive Design for Screen Readers & Keyboard-Only Users',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'WCAG 2.1 AA Accessibility Engineering Slides', 'material_type' => 'slide', 'duration_label' => '20m', 'duration_minutes' => 20],
                    ['title' => 'Semantic HTML & ARIA Landmarks Cheat Sheet', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'Card Sorting & Navigation Tree Evaluation Guide', 'material_type' => 'link', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            3 => [
                'title' => 'Session 3: Nielsen\'s 10 Usability Heuristics & Quantitative Usability Testing',
                'start' => '2026-11-03 13:20:00', 'end' => '2026-11-03 15:00:00',
                'entry_type' => 'online', 'badge' => 'Online', 'room' => 'LMS Async Studio',
                'outcomes' => [
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Conduct a systematic heuristic evaluation and severity rating audit', 'sort_order' => 1],
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Measure task completion rate, time-on-task, and System Usability Scale (SUS)', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'Jakob Nielsen\'s 10 Usability Heuristics for User Interface Design',
                    'Severity Ratings (0 to 4) & Heuristic Audit Reporting',
                    'Think-Aloud Usability Testing Protocol',
                    'System Usability Scale (SUS) Scoring & Error Recovery Design',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Nielsen\'s 10 Heuristics & Severity Scale Slides', 'material_type' => 'slide', 'duration_label' => '20m', 'duration_minutes' => 20],
                    ['title' => 'Heuristic Evaluation Audit Checklist', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'System Usability Scale (SUS) Calculation Sheet', 'material_type' => 'link', 'duration_label' => '10m', 'duration_minutes' => 10],
                ],
            ],
            4 => [
                'title' => 'Session 4: Design Systems, Typography Hierarchy & Micro-Interactions',
                'start' => '2026-11-10 13:20:00', 'end' => '2026-11-10 15:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 413',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Build reusable design tokens, dual-font pairings, and accessible dark/light themes', 'sort_order' => 1],
                ],
                'sub_topics' => [
                    'Design Tokens & CSS Custom Property Architecture',
                    'Editorial Serif vs Geometric Sans-Serif Pairing',
                    'Tactile Feedback, Focus States & Reduced-Motion Preferences',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Design Systems & Typography Pairing Slides', 'material_type' => 'slide', 'duration_label' => '20m', 'duration_minutes' => 20],
                ],
            ],
            5 => [
                'title' => 'Session 5: Interactive Prototype Critique & Eye-Tracking Walkthrough',
                'start' => '2026-11-17 13:20:00', 'end' => '2026-11-17 15:00:00',
                'entry_type' => 'virtual_class', 'badge' => 'Virtual Class', 'room' => 'Zoom HCI Studio',
                'outcomes' => [
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Analyze F-pattern and Z-pattern visual scan paths on complex web dashboards', 'sort_order' => 1],
                ],
                'sub_topics' => [
                    'Visual Scan Paths, Fixation Heatmaps & Saccades',
                    'Reducing Form Friction & Error Prevention Patterns',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Eye-Tracking & Visual Attention Guide', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            6 => [
                'title' => 'Session 6: Final Usability Testing Presentation & SUS Benchmark Review',
                'start' => '2026-11-24 13:20:00', 'end' => '2026-11-24 15:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Anggrek - 413',
                'outcomes' => [
                    ['bloom_level' => '(C5)', 'bloom_category' => 'Evaluation', 'description' => 'Synthesize quantitative usability metrics and qualitative user feedback into iteration plans', 'sort_order' => 1],
                ],
                'sub_topics' => [
                    'Presenting Usability Findings to Engineering Teams',
                    'Prioritizing UX Fixes by Severity and Impact',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Final UX Portfolio Rubric', 'material_type' => 'slide', 'duration_label' => '20m', 'duration_minutes' => 20],
                ],
            ],
        ],
        'COMP6843001' => [
            1 => [
                'title' => 'Session 1: x86-64 Calling Conventions, ELF Internals & Static Disassembly',
                'start' => '2026-10-22 15:20:00', 'end' => '2026-10-22 17:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Syahdan - K2D',
                'outcomes' => [
                    ['bloom_level' => '(C2)', 'bloom_category' => 'Comprehension', 'description' => 'Explain ELF64 headers, GOT/PLT relocation tables, and System V AMD64 ABI registers', 'sort_order' => 1],
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Reverse engineer stripped C binaries using Ghidra and control-flow graph recovery', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'System V AMD64 ABI (RDI, RSI, RDX, RCX, R8, R9 & Stack Frame RBP/RSP)',
                    'ELF64 Segments, Sections (.text, .rodata, .bss, .got.plt)',
                    'Static Analysis & Decompilation with Ghidra / IDA',
                    'Identifying Loops, Switch Tables, and Struct Offsets in Assembly',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'x86-64 Assembly & ELF64 Architecture Slides', 'material_type' => 'slide', 'duration_label' => '25m', 'duration_minutes' => 25],
                    ['title' => 'System V AMD64 Register & Calling Convention Cheat Sheet', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'Ghidra Decompiler & Symbol Recovery Walkthrough', 'material_type' => 'link', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            2 => [
                'title' => 'Session 2: Stack Buffer Overflows, Canary Bypass & Return-Oriented Programming (ROP)',
                'start' => '2026-10-29 15:20:00', 'end' => '2026-10-29 17:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Syahdan - K2D',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Construct ROP chains to bypass NX/DEP non-executable stack protections', 'sort_order' => 1],
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Analyze binary mitigations (Stack Canaries, PIE, RELRO, ASLR) using checksec', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'Stack Frame Corruption & Saved RIP Control',
                    'Data Execution Prevention (NX/DEP) & ret2libc Technique',
                    'Return-Oriented Programming (ROP Gadgets & Chain Construction)',
                    'Stack Canaries, Position Independent Executables (PIE) & Full RELRO',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'NX Bypass & Return-Oriented Programming (ROP) Slides', 'material_type' => 'slide', 'duration_label' => '25m', 'duration_minutes' => 25],
                    ['title' => 'Pwntools & ROPgadget Exploitation Cheat Sheet', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'GDB-Pwanda / GEF Dynamic Debugging Lab', 'material_type' => 'link', 'duration_label' => '15m', 'duration_minutes' => 15],
                ],
            ],
            3 => [
                'title' => 'Session 3: Format String Vulnerabilities & glibc Heap Exploitation (tcache / Use-After-Free)',
                'start' => '2026-11-05 15:20:00', 'end' => '2026-11-05 17:00:00',
                'entry_type' => 'virtual_class', 'badge' => 'Virtual Class', 'room' => 'Zoom Binary Lab',
                'outcomes' => [
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Audit C/C++ source and binaries for arbitrary read/write format string specifiers', 'sort_order' => 1],
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Diagnose glibc ptmalloc2 heap metadata corruption, Use-After-Free, and double-free bugs', 'sort_order' => 2],
                ],
                'sub_topics' => [
                    'Format String Arbitrary Stack Read (%p) & Arbitrary Write (%n)',
                    'glibc ptmalloc2 Chunk Headers, Arenas, and Tcache Bins',
                    'Use-After-Free (UAF) & Tcache Poisoning Mechanics',
                    'Defensive Memory Safe Patterns & Compiler Sanitizers (ASan / UBSan)',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'glibc Heap Internals & Use-After-Free Slides', 'material_type' => 'slide', 'duration_label' => '25m', 'duration_minutes' => 25],
                    ['title' => 'Format String & ptmalloc2 Heap Chunk Cheat Sheet', 'material_type' => 'cheat_sheet', 'duration_label' => '15m', 'duration_minutes' => 15],
                    ['title' => 'AddressSanitizer (ASan) Remediation Guide', 'material_type' => 'link', 'duration_label' => '10m', 'duration_minutes' => 10],
                ],
            ],
            4 => [
                'title' => 'Session 4: Kernel Exploitation Fundamentals, Seccomp Sandboxing & Syscall Filtering',
                'start' => '2026-11-12 15:20:00', 'end' => '2026-11-12 17:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Syahdan - K2D',
                'outcomes' => [
                    ['bloom_level' => '(C4)', 'bloom_category' => 'Analysis', 'description' => 'Analyze seccomp-bpf syscall filters, SMEP/SMAP/KASLR mitigations, and ioctl handlers', 'sort_order' => 1],
                ],
                'sub_topics' => [
                    'Linux seccomp-bpf Filters & Sandbox Escape Vectors',
                    'Kernel Space Protections (KASLR, SMEP, SMAP, KPTI)',
                    'Auditing Character Device ioctl() Copy-from-User Bugs',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Seccomp-BPF & Linux Kernel Mitigations Slides', 'material_type' => 'slide', 'duration_label' => '25m', 'duration_minutes' => 25],
                ],
            ],
            5 => [
                'title' => 'Session 5: Automated Coverage-Guided Fuzzing with AFL++ & LibFuzzer',
                'start' => '2026-11-19 15:20:00', 'end' => '2026-11-19 17:00:00',
                'entry_type' => 'online', 'badge' => 'Online', 'room' => 'LMS Fuzzing Cluster',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Write LibFuzzer harnesses and triage crash corpus inputs with ASan/UBSan', 'sort_order' => 1],
                ],
                'sub_topics' => [
                    'Coverage-Guided Mutation Fuzzing (AFL++ & LLVM LibFuzzer)',
                    'Writing Fuzz Targets for Parsers and Network Protocols',
                    'Crash Deduplication & Root-Cause Triage',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'AFL++ & LibFuzzer Harness Engineering Guide', 'material_type' => 'cheat_sheet', 'duration_label' => '20m', 'duration_minutes' => 20],
                ],
            ],
            6 => [
                'title' => 'Session 6: Symbolic Execution (angr) & Binary Patching Defense',
                'start' => '2026-11-26 15:20:00', 'end' => '2026-11-26 17:00:00',
                'entry_type' => 'onsite_class', 'badge' => 'Onsite Class', 'room' => 'Syahdan - K2D',
                'outcomes' => [
                    ['bloom_level' => '(C3)', 'bloom_category' => 'Application', 'description' => 'Solve path constraints using symbolic execution (angr / Z3) and apply binary hot-patches', 'sort_order' => 1],
                ],
                'sub_topics' => [
                    'Constraint Solving with Z3 & Symbolic Execution in angr',
                    'Avoiding Path Explosion in Complex Control Flow',
                    'ELF Binary Hardening & Patch Verification',
                ],
                'materials' => [
                    ['title' => 'Wifi Attendance', 'material_type' => 'attendance', 'duration_label' => '1h 40m', 'duration_minutes' => 100],
                    ['title' => 'Symbolic Execution with angr Slide Deck', 'material_type' => 'slide', 'duration_label' => '25m', 'duration_minutes' => 25],
                ],
            ],
        ],
    ];

    $stmtSession = $pdo->prepare("
        INSERT INTO course_sessions (
            uuid, class_id, session_number, title, delivery_mode, start_time, end_time, room, status, attendance_window_open
        ) VALUES (
            :uuid, :class_id, :session_number, :title, :delivery_mode, :start_time, :end_time, :room, :status, :att_open
        )
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            delivery_mode = VALUES(delivery_mode),
            start_time = VALUES(start_time),
            end_time = VALUES(end_time),
            room = VALUES(room),
            status = VALUES(status),
            attendance_window_open = VALUES(attendance_window_open)
    ");

    $stmtOutcome = $pdo->prepare("
        INSERT INTO session_learning_outcomes (session_id, bloom_level, bloom_category, description, sort_order)
        VALUES (:sid, :level, :cat, :desc, :ord)
    ");
    $stmtSubTopic = $pdo->prepare("
        INSERT INTO session_sub_topics (session_id, topic_name, sort_order)
        VALUES (:sid, :topic, :ord)
    ");
    $stmtMaterial = $pdo->prepare("
        INSERT INTO session_materials (session_id, title, material_type, duration_label, duration_minutes, url, sort_order)
        VALUES (:sid, :title, :type, :dlabel, :dmin, :url, :ord)
    ");

    $sessionIdsByClassAndNum = [];
    foreach ($coursesData['courses'] as $cIdx => $c) {
        $code = $c['code'];
        $courseId = $courseIdsByCode[$code];
        $classId = $classIdsByCourseCode[$code];
        $courseSessionsSpec = $sessionCurricula[$code];

        for ($sNum = 1; $sNum <= 6; $sNum++) {
            $spec = $courseSessionsSpec[$sNum];
            $isSecProgS1 = ($code === 'COMP6695001' && $sNum === 1);
            $sUuid = $isSecProgS1
                ? $session7Data['session']['uuid']
                : sprintf('405fdfcb-4748-4f2e-adbd-%06d%06d', $cIdx + 1, $sNum);
            $sMode = match ($spec['entry_type'] ?? 'onsite_class') {
                'virtual_class' => 'Virtual - GSLC / Zoom',
                'online' => 'Online - Async',
                'onsite_exam' => 'Onsite - Exam',
                default => 'Onsite - F2F',
            };
            $sRoom = $spec['room'] ?? ($c['class_code'] === 'LC07' ? 'Anggrek - 413' : 'Anggrek - 605');
            $sStart = $spec['start'];
            $sEnd = $spec['end'];

            $stmtSession->execute([
                ':uuid' => $sUuid,
                ':class_id' => $classId,
                ':session_number' => $sNum,
                ':title' => $spec['title'],
                ':delivery_mode' => $sMode,
                ':start_time' => $sStart,
                ':end_time' => $sEnd,
                ':room' => $sRoom,
                ':status' => $sNum < 2 ? 'completed' : ($sNum === 2 ? 'ongoing' : 'planned'),
                ':att_open' => $sNum === 2 ? 1 : 0,
            ]);

            $sIdStmt = $pdo->prepare("SELECT id FROM course_sessions WHERE class_id = :cid AND session_number = :sn");
            $sIdStmt->execute([':cid' => $classId, ':sn' => $sNum]);
            $sid = (int) $sIdStmt->fetchColumn();
            $sessionIdsByClassAndNum["{$code}:{$sNum}"] = $sid;

            foreach ($spec['outcomes'] as $lo) {
                $stmtOutcome->execute([
                    ':sid' => $sid,
                    ':level' => $lo['bloom_level'],
                    ':cat' => $lo['bloom_category'],
                    ':desc' => $lo['description'],
                    ':ord' => (int) $lo['sort_order'],
                ]);
            }

            foreach ($spec['sub_topics'] as $idx => $topic) {
                $stmtSubTopic->execute([
                    ':sid' => $sid,
                    ':topic' => $topic,
                    ':ord' => $idx + 1,
                ]);
            }

            foreach ($spec['materials'] as $idx => $mat) {
                $stmtMaterial->execute([
                    ':sid' => $sid,
                    ':title' => $mat['title'],
                    ':type' => $mat['material_type'],
                    ':dlabel' => $mat['duration_label'],
                    ':dmin' => (int) $mat['duration_minutes'],
                    ':url' => '/courses/' . $courseId . '?session=' . $sNum . '#material-' . ($idx + 1),
                    ':ord' => $idx + 1,
                ]);
            }
        }
    }

    // 5. Assignments (created by the assigned Lecturer, spanning October to November 30, 2026)
    $stmtAssignment = $pdo->prepare("
        INSERT INTO assignments (uuid, class_id, title, description, due_date, late_policy, max_score, created_by)
        VALUES (:uuid, :class_id, :title, :desc, :due, :late, 100.00, :creator)
        ON DUPLICATE KEY UPDATE
            class_id = VALUES(class_id),
            title = VALUES(title),
            description = VALUES(description),
            due_date = VALUES(due_date),
            created_by = VALUES(created_by)
    ");

    $seededAssignments = [
        [
            'uuid' => 'd1000000-0000-4000-8000-000000000001',
            'course_code' => 'COMP6695001',
            'title' => 'Assignment 1 : Secure LMS Architecture & OWASP ASVS L2 Audit',
            'desc' => 'Build and harden a Dockerized LAMP LMS compliant with OWASP ASVS L2 and Top 10 (2021).',
            'due' => '2026-10-26 23:59:00',
            'late' => 'Strict deadline — no late submissions without approved excuse',
            'creator' => $lecturer1Id,
        ],
        [
            'uuid' => 'd1000000-0000-4000-8000-000000000002',
            'course_code' => 'COMP6800001',
            'title' => 'Assignment 1 : Heuristic Usability Evaluation Report',
            'desc' => 'Submit your heuristic evaluation notes and usability findings for the Session 3 prototype.',
            'due' => '2026-11-06 23:59:00',
            'late' => '10% deduction per day late (max 3 days)',
            'creator' => $lecturer2Id,
        ],
        [
            'uuid' => 'd1000000-0000-4000-8000-000000000003',
            'course_code' => 'COMP6646001',
            'title' => 'Assignment 1 : Disk Image Chain of Custody & MFT Triage',
            'desc' => 'Submit a verified SHA-256 chain of custody log and $MFT deleted artifact recovery report.',
            'due' => '2026-11-13 23:59:00',
            'late' => 'Strict forensic submission window',
            'creator' => $lecturer1Id,
        ],
        [
            'uuid' => 'd1000000-0000-4000-8000-000000000004',
            'course_code' => 'COMP6843001',
            'title' => 'Assignment 1 : ELF64 Reverse Engineering & ROP Chain Analysis',
            'desc' => 'Decompile the stripped challenge binary in Ghidra and document the ROP gadget chain.',
            'due' => '2026-11-20 23:59:00',
            'late' => '10% deduction per day late (max 3 days)',
            'creator' => $lecturer2Id,
        ],
        [
            'uuid' => 'd1000000-0000-4000-8000-000000000005',
            'course_code' => 'COMP6695001',
            'title' => 'Assignment 2 : Final Group Penetration Test & Patch Report',
            'desc' => 'Submit your team penetration testing dossier, CVE remediation patches, and PHPUnit verification suite.',
            'due' => '2026-11-30 23:59:00',
            'late' => 'Final semester submission — hard cutoff on Nov 30, 23:59 GMT+7',
            'creator' => $lecturer1Id,
        ],
    ];

    $assignmentIdsByUuid = [];
    foreach ($seededAssignments as $aRow) {
        $stmtAssignment->execute([
            ':uuid' => $aRow['uuid'],
            ':class_id' => $classIdsByCourseCode[$aRow['course_code']],
            ':title' => $aRow['title'],
            ':desc' => $aRow['desc'],
            ':due' => $aRow['due'],
            ':late' => $aRow['late'],
            ':creator' => $aRow['creator'],
        ]);
        $assignmentIdsByUuid[$aRow['uuid']] = (int) $pdo->query("SELECT id FROM assignments WHERE uuid = " . $pdo->quote($aRow['uuid']))->fetchColumn();
    }

    // 6. Schedule Entries (Full Course Class Schedule + Exams + Assignments through November 30, 2026)
    $stmtSchedule = $pdo->prepare("
        INSERT INTO schedule_entries (
            uuid, class_id, session_id, assignment_id, entry_type, badge_label,
            title, start_time, end_time, timezone, room, seat_number, details
        ) VALUES (
            :uuid, :class_id, :session_id, :assignment_id, :entry_type, :badge_label,
            :title, :start_time, :end_time, 'Asia/Jakarta', :room, :seat_number, :details
        )
    ");

    $schedSeq = 1;
    foreach ($coursesData['courses'] as $c) {
        $cCode = $c['code'];
        $classId = $classIdsByCourseCode[$cCode];
        foreach ($sessionCurricula[$cCode] as $sNum => $spec) {
            $sessId = $sessionIdsByClassAndNum["{$cCode}:{$sNum}"] ?? null;
            $eType = $spec['entry_type'] ?? 'onsite_class';
            $seat = $eType === 'onsite_exam' ? sprintf('Seat %02d', 12 + $sNum) : null;
            $stmtSchedule->execute([
                ':uuid' => sprintf('60000000-0000-4000-8000-00000000%04d', $schedSeq++),
                ':class_id' => $classId,
                ':session_id' => $sessId,
                ':assignment_id' => null,
                ':entry_type' => $eType,
                ':badge_label' => $spec['badge'] ?? 'Onsite Class',
                ':title' => $spec['title'],
                ':start_time' => $spec['start'],
                ':end_time' => $spec['end'],
                ':room' => $spec['room'],
                ':seat_number' => $seat,
                ':details' => "Session {$sNum} ({$c['class_code']})",
            ]);
        }
    }

    foreach ($seededAssignments as $aRow) {
        $aId = $assignmentIdsByUuid[$aRow['uuid']] ?? null;
        $stmtSchedule->execute([
            ':uuid' => sprintf('60000000-0000-4000-8000-00000000%04d', $schedSeq++),
            ':class_id' => $classIdsByCourseCode[$aRow['course_code']],
            ':session_id' => null,
            ':assignment_id' => $aId,
            ':entry_type' => 'assignment',
            ':badge_label' => 'Assignment',
            ':title' => $aRow['title'],
            ':start_time' => $aRow['due'],
            ':end_time' => $aRow['due'],
            ':room' => 'LMS Portal Submission',
            ':seat_number' => null,
            ':details' => 'Assignment Due Date (GMT+7)',
        ]);
    }

    // 7. Forum Threads & Posts (Each Course Session 1..6 has its own separate Forum)
    $pdo->exec("DELETE FROM forum_post_reports");
    $pdo->exec("DELETE FROM forum_posts");
    $pdo->exec("DELETE FROM forum_threads");

    $stmtThread = $pdo->prepare("
        INSERT INTO forum_threads (uuid, class_id, session_id, author_id, title, is_pinned, is_locked, created_at)
        VALUES (:uuid, :class_id, :session_id, :author_id, :title, :is_pinned, 0, :created_at)
    ");

    $stmtPost = $pdo->prepare("
        INSERT INTO forum_posts (uuid, thread_id, author_id, body_raw, body_html, created_at)
        VALUES (:uuid, :thread_id, :author_id, :body_raw, :body_html, :created_at)
    ");

    $threadSeq = 1;
    foreach ($coursesData['courses'] as $c) {
        $cCode = $c['code'];
        $classId = $classIdsByCourseCode[$cCode];
        $authorId = ($cCode === 'COMP6800001' || $cCode === 'COMP6843001') ? $lecturer2Id : $lecturer1Id;

        foreach ($sessionCurricula[$cCode] as $sNum => $spec) {
            $sessId = $sessionIdsByClassAndNum["{$cCode}:{$sNum}"] ?? null;
            $tUuid = sprintf('f1000000-0000-4000-8000-00000000%04d', $threadSeq);
            $pUuid = sprintf('f2000000-0000-4000-8000-00000000%04d', $threadSeq);
            $threadSeq++;

            $threadTitle = "Session {$sNum} Discussion : {$spec['title']}";
            $subTopicSummary = implode(', ', $spec['sub_topics'] ?? []);
            $bodyRaw = "Welcome to the **Session {$sNum} Forum** for **{$c['name']} ({$cCode})**.\n\n"
                . "Please post your questions, technical analysis, and peer discussion for **{$spec['title']}** here.\n"
                . "Key sub-topics covered in this session: {$subTopicSummary}.";
            $safeHtml = \App\Security\Markdown::render($bodyRaw);
            $createdAt = date('Y-m-d H:i:s', min(time(), strtotime($spec['start'])));

            $stmtThread->execute([
                ':uuid' => $tUuid,
                ':class_id' => $classId,
                ':session_id' => $sessId,
                ':author_id' => $authorId,
                ':title' => $threadTitle,
                ':is_pinned' => $sNum === 1 ? 1 : 0,
                ':created_at' => $createdAt,
            ]);
            $threadId = (int) $pdo->lastInsertId();

            $stmtPost->execute([
                ':uuid' => $pUuid,
                ':thread_id' => $threadId,
                ':author_id' => $authorId,
                ':body_raw' => $bodyRaw,
                ':body_html' => $safeHtml,
                ':created_at' => $createdAt,
            ]);
        }
    }

    // 8. Grade Components & Published Student Grades (Strictly 3 components = 100.00% total weight per class)
    $pdo->exec("DELETE FROM grade_change_history");
    $pdo->exec("DELETE FROM student_grades");
    $pdo->exec("DELETE FROM grade_components");

    $stmtGradeComp = $pdo->prepare("
        INSERT INTO grade_components (uuid, class_id, name, weight_pct, is_locked, is_published, created_by)
        VALUES (:uuid, :class_id, :name, :weight, 0, 1, :creator)
    ");
    $stmtStuGrade = $pdo->prepare("
        INSERT INTO student_grades (component_id, student_id, score, notes, created_by)
        VALUES (:comp_id, :stu_id, :score, :notes, :creator)
    ");

    $componentsSpec = [
        ['suffix' => '01', 'name' => 'Assignment & Lab Quizzes', 'weight' => 30.00, 'score' => 92.50],
        ['suffix' => '02', 'name' => 'Mid Semester Exam (Theory)', 'weight' => 30.00, 'score' => 88.00],
        ['suffix' => '03', 'name' => 'Final Course Project', 'weight' => 40.00, 'score' => 95.00],
    ];

    foreach ($coursesData['courses'] as $cIdx => $c) {
        $classId = $classIdsByCourseCode[$c['code']];
        $lecUsername = $c['lecturer_username'] ?? 'lecturer1';
        $responsibleLecturerId = $userIdsByUsername[$lecUsername] ?? $lecturer1Id;

        foreach ($componentsSpec as $comp) {
            $compUuid = sprintf('91000000-0000-4000-8000-00000000%02d%s', $cIdx + 1, $comp['suffix']);
            $stmtGradeComp->execute([
                ':uuid' => $compUuid,
                ':class_id' => $classId,
                ':name' => $comp['name'],
                ':weight' => $comp['weight'],
                ':creator' => $responsibleLecturerId,
            ]);
            $compId = (int) $pdo->lastInsertId();

            foreach ([$student1Id, $student2Id, $student3Id] as $sIdx => $sId) {
                $stmtStuGrade->execute([
                    ':comp_id' => $compId,
                    ':stu_id' => $sId,
                    ':score' => min(100.0, max(75.0, $comp['score'] - ($sIdx * 3.5))),
                    ':notes' => 'Private lecturer evaluation note: strong security engineering methodology.',
                    ':creator' => $responsibleLecturerId,
                ]);
            }
        }
    }

    // 9. Grouping Sets & Student Groups (3 groups per course; max 1 group per student per course)
    $pdo->exec("DELETE FROM group_members");
    $pdo->exec("DELETE FROM student_groups");
    $pdo->exec("DELETE FROM grouping_sets");

    $stmtGroupSet = $pdo->prepare("
        INSERT INTO grouping_sets (uuid, class_id, name, min_members, max_members, window_open_at, window_close_at, is_self_formed, status, created_by)
        VALUES (:uuid, :class_id, :name, 2, 3, '2026-10-01 00:00:00', '2026-12-15 23:59:59', 1, 'open', :creator)
    ");
    $stmtGroup = $pdo->prepare("
        INSERT INTO student_groups (uuid, grouping_set_id, name, created_by, status, approved_by, approved_at)
        VALUES (:uuid, :set_id, :name, :creator, 'approved', :approver, CURRENT_TIMESTAMP)
    ");
    $stmtGroupMember = $pdo->prepare("
        INSERT INTO group_members (group_id, student_id, role)
        VALUES (:gid, :sid, :role)
    ");

    $groupBlueprints = [
        1 => ['name' => 'Group 1 — Cyber Sentinel Alpha', 'initial_leader' => $student1Id],
        2 => ['name' => 'Group 2 — ZeroTrust Bravo', 'initial_leader' => $student2Id],
        3 => ['name' => 'Group 3 — Kernel Shield Charlie', 'initial_leader' => null],
    ];

    foreach ($coursesData['courses'] as $cIdx => $c) {
        $classId = $classIdsByCourseCode[$c['code']];
        $lecUsername = $c['lecturer_username'] ?? 'lecturer1';
        $responsibleLecturerId = $userIdsByUsername[$lecUsername] ?? $lecturer1Id;

        $gsUuid = sprintf('71000000-0000-4000-8000-0000000000%02d', $cIdx + 1);
        $stmtGroupSet->execute([
            ':uuid' => $gsUuid,
            ':class_id' => $classId,
            ':name' => 'Semester Project Teams (' . $c['class_code'] . ')',
            ':creator' => $responsibleLecturerId,
        ]);
        $gsId = (int) $pdo->lastInsertId();

        foreach ($groupBlueprints as $gNum => $bp) {
            $grpUuid = sprintf('72000000-0000-4000-8000-00000000%02d%02d', $cIdx + 1, $gNum);
            $stmtGroup->execute([
                ':uuid' => $grpUuid,
                ':set_id' => $gsId,
                ':name' => $bp['name'],
                ':creator' => $bp['initial_leader'] ?? $responsibleLecturerId,
                ':approver' => $responsibleLecturerId,
            ]);
            $gId = (int) $pdo->lastInsertId();

            if ($bp['initial_leader'] !== null) {
                $stmtGroupMember->execute([
                    ':gid' => $gId,
                    ':sid' => $bp['initial_leader'],
                    ':role' => 'leader',
                ]);
            }
        }
    }

    // 10. Attendance Records — intentionally left empty (not predefined as 'present')
    // Lecturers can only mark attendance once the session date and time has started.

    // 11. Scrap support tickets & absent tickets completely
    $pdo->exec("DELETE FROM service_ticket_messages");
    $pdo->exec("DELETE FROM service_tickets");
    $pdo->exec("DELETE FROM attendance_excuse_requests");

    // 12. Append-Only Audit Log Entry for Seeder
    $stmtAudit = $pdo->prepare("
        INSERT INTO audit_logs (uuid, user_id, action, target_type, target_id, ip_address, user_agent, payload_json)
        VALUES (:uuid, :uid, 'system.seed.completed', 'academic_periods', '2610', '127.0.0.1', 'CLI/import_lms_data.php', :payload)
    ");
    $stmtAudit->execute([
        ':uuid' => sprintf(
            '%04x%04x-%04x-4%03x-%04x-%012x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0x8000, 0xbfff),
            random_int(0, 0xffffffffffff)
        ),
        ':uid' => $operatorId,
        ':payload' => json_encode(['term' => '2610', 'courses_seeded' => count($coursesData['courses'])]),
    ]);

    $pdo->commit();

    echo "========================================================================================\n";
    echo "     SECURE NEO-BRUTALIST LMS — OFFLINE IMPORTER & SEEDER (TERM 2610)                   \n";
    echo "========================================================================================\n";
    echo " [OK] Loaded Term 2610 ('2026, Odd Semester') & 4 LEC Courses (2 per Lecturer)\n";
    echo " [OK] Seeded Sessions 1, 2, 3 with complete Outcomes, Sub-Topics & Materials per Course\n";
    echo " [OK] Seeded 3 Groups per Course (1-Group-Per-Student Limit) & 100% Capped Gradebook\n";
    echo "========================================================================================\n";
    exit(0);
} catch (Throwable $e) {
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable) {
    }
    fwrite(STDERR, "[IMPORT ERROR] " . $e->getMessage() . " at line " . $e->getLine() . "\n");
    exit(1);
}
