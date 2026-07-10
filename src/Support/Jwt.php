<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal HS256 JWT encode/decode. Composer kurulu olmayan geliştirme makinesinde harici
 * bağımlılık gerektirmemek için elle yazıldı (bkz. PROJECT_MEMORY.md ortam bulguları).
 * Yalnızca 03_Backend_API.md §2.2'de tanımlanan claim seti (sub, tenant_id, role, exp) için yeterlidir.
 */
final class Jwt
{
    public static function encode(array $claims, string $secret): string
    {
        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = self::base64UrlEncode(json_encode($claims));
        $signature = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * @throws \RuntimeException imza geçersiz, süresi dolmuş veya biçim bozuksa
     */
    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed token.');
        }

        [$header, $payload, $signature] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Invalid signature.');
        }

        $claims = json_decode(self::base64UrlDecode($payload), true);
        if (!is_array($claims)) {
            throw new \RuntimeException('Malformed payload.');
        }

        if (isset($claims['exp']) && time() >= (int) $claims['exp']) {
            throw new \RuntimeException('Token expired.');
        }

        return $claims;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
