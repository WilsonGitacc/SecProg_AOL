<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;

final class AuditService
{
    public static function log(
        ?int $userId,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $details = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        try {
            $pdo = Database::getConnection();
            $uuid = sprintf(
                '%04x%04x-%04x-4%03x-%04x-%012x',
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0x0fff),
                random_int(0x8000, 0xbfff),
                random_int(0, 0xffffffffffff)
            );
            $ip = $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $ua = substr((string) ($userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'SecureLMS/1.0')), 0, 500);
            $payload = $details !== null ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (uuid, user_id, action, target_type, target_id, ip_address, user_agent, payload_json)
                VALUES (:uuid, :user_id, :action, :target_type, :target_id, :ip, :ua, :payload)
            ");
            $stmt->execute([
                ':uuid' => $uuid,
                ':user_id' => $userId && $userId > 0 ? $userId : null,
                ':action' => substr($action, 0, 100),
                ':target_type' => $targetType !== null ? substr($targetType, 0, 50) : null,
                ':target_id' => $targetId !== null ? substr($targetId, 0, 50) : null,
                ':ip' => substr((string) $ip, 0, 45),
                ':ua' => $ua,
                ':payload' => $payload,
            ]);
        } catch (\Throwable) {
            // Never leak DB errors to user output
        }
    }
}
