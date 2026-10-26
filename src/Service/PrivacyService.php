<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Deterministic Privacy & Pseudonymization Service.
 * Ensures zero real student/lecturer names, NIMs, emails, or phone numbers
 * are ever stored in MySQL or rendered in HTML.
 */
final class PrivacyService
{
    private const FIRST_NAMES = [
        'Sarah', 'Marcus', 'Elena', 'David', 'Nadia', 'Arthur', 'Clara', 'Julian',
        'Hana', 'Victor', 'Maya', 'Lucas', 'Tessa', 'Adrian', 'Klara', 'Felix',
        'Sora', 'Damon', 'Iris', 'Gideon', 'Naomi', 'Oscar', 'Vera', 'Leo',
    ];

    private const LAST_NAMES = [
        'Jenkins', 'Vance', 'Rostova', 'Chen', 'Kowalski', 'Sterling', 'Mercer', 'Alverez',
        'Takahashi', 'Lindqvist', 'Patel', 'Okafor', 'Moreau', 'Sorensen', 'Novak', 'Castillo',
        'Keller', 'ramirez', 'Hayashi', 'Bauer', 'Silva', 'walsh', 'Ibrahim', 'Park',
    ];

    public static function pseudonymizeName(string $seedOrRealName, string $role = 'student'): string
    {
        $hash = hash('sha256', 'secure_lms_pseudonym_v1:' . strtolower(trim($seedOrRealName)) . ':' . strtolower($role));
        $firstIdx = hexdec(substr($hash, 0, 6)) % count(self::FIRST_NAMES);
        $lastIdx = hexdec(substr($hash, 6, 6)) % count(self::LAST_NAMES);

        $first = self::FIRST_NAMES[$firstIdx];
        $last = ucfirst(self::LAST_NAMES[$lastIdx]);

        if ($role === 'lecturer') {
            return "Prof. {$first} {$last}";
        }
        if ($role === 'operator') {
            return "Ops. {$first} {$last}";
        }
        return "{$first} {$last}";
    }

    public static function pseudonymizeStudentAlias(string $seed): string
    {
        $hash = hash('sha256', 'student_alias_v1:' . $seed);
        $num = (hexdec(substr($hash, 0, 8)) % 900000) + 100000;
        return 'STD-' . $num;
    }

    public static function pseudonymizeLecturerAlias(string $seed): string
    {
        $hash = hash('sha256', 'lecturer_alias_v1:' . $seed);
        $num = (hexdec(substr($hash, 0, 6)) % 9000) + 1000;
        return 'LEC-' . $num;
    }

    public static function pseudonymizeEmail(string $username, string $role): string
    {
        $clean = preg_replace('/[^a-z0-9_.-]/i', '', strtolower($username)) ?: 'user';
        return "{$clean}.{$role}@lms.local";
    }
}
