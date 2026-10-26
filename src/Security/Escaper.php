<?php

declare(strict_types=1);

namespace App\Security;

final class Escaper
{
    public function __invoke(mixed $value): string
    {
        return self::html($value);
    }

    public static function html(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function attr(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function js(mixed $value): string
    {
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }

    public static function url(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '#';
        }
        if (str_starts_with($trimmed, '/') && !str_starts_with($trimmed, '//')) {
            return self::attr($trimmed);
        }
        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return '#unsafe-url-blocked';
        }
        return self::attr($trimmed);
    }
}
