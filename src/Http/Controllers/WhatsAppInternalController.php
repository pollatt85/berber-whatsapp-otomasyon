<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Config\Env;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\CustomerRepository;
use App\Repository\MessageLogRepository;
use App\Repository\MessageTemplateRepository;
use App\Repository\TenantRepository;
use App\Support\MetaGraphClient;
use App\Support\TokenCipher;

/**
 * `POST /internal/whatsapp/send`, `POST /internal/whatsapp/templates/sync`
 * (05_WhatsApp_Integration.md §3, §5, §6, §7). n8n servis kanalı (HMAC) — tenant access
 * token'ları n8n'e asla verilmez, yalnızca burada çözülüp Meta'ya iletilir.
 */
final class WhatsAppInternalController
{
    private const META_ERROR_TOKEN_INVALID = 190;
    private const META_ERROR_WINDOW_CLOSED = 131047;

    public function __construct(
        private TenantRepository $tenants,
        private CustomerRepository $customers,
        private MessageLogRepository $messageLogs,
        private MessageTemplateRepository $templates,
        private MetaGraphClient $meta
    ) {
    }

    public function send(Request $request): Response
    {
        $tenantId = (string) $request->input('tenant_id', '');
        $type = (string) $request->input('type', '');
        $customerId = $request->input('customer_id');
        $idempotencyKey = $request->input('idempotency_key');

        if ($tenantId === '' || !in_array($type, ['text', 'template', 'interactive'], true)) {
            throw new ApiException('validation_error', 'tenant_id and a valid type (text|template|interactive) are required.', 422);
        }

        if ($idempotencyKey !== null) {
            $existing = $this->messageLogs->findByIdempotencyKey($tenantId, (string) $idempotencyKey);
            if ($existing !== null) {
                return Response::json(['data' => $existing], 200);
            }
        }

        $tenant = $this->tenants->findByIdWithToken($tenantId);
        if ($tenant === null) {
            throw new ApiException('tenant_not_found', 'No tenant matches this tenant_id.', 404);
        }

        $customer = null;
        if ($customerId !== null) {
            $customer = $this->customers->find($tenantId, (string) $customerId);
            if ($customer === null) {
                throw new ApiException('not_found', 'Customer not found.', 404);
            }
        }

        $templateId = null;
        if ($type === 'text') {
            if ($customer === null) {
                throw new ApiException('validation_error', 'customer_id is required for type=text.', 422);
            }
            $lastInbound = $this->messageLogs->lastInboundAt($tenantId, $customer['id']);
            if ($lastInbound === null || (time() - strtotime($lastInbound)) > 24 * 3600) {
                throw new ApiException('window_closed', '24 saatlik konuşma penceresi kapalı; şablon mesaj kullanın.', 409);
            }
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $customer['whatsapp_number'],
                'type' => 'text',
                'text' => ['body' => (string) $request->input('text', '')],
            ];
        } elseif ($type === 'template') {
            if ($customer === null) {
                throw new ApiException('validation_error', 'customer_id is required for type=template.', 422);
            }
            $internalName = (string) $request->input('template_internal_name', '');
            $template = $this->templates->findByInternalName($tenantId, $internalName);
            if ($template === null || !$template['active']) {
                throw new ApiException('validation_error', 'Template not found or inactive.', 422);
            }
            $templateId = $template['id'];
            $variables = (array) $request->input('variables', []);
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $customer['whatsapp_number'],
                'type' => 'template',
                'template' => [
                    'name' => $template['meta_template_name'],
                    'language' => ['code' => 'tr'],
                    'components' => $variables === [] ? [] : [[
                        'type' => 'body',
                        'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $variables),
                    ]],
                ],
            ];
        } else {
            if ($customer === null) {
                throw new ApiException('validation_error', 'customer_id is required for type=interactive.', 422);
            }
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $customer['whatsapp_number'],
                'type' => 'interactive',
                'interactive' => (array) $request->input('interactive', []),
            ];
        }

        $accessToken = TokenCipher::decrypt(hex2bin($tenant['access_token_hex']), Env::required('APP_ENCRYPTION_KEY'));
        $result = $this->meta->sendMessage($tenant['phone_number_id'], $accessToken, $payload);

        $success = $result['status'] >= 200 && $result['status'] < 300 && !isset($result['body']['error']);
        $metaErrorCode = $success ? null : (string) ($result['body']['error']['code'] ?? 'unknown');

        if (!$success && $metaErrorCode === (string) self::META_ERROR_TOKEN_INVALID) {
            $this->tenants->markDisconnected($tenantId);
        }

        $logRow = $this->messageLogs->insert(
            $tenantId,
            $customer['id'] ?? null,
            null,
            'outbound',
            $templateId,
            ['request' => $payload, 'response' => $result['body']],
            $success ? 'sent' : 'failed',
            $metaErrorCode,
            $idempotencyKey !== null ? (string) $idempotencyKey : null
        );

        if (!$success) {
            if ($metaErrorCode === (string) self::META_ERROR_WINDOW_CLOSED) {
                throw new ApiException('window_closed', 'Meta: 24 saatlik pencere kapalı.', 409, ['message_log_id' => $logRow['id']]);
            }
            throw new ApiException(
                'whatsapp_send_failed',
                (string) ($result['body']['error']['message'] ?? 'WhatsApp mesajı gönderilemedi.'),
                502,
                ['meta_error_code' => $metaErrorCode, 'message_log_id' => $logRow['id']]
            );
        }

        return Response::json(['data' => $logRow], 201);
    }

    /**
     * `POST /internal/whatsapp/log-inbound` (BACKLOG.md §A madde 28). n8n'in
     * "Upsert Customer" adımından sonra, gelen müşteri mesajını `message_log`'a
     * `direction=inbound` olarak yazar — `send()`'in 24 saatlik pencere kontrolü
     * (`lastInboundAt`) bu satır olmadan hiçbir zaman geçemiyordu (ilk temas 409
     * `window_closed`). `idempotency_key` olarak Meta'nın `wamid`'i verilir; n8n
     * retry'sinde tekrar INSERT edilmez (mevcut satır döner).
     */
    public function logInbound(Request $request): Response
    {
        $tenantId = (string) $request->input('tenant_id', '');
        $customerId = (string) $request->input('customer_id', '');
        $type = (string) $request->input('type', '');
        $idempotencyKey = $request->input('idempotency_key');

        if ($tenantId === '' || $customerId === '' || $type === '') {
            throw new ApiException('validation_error', 'tenant_id, customer_id and type are required.', 422);
        }

        if ($idempotencyKey !== null) {
            $existing = $this->messageLogs->findByIdempotencyKey($tenantId, (string) $idempotencyKey);
            if ($existing !== null) {
                return Response::json(['data' => $existing], 200);
            }
        }

        $customer = $this->customers->find($tenantId, $customerId);
        if ($customer === null) {
            throw new ApiException('not_found', 'Customer not found.', 404);
        }

        $logRow = $this->messageLogs->insert(
            $tenantId,
            $customerId,
            null,
            'inbound',
            null,
            ['type' => $type, 'content' => (array) $request->input('content', [])],
            'received',
            null,
            $idempotencyKey !== null ? (string) $idempotencyKey : null
        );

        return Response::json(['data' => $logRow], 201);
    }

    /**
     * $tenantId verilirse (panel sarmalayıcısı, JWT'den çözülmüş) body'deki tenant_id yerine
     * o kullanılır — panel istemcisi tenant_id gönderemez/geçersiz kılamaz.
     */
    public function syncTemplates(Request $request, ?string $tenantId = null): Response
    {
        $tenantId = $tenantId ?? (string) $request->input('tenant_id', '');
        if ($tenantId === '') {
            throw new ApiException('validation_error', 'tenant_id is required.', 422);
        }

        $tenant = $this->tenants->findByIdWithToken($tenantId);
        if ($tenant === null) {
            throw new ApiException('tenant_not_found', 'No tenant matches this tenant_id.', 404);
        }

        $accessToken = TokenCipher::decrypt(hex2bin($tenant['access_token_hex']), Env::required('APP_ENCRYPTION_KEY'));
        $result = $this->meta->listTemplates($tenant['waba_id'], $accessToken);

        if ($result['status'] < 200 || $result['status'] >= 300 || isset($result['body']['error'])) {
            throw new ApiException(
                'whatsapp_sync_failed',
                (string) ($result['body']['error']['message'] ?? 'Şablon senkronizasyonu başarısız.'),
                502
            );
        }

        $synced = [];
        foreach ((array) ($result['body']['data'] ?? []) as $metaTemplate) {
            $synced[] = $this->templates->upsertFromSync(
                $tenantId,
                (string) $metaTemplate['name'],
                ($metaTemplate['status'] ?? '') === 'APPROVED'
            );
        }

        return Response::json(['data' => $synced]);
    }
}
