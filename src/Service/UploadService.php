<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Security\AvScannerHook;

final class UploadService
{
    private const MAX_SIZE_BYTES = 10485760; // 10 MB
    private const ALLOWED_MIME_MAP = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'txt'  => ['text/plain'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    /**
     * Safe 7-stage file upload pipeline.
     * @return array{success: bool, error?: string, file_id?: int, uuid?: string}
     */
    public static function handleUpload(array $file, int $userId): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'File upload error or no file selected.'];
        }

        $origName = basename((string) ($file['name'] ?? 'upload'));
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > self::MAX_SIZE_BYTES) {
            return ['success' => false, 'error' => 'File size must be between 1 byte and 10 MB.'];
        }

        // Reject double extensions or null bytes
        if (str_contains($origName, "\0") || preg_match('/\.(php\d*|phtml|phar|sh|pl|py|cgi|exe|bat|cmd|js|html?|svg)\b/i', $origName)) {
            return ['success' => false, 'error' => 'Executable or dangerous file extensions are prohibited.'];
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_MIME_MAP[$ext])) {
            return ['success' => false, 'error' => 'File extension not in allowlist (pdf, png, jpg, jpeg, txt, zip).'];
        }

        if (!is_file($tmpPath)) {
            return ['success' => false, 'error' => 'Uploaded temporary file missing.'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($tmpPath);
        if (!in_array($detectedMime, self::ALLOWED_MIME_MAP[$ext], true)) {
            return ['success' => false, 'error' => "MIME type mismatch: detected {$detectedMime} for .{$ext}."];
        }

        if (!AvScannerHook::scan($tmpPath)) {
            AuditService::log($userId, 'upload.rejected.av_scan', 'uploaded_files', $origName);
            return ['success' => false, 'error' => 'File rejected by security/antivirus content scanner.'];
        }

        $uuid = sprintf(
            '%04x%04x-%04x-4%03x-%04x-%012x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0x8000, 0xbfff),
            random_int(0, 0xffffffffffff)
        );
        $storageFilename = $uuid . '.' . $ext;
        $storageDir = dirname(__DIR__, 2) . '/storage/uploads';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0770, true);
        }
        $destPath = $storageDir . '/' . $storageFilename;

        if (!copy($tmpPath, $destPath)) {
            return ['success' => false, 'error' => 'Failed to persist file to isolated storage.'];
        }
        chmod($destPath, 0640);
        $sha256 = hash_file('sha256', $destPath) ?: str_repeat('0', 64);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO uploaded_files (
                uuid, original_filename, storage_filename, file_path,
                mime_type, file_size_bytes, sha256_hash, uploaded_by, av_scan_status
            ) VALUES (
                :uuid, :orig, :storage, :path, :mime, :size, :hash, :uid, 'clean'
            )
        ");
        $stmt->execute([
            ':uuid' => $uuid,
            ':orig' => preg_replace('/[^a-zA-Z0-9._ -]/', '_', $origName) ?: 'attachment.' . $ext,
            ':storage' => $storageFilename,
            ':path' => $destPath,
            ':mime' => $detectedMime,
            ':size' => $size,
            ':hash' => $sha256,
            ':uid' => $userId,
        ]);
        $fileId = (int) $pdo->lastInsertId();
        AuditService::log($userId, 'upload.stored', 'uploaded_files', $uuid, ['mime' => $detectedMime, 'size' => $size]);

        return ['success' => true, 'file_id' => $fileId, 'uuid' => $uuid];
    }
}
