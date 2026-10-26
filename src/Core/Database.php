<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;
    private static bool $envLoaded = false;

    public static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    if (str_contains($line, '=')) {
                        [$key, $val] = explode('=', $line, 2);
                        $key = trim($key);
                        $val = trim($val, " \t\n\r\0\x0B\"'");
                        if (getenv($key) === false) {
                            putenv("{$key}={$val}");
                            $_ENV[$key] = $val;
                        }
                    }
                }
            }
        }
        self::$envLoaded = true;
    }

    public static function env(string $key, string $default = ''): string
    {
        self::loadEnv();
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return (string) $val;
        }
        return isset($_ENV[$key]) && $_ENV[$key] !== '' ? (string) $_ENV[$key] : $default;
    }

    public static function setConnection(?PDO $pdo): void
    {
        self::$connection = $pdo;
    }

    public static function getConnection(bool $useMigrator = false): PDO
    {
        if (self::$connection !== null && !$useMigrator) {
            return self::$connection;
        }

        self::loadEnv();
        $host = self::env('DB_HOST', 'db');
        $port = (int) self::env('DB_PORT', '3306');
        $dbName = self::env('DB_NAME', 'secure_lms');

        if ($useMigrator) {
            $user = self::env('DB_MIGRATOR_USER', 'lms_migrator');
            $pass = self::env('DB_MIGRATOR_PASSWORD', self::env('DB_PASSWORD', ''));
        } else {
            $user = self::env('DB_USER', 'lms_app');
            $pass = self::env('DB_PASSWORD', '');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        if (!$useMigrator) {
            self::$connection = $pdo;
        }

        return $pdo;
    }
}
