<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public function __construct(
        public int $status,
        public array $payload = [],
        public ?string $rawBody = null,
        public string $rawContentType = 'text/plain',
        public array $headers = []
    ) {
    }

    /**
     * 302 yönlendirme — kök URL'yi panele düşürmek için (public/index.php `/` rotası).
     * Görece yol verilir ki hem :8081 hem başka host altında doğru çözülsün.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, [], '', 'text/html; charset=utf-8', ['Location' => $location]);
    }

    public static function json(array $payload, int $status = 200): self
    {
        return new self($status, $payload);
    }

    /**
     * Standart hata sözleşmesi (03_Backend_API.md §6).
     */
    public static function error(string $code, string $message, int $status, array $details = []): self
    {
        return new self($status, ['error' => $code, 'message' => $message, 'details' => $details]);
    }

    /**
     * `hub.challenge` echo gibi düz metin yanıtlar için (05_WhatsApp_Integration.md §2.1).
     */
    public static function text(string $body, int $status = 200): self
    {
        return new self($status, [], $body);
    }

    /**
     * Panel sayfaları için (06_Admin_Panel.md) — sunucu yalnızca HTML iskeleti render eder,
     * veri istemci tarafında JWT'li fetch ile çekilir.
     */
    public static function html(string $body, int $status = 200): self
    {
        return new self($status, [], $body, 'text/html; charset=utf-8');
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->rawBody !== null) {
            header('Content-Type: ' . $this->rawContentType);
            echo $this->rawBody;
            return;
        }
        header('Content-Type: application/json');
        echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
