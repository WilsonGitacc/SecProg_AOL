<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Security\Policy;
use App\Service\AuditService;
use App\Service\AuthService;
use PDO;

final class UploadController
{
    public static function download(Request $request): Response
    {
        $user = AuthService::currentUser();
        $uuid = (string) ($request->params['uuid'] ?? '');

        // Reject any path traversal characters
        if (!preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
            return Response::html('<h1>400 Bad Request — Invalid File Identifier</h1>', 400);
        }

        if (!Policy::authorize($user, "/files/{$uuid}", 'GET')) {
            return Response::html('<h1>403 Forbidden</h1>', 403);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM uploaded_files WHERE uuid = :u AND av_scan_status = 'clean' LIMIT 1");
        $stmt->execute([':u' => $uuid]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file || !is_file((string) $file['file_path'])) {
            return Response::html('<h1>404 File Not Found</h1>', 404);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $file['original_filename']) ?: 'download.bin';
        $content = (string) file_get_contents((string) $file['file_path']);
        AuditService::log($user->id, 'upload.downloaded', 'uploaded_files', $uuid);

        return new Response(200, $content, [
            'Content-Type' => (string) $file['mime_type'],
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
