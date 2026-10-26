<?php

declare(strict_types=1);

namespace App\Security;

final class AvScannerHook
{
    private const EICAR_SIGNATURE = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    /**
     * Scans a file for EICAR test signatures or embedded PHP executable tags.
     * Returns true if clean, false if infected/malicious.
     */
    public static function scan(string $filePath): bool
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return false;
        }
        $content = (string) file_get_contents($filePath, false, null, 0, 65536);
        if (str_contains($content, self::EICAR_SIGNATURE)) {
            return false;
        }
        if (stripos($content, '<?php') !== false || stripos($content, '<?=') !== false) {
            return false;
        }
        return true;
    }
}
