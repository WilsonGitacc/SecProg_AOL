<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Session
{
    private const IDLE_TIMEOUT = 1800;      // 30 minutes
    private const ABSOLUTE_TIMEOUT = 28800; // 8 hours

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::enforceTimeouts();
            return;
        }

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            session_name('SECURE_LMS_SESSID');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        } elseif (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }

        self::enforceTimeouts();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        if (empty($_SESSION['csp_nonce'])) {
            $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
        }
    }

    private static function enforceTimeouts(): void
    {
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > self::IDLE_TIMEOUT) {
            self::destroy();
            return;
        }
        if (isset($_SESSION['created_at']) && ($now - (int) $_SESSION['created_at']) > self::ABSOLUTE_TIMEOUT) {
            self::destroy();
            return;
        }
        $_SESSION['last_activity'] = $now;
        if (!isset($_SESSION['created_at'])) {
            $_SESSION['created_at'] = $now;
        }
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['created_at'] = time();
        $_SESSION['last_activity'] = time();
    }

    public static function destroy(): void
    {
        if (!empty($_SESSION['db_session_hash'])) {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("UPDATE user_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([':id' => (string) $_SESSION['db_session_hash']]);
            } catch (\Throwable) {
            }
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_destroy();
        }
    }

    public static function getCsrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    public static function getCspNonce(): string
    {
        self::start();
        if (empty($_SESSION['csp_nonce'])) {
            $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
        }
        return (string) $_SESSION['csp_nonce'];
    }

    public static function flash(string $key, ?string $value = null): ?string
    {
        self::start();
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $msg = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return is_string($msg) ? $msg : null;
    }
}
