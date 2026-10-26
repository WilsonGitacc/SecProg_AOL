<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Safe Markdown Subset Renderer.
 * Escapes all raw HTML first, then renders bold, italics, inline code,
 * code blocks, bullet lists, and http/https links only (blocks javascript:/data:/vbscript:).
 */
final class Markdown
{
    public static function render(string $raw): string
    {
        $escaped = Escaper::html($raw);

        // Code blocks ```...```
        $escaped = (string) preg_replace_callback('/```([a-zA-Z0-9_-]*)\n(.*?)```/s', static function (array $m): string {
            return '<pre class="nb-code"><code>' . $m[2] . '</code></pre>';
        }, $escaped);

        // Inline code `...`
        $escaped = (string) preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $escaped);

        // Bold **...**
        $escaped = (string) preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $escaped);

        // Italic *...*
        $escaped = (string) preg_replace('/\*([^*\n]+)\*/', '<em>$1</em>', $escaped);

        // Safe links [label](url) - allow only http://, https://, or single-slash internal path
        $escaped = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', static function (array $m): string {
            $label = $m[1];
            $rawUrl = html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8');
            if (
                preg_match('#^https?://#i', $rawUrl) ||
                (str_starts_with($rawUrl, '/') && !str_starts_with($rawUrl, '//'))
            ) {
                $safeHref = Escaper::attr($rawUrl);
                return '<a href="' . $safeHref . '" rel="noopener noreferrer">' . $label . '</a>';
            }
            return $label;
        }, $escaped);

        // Paragraphs / line breaks
        $paragraphs = preg_split('/\n{2,}/', trim($escaped)) ?: [];
        $out = [];
        foreach ($paragraphs as $p) {
            if (str_starts_with($p, '<pre ')) {
                $out[] = $p;
            } else {
                $out[] = '<p>' . nl2br($p, false) . '</p>';
            }
        }
        return implode("\n", $out);
    }
}
