<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Config\Env;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\MessageLogRepository;
use App\Repository\TenantRepository;
use App\Repository\WebhookEventRepository;
use App\Support\N8nNotifier;

/**
 * `GET/POST /webhook/whatsapp` (05_WhatsApp_Integration.md §2). Meta'nın doğrudan çağırdığı
 * uç — tenant'tan bağımsız, uygulama genelindeki `WEBHOOK_VERIFY_TOKEN`/`META_APP_SECRET` ile
 * çalışır (n8n değil, Connection::service() BYPASSRLS). Tenant çözüldükten sonra n8n'e
 * orkestrasyon için iletilir (bkz. `N8nNotifier`, 04_n8n_Workflows.md §1).
 */
final class WhatsAppWebhookController
{
    public function __construct(
        private WebhookEventRepository $events,
        private TenantRepository $tenants,
        private ?MessageLogRepository $messageLogs = null,
        private ?N8nNotifier $notifier = null
    ) {
        $this->notifier ??= new N8nNotifier();
    }

    /**
     * §2.1: Meta'nın webhook aboneliği doğrulaması. `hub.verify_token` eşleşirse `hub.challenge`
     * düz metin olarak döner, aksi halde 403.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->queryRaw('hub.mode');
        $token = $request->queryRaw('hub.verify_token');
        $challenge = $request->queryRaw('hub.challenge');

        if ($mode === 'subscribe' && $token !== null && hash_equals(Env::required('WEBHOOK_VERIFY_TOKEN'), $token)) {
            return Response::text((string) $challenge);
        }

        throw new ApiException('forbidden', 'Webhook verification failed.', 403);
    }

    /**
     * §2.2: İmza doğrulama → ham kayıt → tenant çözümü. Meta'nın retry/webhook-disable
     * davranışına karşı (§2.2 madde 4), geçerli imzalı her istekte tenant bulunamasa bile 200
     * döner; yalnızca geçersiz imza reddedilir (403, kayıt düşülmez — spoof koruması).
     */
    public function receive(Request $request): Response
    {
        $signatureHeader = $request->header('X-Hub-Signature-256') ?? '';
        $expected = 'sha256=' . hash_hmac('sha256', $request->rawBody, Env::required('META_APP_SECRET'));

        if (!hash_equals($expected, $signatureHeader)) {
            throw new ApiException('unauthorized', 'Invalid webhook signature.', 403);
        }

        $payload = $request->body;
        $phoneNumberId = (string) ($payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? '');
        $tenant = $phoneNumberId !== '' ? $this->tenants->findByPhoneNumberId($phoneNumberId) : null;

        $this->events->insert($tenant['id'] ?? null, $phoneNumberId !== '' ? $phoneNumberId : 'unknown', true, $payload);

        if ($tenant !== null) {
            $value = $payload['entry'][0]['changes'][0]['value'] ?? [];

            // Y4: Meta teslimat durumu callback'i (statuses[]) — message_log.status'u güncelle.
            // Bir webhook ya `messages` ya da `statuses` taşır; statuses varsa n8n'e iletmeye gerek yok.
            if (!empty($value['statuses']) && $this->messageLogs !== null) {
                foreach ($value['statuses'] as $status) {
                    $wamid = (string) ($status['id'] ?? '');
                    $newStatus = (string) ($status['status'] ?? '');
                    if ($wamid !== '' && in_array($newStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
                        $this->messageLogs->updateStatusByWamid($tenant['id'], $wamid, $newStatus);
                    }
                }
            }

            // Gelen kullanıcı mesajı → orkestrasyon için n8n'e ilet.
            if (!empty($value['messages'])) {
                $this->notifier->notifyIncomingMessage($tenant['id'], $phoneNumberId, $payload);
            }
        }

        return Response::json(['status' => 'ok']);
    }
}
