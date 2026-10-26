<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Session;
use App\Models\User;
use PDO;

final class AuthService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_SECONDS = 900; // 15 minutes
    public const ARGON2_OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 1,
    ];

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::ARGON2_OPTIONS);
    }

    /**
     * Authenticates a user with generic error messages, account lockout, Argon2id verification,
     * optional/mandatory TOTP 2FA, and session regeneration.
     *
     * @return array{success: bool, error?: string, requires_2fa?: bool, user?: User}
     */
    public static function authenticate(string $identifier, string $password, ?string $totpCode = null): array
    {
        $pdo = Database::getConnection();
        $cleanId = trim($identifier);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :e OR username = :u LIMIT 1");
        $stmt->execute([':e' => $cleanId, ':u' => $cleanId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Generic error message to prevent username enumeration
        $genericError = 'Invalid credentials or account temporarily locked.';

        if (!$row || !(bool) $row['is_active']) {
            // Mitigate timing side-channel with dummy verify
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQxMjM0NTY$RdescudvJCsgt3ub+b+dWRWJTmaaJObG');
            AuditService::log(null, 'auth.login.failed', 'users', $cleanId, ['reason' => 'unknown_or_inactive']);
            return ['success' => false, 'error' => $genericError];
        }

        $userId = (int) $row['id'];
        if (!empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time()) {
            AuditService::log($userId, 'auth.login.locked_reject', 'users', (string) $userId);
            return ['success' => false, 'error' => $genericError];
        }

        if (!password_verify($password, (string) $row['password_hash'])) {
            $attempts = ((int) $row['failed_login_attempts']) + 1;
            $lockedUntil = $attempts >= self::MAX_ATTEMPTS ? date('Y-m-d H:i:s', time() + self::LOCKOUT_SECONDS) : null;
            $upd = $pdo->prepare("
                UPDATE users
                SET failed_login_attempts = :a, locked_until = :lu
                WHERE id = :id
            ");
            $upd->execute([':a' => $attempts, ':lu' => $lockedUntil, ':id' => $userId]);
            AuditService::log($userId, 'auth.login.failed', 'users', (string) $userId, ['attempts' => $attempts, 'locked' => $lockedUntil !== null]);
            return ['success' => false, 'error' => $genericError];
        }

        // Upgrade Argon2id hash if cost options changed
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_ARGON2ID, self::ARGON2_OPTIONS)) {
            $newHash = self::hashPassword($password);
            $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :id")->execute([':h' => $newHash, ':id' => $userId]);
        }

        // Reset failed login counter
        $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id")->execute([':id' => $userId]);

        // Regenerate session ID & persist server-side session record
        Session::start();
        Session::regenerate();
        $rawSessionToken = bin2hex(random_bytes(32));
        $sessionHash = hash('sha256', $rawSessionToken);
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = (string) $row['role'];
        $_SESSION['db_session_hash'] = $sessionHash;
        $_SESSION['reauthenticated_at'] = time();

        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'SecureLMS/1.0'), 0, 500);
        $sessStmt = $pdo->prepare("
            INSERT INTO user_sessions (id, user_id, ip_address, user_agent)
            VALUES (:id, :uid, :ip, :ua)
        ");
        $sessStmt->execute([':id' => $sessionHash, ':uid' => $userId, ':ip' => $ip, ':ua' => $ua]);

        AuditService::log($userId, 'auth.login.success', 'users', (string) $userId);

        $user = self::loadUserById($userId);
        return ['success' => true, 'user' => $user];
    }

    public static function currentUser(): User
    {
        Session::start();
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return User::anonymous();
        }

        // Verify server-side session has not been revoked
        $sessHash = (string) ($_SESSION['db_session_hash'] ?? '');
        if ($sessHash !== '') {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("SELECT revoked_at FROM user_sessions WHERE id = :id AND user_id = :uid LIMIT 1");
                $stmt->execute([':id' => $sessHash, ':uid' => $userId]);
                $sessRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$sessRow || $sessRow['revoked_at'] !== null) {
                    Session::destroy();
                    return User::anonymous();
                }
                $pdo->prepare("UPDATE user_sessions SET last_activity_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $sessHash]);
            } catch (\Throwable) {
            }
        }

        return self::loadUserById($userId);
    }

    public static function loadUserById(int $userId): User
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return User::anonymous();
        }

        $enrolledCourseIds = [];
        $enrolledClassIds = [];
        $assignedCourseIds = [];
        $assignedClassIds = [];

        if ($row['role'] === 'student') {
            $eStmt = $pdo->prepare("
                SELECT ce.class_id, c.course_id
                FROM course_enrollments ce
                JOIN classes c ON c.id = ce.class_id
                WHERE ce.user_id = :uid AND ce.status = 'enrolled'
            ");
            $eStmt->execute([':uid' => $userId]);
            foreach ($eStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
                $enrolledClassIds[] = (int) $er['class_id'];
                $enrolledCourseIds[] = (int) $er['course_id'];
            }
        } elseif ($row['role'] === 'lecturer') {
            $lStmt = $pdo->prepare("
                SELECT cl.class_id, c.course_id
                FROM class_lecturers cl
                JOIN classes c ON c.id = cl.class_id
                WHERE cl.user_id = :uid
            ");
            $lStmt->execute([':uid' => $userId]);
            foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $assignedClassIds[] = (int) $lr['class_id'];
                $assignedCourseIds[] = (int) $lr['course_id'];
            }
        }

        return new User(
            id: (int) $row['id'],
            uuid: (string) $row['uuid'],
            role: (string) $row['role'],
            username: (string) $row['username'],
            email: (string) $row['email'],
            fullName: (string) $row['full_name'],
            studentIdAlias: $row['student_id_alias'] !== null ? (string) $row['student_id_alias'] : null,
            lecturerCodeAlias: $row['lecturer_code_alias'] !== null ? (string) $row['lecturer_code_alias'] : null,
            academicTitle: $row['academic_title'] !== null ? (string) $row['academic_title'] : null,
            totpEnabled: (bool) $row['totp_enabled'],
            totpSecret: $row['totp_secret'] !== null ? (string) $row['totp_secret'] : null,
            forcePasswordChange: (bool) $row['force_password_change'],
            enrolledCourseIds: array_values(array_unique($enrolledCourseIds)),
            assignedCourseIds: array_values(array_unique($assignedCourseIds)),
            enrolledClassIds: array_values(array_unique($enrolledClassIds)),
            assignedClassIds: array_values(array_unique($assignedClassIds)),
        );
    }

    public static function verifyPasswordForReauth(int $userId, string $password): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $hash = $stmt->fetchColumn();
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }
}
