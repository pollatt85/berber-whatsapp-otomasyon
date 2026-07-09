<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Meta Cloud API (Graph API) HTTP istemcisi (05_WhatsApp_Integration.md §3, §6).
 * Tenant access token'ları yalnızca Backend'de çözülür ve burada kullanılır — n8n'e verilmez.
 */
final class MetaGraphClient
{
    private const BASE_URL = 'https://graph.facebook.com/v20.0';

    /**
     * @return array{status:int, body:array}
     */
    public function sendMessage(string $phoneNumberId, string $accessToken, array $payload): array
    {
        return $this->request('POST', "/{$phoneNumberId}/messages", $accessToken, $payload);
    }

    /**
     * @return array{status:int, body:array}
     */
    public function listTemplates(string $wabaId, string $accessToken): array
    {
        return $this->request('GET', "/{$wabaId}/message_templates", $accessToken);
    }

    /**
     * @return array{status:int, body:array}
     */
    private function request(string $method, string $path, string $accessToken, ?array $body = null): array
    {
        $ch = curl_init(self::BASE_URL . $path);
        $headers = ['Authorization: Bearer ' . $accessToken];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Meta Graph API request failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }
}
