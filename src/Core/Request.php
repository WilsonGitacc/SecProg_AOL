<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    public readonly array $query;
    public readonly array $post;
    public readonly array $files;
    public readonly array $server;
    public readonly array $headers;
    public array $params = [];

    public function __construct(
        ?string $method = null,
        ?string $path = null,
        ?array $query = null,
        ?array $post = null,
        ?array $files = null,
        ?array $server = null
    ) {
        $this->server = $server ?? $_SERVER;
        $rawMethod = $method ?? ($this->server['REQUEST_METHOD'] ?? 'GET');
        $this->post = $post ?? $_POST;
        if (strtoupper($rawMethod) === 'POST' && isset($this->post['_method'])) {
            $override = strtoupper((string) $this->post['_method']);
            if (in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
                $rawMethod = $override;
            }
        }
        $this->method = strtoupper($rawMethod);
        $rawUri = $path ?? ($this->server['REQUEST_URI'] ?? '/');
        $parsedPath = parse_url($rawUri, PHP_URL_PATH);
        $normPath = is_string($parsedPath) && $parsedPath !== '' ? '/' . trim($parsedPath, '/') : '/';
        $this->path = $normPath === '//' ? '/' : $normPath;
        $this->query = $query ?? $_GET;
        $this->files = $files ?? $_FILES;
        $this->headers = self::extractHeaders($this->server);
    }

    private static function extractHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = (string) $v;
            }
        }
        return $headers;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? 'SecureLMS-Client/1.0'), 0, 500);
    }

    public function wantsJson(): bool
    {
        $accept = $this->headers['accept'] ?? '';
        return str_contains($accept, 'application/json') || (($this->query['format'] ?? '') === 'json');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }
}
