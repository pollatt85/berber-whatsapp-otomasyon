<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string,mixed> */
    private array $attributes = [];

    /** @var array<string,mixed> */
    public array $query;

    /** @var array<string,mixed> */
    public array $body;

    public string $method;
    public string $path;
    public string $rawBody;
    public string $rawQueryString;

    public function __construct(string $method, string $path, array $query, string $rawBody, string $rawQueryString = '')
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->rawBody = $rawBody;
        $this->rawQueryString = $rawQueryString;

        $decoded = json_decode($rawBody, true);
        $this->body = is_array($decoded) ? $decoded : [];
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $rawBody = file_get_contents('php://input') ?: '';
        $rawQueryString = $_SERVER['QUERY_STRING'] ?? '';

        return new self($method, rtrim($uri, '/') ?: '/', $_GET, $rawBody, $rawQueryString);
    }

    /**
     * `hub.mode`/`hub.verify_token` gibi noktalı query anahtarları için — PHP `$_GET`'te
     * noktaları otomatik alt çizgiye çevirir, bu yüzden `$_GET['hub.mode']` asla bulunmaz
     * (05_WhatsApp_Integration.md §2.1). Ham query string'i noktaları koruyarak ayrıştırır.
     */
    public function queryRaw(string $key): ?string
    {
        foreach (explode('&', $this->rawQueryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if (urldecode($k) === $key) {
                return urldecode($v);
            }
        }
        return null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');
        if ($header !== null && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }
}
