<?php

declare(strict_types=1);

namespace Tests\Support;

class UserContext
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $role, // 'anonymous', 'student', 'lecturer', 'operator'
        public readonly array $enrolledCourseIds = [],
        public readonly array $assignedCourseIds = []
    ) {
    }

    public function isAnonymous(): bool
    {
        return $this->role === 'anonymous';
    }
}
