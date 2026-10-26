<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Session;

final class Csrf
{
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function validate(string $method, ?string $submittedToken, ?string $sessionToken = null): bool
    {
        $method = strtoupper($method);
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $sessionToken ??= Session::getCsrfToken();
        if ($submittedToken === null || $sessionToken === '' || $submittedToken === '') {
            return false;
        }
        return hash_equals($sessionToken, $submittedToken);
    }
}
