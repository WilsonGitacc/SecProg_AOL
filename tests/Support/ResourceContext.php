<?php

declare(strict_types=1);

namespace Tests\Support;

class ResourceContext
{
    public function __construct(
        public readonly string $route,
        public readonly string $method = 'GET',
        public readonly ?int $courseId = null,
        public readonly ?int $ownerId = null,
        public readonly ?int $postAgeSeconds = null
    ) {
    }
}
