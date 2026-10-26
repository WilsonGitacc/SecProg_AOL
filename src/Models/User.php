<?php

declare(strict_types=1);

namespace App\Models;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $role,
        public readonly string $username,
        public readonly string $email,
        public readonly string $fullName,
        public readonly ?string $studentIdAlias = null,
        public readonly ?string $lecturerCodeAlias = null,
        public readonly ?string $academicTitle = null,
        public readonly bool $totpEnabled = false,
        public readonly ?string $totpSecret = null,
        public readonly bool $forcePasswordChange = false,
        public readonly array $enrolledCourseIds = [],
        public readonly array $assignedCourseIds = [],
        public readonly array $enrolledClassIds = [],
        public readonly array $assignedClassIds = []
    ) {
    }

    public function isAnonymous(): bool
    {
        return $this->role === 'anonymous' || $this->id <= 0;
    }

    public static function anonymous(): self
    {
        return new self(0, '', 'anonymous', 'anonymous', '', 'Anonymous');
    }
}
