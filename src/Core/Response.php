<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public int $statusCode = 200,
        public string $body = '',
        public array $headers = ['Content-Type' => 'text/html; charset=UTF-8']
    ) {
    }

    public static function html(string $html, int $status = 200, array $extraHeaders = []): self
    {
        return new self($status, $html, array_merge(['Content-Type' => 'text/html; charset=UTF-8'], $extraHeaders));
    }

    public static function json(array $data, int $status = 200, array $extraHeaders = []): self
    {
        return new self(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            array_merge(['Content-Type' => 'application/json; charset=UTF-8'], $extraHeaders)
        );
    }

    public static function redirect(string $internalPath, int $status = 302): self
    {
        // Strict open-redirect protection: only allow validated internal paths starting with single '/'
        if (!str_starts_with($internalPath, '/') || str_starts_with($internalPath, '//') || str_contains($internalPath, "\r") || str_contains($internalPath, "\n")) {
            $internalPath = '/dashboard';
        }
        return new self($status, '', ['Location' => $internalPath]);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }
        echo $this->body;
    }
}
