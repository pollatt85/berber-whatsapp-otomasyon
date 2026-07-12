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
     * Panel tier/kalite uyarı alanı (09_SaaS_Deployment.md §2/§6, BACKLOG §A m.17):
     * numaranın kalite puanı + mesajlaşma limit kademesi.
     *
     * @return array{status:int, body:array}
     */
    public function getPhoneNumberHealth(string $phoneNumberId, string $accessToken): array
    {
        $fields = 'display_phone_number,verified_name,quality_rating,messaging_limit_tier,name_status';

        return $this->request('GET', "/{$phoneNumberId}?fields={$fields}", $accessToken);
    }

    /**
     * Embedded Signup (05_WhatsApp_Integration.md §1 adım 2): Meta'nın popup dönüşünde verdiği
     * `code`, App kimliğiyle kalıcı business token'a çevrilir. Bearer header gerekmez —
     * kimlik `client_id`/`client_secret` query paramlarıyla taşınır.
     *
     * @return array{status:int, body:array}
     */
    public function exchangeCode(string $appId, string $appSecret, string $code): array
    {
        $query = http_build_query([
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'code' => $code,
        ]);

        return $this->request('GET', "/oauth/access_token?{$query}", null);
    }

    /**
     * Embedded Signup adım 4 (05§1) + BACKLOG §A m.29: WABA'nın bu App'in webhook'una
     * abone edilmesi. Bu adım atlanırsa gelen mesaj webhook'u SESSİZCE hiç tetiklenmez
     * (PHASE_31'de gerçek trafikte teşhis edildi) — bağlantı akışının zorunlu parçası.
     *
     * @return array{status:int, body:array}
     */
    public function subscribeApp(string $wabaId, string $accessToken): array
    {
        return $this->request('POST', "/{$wabaId}/subscribed_apps", $accessToken);
    }

    /**
     * @return array{status:int, body:array}
     */
    private function request(string $method, string $path, ?string $accessToken, ?array $body = null): array
    {
        $ch = curl_init(self::BASE_URL . $path);
        // exchangeCode kimliği query paramlarıyla taşır — Bearer header'sız çağrılabilir.
        $headers = $accessToken !== null ? ['Authorization: Bearer ' . $accessToken] : [];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        } elseif ($method === 'POST') {
            // Gövdesiz POST (subscribed_apps) — Content-Length: 0 garanti edilsin.
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
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
