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

        if ($tenantId === '' || !in_array($type, ['text', 'template', 'interactive', 'flow'], true)) {
            throw new ApiException('validation_error', 'tenant_id and a valid type (text|template|interactive|flow) are required.', 422);
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
            // Dil önceliği: istek gövdesi > senkronizasyonda Meta'dan alınan message_templates.language
            // (migration 0005) > 'tr'. Meta, şablon adı + dil çiftini birlikte doğrular.
            $language = (string) $request->input('language', '');
            if ($language === '') {
                $language = (string) ($template['language'] ?? 'tr');
            }
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $customer['whatsapp_number'],
                'type' => 'template',
                'template' => [
                    'name' => $template['meta_template_name'],
                    'language' => ['code' => $language],
                    'components' => $variables === [] ? [] : [[
                        'type' => 'body',
                        'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $variables),
                    ]],
                ],
            ];
        } elseif ($type === 'flow') {
            // WhatsApp Flows tetikleyici mesajı (randevu formu, PHASE_35). `flow_token` içine
            // `"{tenant_id}:{customer_id}"` gömülür — WhatsAppFlowController tenant'ı buradan
            // çözer. n8n flow_id'yi bilmez; META_FLOW_ID burada (Env) okunur.
            if ($customer === null) {
                throw new ApiException('validation_error', 'customer_id is required for type=flow.', 422);
            }
            $flowId = Env::get('META_FLOW_ID', '');
            if ($flowId === '') {
                throw new ApiException('not_configured', 'META_FLOW_ID yapılandırılmamış.', 503);
            }
            $flowData = (array) $request->input('flow_data', []);
            // Meta'nın belgelediği sözleşme: data alanı "boş olmayan bir nesne" olmalı — boşsa
            // anahtarın kendisi hiç gönderilmemeli (401009/131009'a yol açan asıl sebep buydu,
            // ayrıca Flow henüz DRAFT durumdayken "mode":"draft" zorunlu).
            $flowActionPayload = ['screen' => 'SERVICES'];
            if ($flowData !== []) {
                $flowActionPayload['data'] = $flowData;
            }
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $customer['whatsapp_number'],
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'flow',
                    'body' => ['text' => (string) $request->input('body_text', 'Randevu almak için formu doldurun:')],
                    'action' => [
                        'name' => 'flow',
                        'parameters' => [
                            'flow_message_version' => '3',
                            'flow_token' => "{$tenantId}:{$customer['id']}",
                            'flow_id' => $flowId,
                            'flow_cta' => 'Randevu Al',
                            'mode' => Env::get('META_FLOW_MODE', 'published'),
                            'flow_action' => 'navigate',
                            'flow_action_payload' => $flowActionPayload,
                        ],
                    ],
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
     * `GET /settings/whatsapp/health` (BACKLOG §A m.17, 09§2/§6) — panel JWT kanalı.
     * Numaranın Meta tarafındaki kalite puanı (`quality_rating`) ve mesajlaşma limit
     * kademesini (`messaging_limit_tier`, ör. TIER_250) döner; panel tier uyarı alanı
     * bunu gösterir. Meta'ya ulaşılamazsa 502 yerine `available:false` ile 200 döner —
     * ayar sayfası salt bilgi alanı yüzünden kırılmasın.
     */
    public function health(Request $request, string $tenantId): Response
    {
        $tenant = $this->tenants->findByIdWithToken($tenantId);
        if ($tenant === null) {
            throw new ApiException('tenant_not_found', 'No tenant matches this tenant_id.', 404);
        }
        if ($tenant['whatsapp_status'] !== 'connected' || $tenant['access_token_hex'] === null) {
            return Response::json(['data' => ['available' => false, 'reason' => 'not_connected']]);
        }

        try {
            $accessToken = TokenCipher::decrypt(hex2bin($tenant['access_token_hex']), Env::required('APP_ENCRYPTION_KEY'));
            $result = $this->meta->getPhoneNumberHealth($tenant['phone_number_id'], $accessToken);
        } catch (\Throwable $e) {
            return Response::json(['data' => ['available' => false, 'reason' => 'meta_unreachable']]);
        }

        if ($result['status'] < 200 || $result['status'] >= 300 || isset($result['body']['error'])) {
            return Response::json(['data' => [
                'available' => false,
                'reason' => 'meta_error',
                'meta_error' => (string) ($result['body']['error']['message'] ?? 'unknown'),
            ]]);
        }

        $body = $result['body'];

        return Response::json(['data' => [
            'available' => true,
            'display_phone_number' => $body['display_phone_number'] ?? null,
            'verified_name' => $body['verified_name'] ?? null,
            'quality_rating' => $body['quality_rating'] ?? null,
            'messaging_limit_tier' => $body['messaging_limit_tier'] ?? null,
            'name_status' => $body['name_status'] ?? null,
        ]]);
    }

    /**
     * `POST /settings/whatsapp/connect` (05_WhatsApp_Integration.md §1, BACKLOG §A m.25+m.29) —
     * Embedded Signup'ın sunucu tarafı. Panel, Meta popup'ından dönen `code` + popup'ın
     * message event'iyle bildirdiği `waba_id`/`phone_number_id` üçlüsünü gönderir:
     *   1. code → business token exchange (Graph /oauth/access_token)
     *   2. WABA'nın bu App'e webhook aboneliği (POST /{waba-id}/subscribed_apps) — Embedded
     *      Signup DIŞI bağlantıların atladığı, yokluğunda inbound webhook'un sessizce hiç
     *      tetiklenmediği kritik adım (PHASE_31 bulgusu)
     *   3. token şifrelenip tenants satırına yazılır, whatsapp_status='connected'
     */
    public function connect(Request $request, string $tenantId, string $role): Response
    {
        if (!in_array($role, ['owner', 'manager'], true)) {
            throw new ApiException('forbidden', 'Role does not permit this action.', 403);
        }

        $code = (string) $request->input('code', '');
        $wabaId = (string) $request->input('waba_id', '');
        $phoneNumberId = (string) $request->input('phone_number_id', '');

        $errors = [];
        if ($code === '') {
            $errors['code'] = 'Embedded Signup dönüş kodu gerekli.';
        }
        if ($wabaId === '') {
            $errors['waba_id'] = 'waba_id gerekli.';
        }
        if ($phoneNumberId === '') {
            $errors['phone_number_id'] = 'phone_number_id gerekli.';
        }
        if ($errors !== []) {
            throw new ApiException('validation_error', 'Geçersiz alanlar var.', 422, $errors);
        }

        $appId = Env::get('META_APP_ID', '');
        $appSecret = Env::get('META_APP_SECRET', '');
        if ($appId === '' || $appSecret === '') {
            throw new ApiException('not_configured', 'META_APP_ID / META_APP_SECRET yapılandırılmamış.', 503);
        }

        $exchange = $this->meta->exchangeCode($appId, $appSecret, $code);
        $accessToken = (string) ($exchange['body']['access_token'] ?? '');
        if ($exchange['status'] < 200 || $exchange['status'] >= 300 || $accessToken === '') {
            throw new ApiException(
                'whatsapp_connect_failed',
                (string) ($exchange['body']['error']['message'] ?? 'Meta code exchange başarısız.'),
                502,
                ['step' => 'exchange_code']
            );
        }

        $subscribe = $this->meta->subscribeApp($wabaId, $accessToken);
        if ($subscribe['status'] < 200 || $subscribe['status'] >= 300 || isset($subscribe['body']['error'])) {
            throw new ApiException(
                'whatsapp_connect_failed',
                (string) ($subscribe['body']['error']['message'] ?? 'WABA webhook aboneliği başarısız.'),
                502,
                ['step' => 'subscribed_apps']
            );
        }

        $encryptedHex = bin2hex(TokenCipher::encrypt($accessToken, Env::required('APP_ENCRYPTION_KEY')));
        try {
            $tenant = $this->tenants->connectWhatsApp($tenantId, $phoneNumberId, $wabaId, $encryptedHex);
        } catch (\PDOException $e) {
            // tenants.phone_number_id UNIQUE — numara başka bir tenant'a bağlıysa 500 yerine 409.
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new ApiException('phone_number_in_use', 'Bu numara başka bir işletmeye bağlı.', 409);
            }
            throw $e;
        }
        if ($tenant === null) {
            throw new ApiException('tenant_not_found', 'No tenant matches this tenant_id.', 404);
        }

        return Response::json(['data' => $tenant]);
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
                ($metaTemplate['status'] ?? '') === 'APPROVED',
                (string) ($metaTemplate['language'] ?? 'tr')
            );
        }

        return Response::json(['data' => $synced]);
    }
}
