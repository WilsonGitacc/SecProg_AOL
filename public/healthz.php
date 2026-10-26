<?php
declare(strict_types=1);

http_response_code(200);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode([
    'status' => 'healthy',
    'timestamp' => gmdate('c'),
    'service' => 'secure-lms-web',
    'uptime_sapi' => PHP_SAPI,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit(0);
