<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;
use App\Repository\ConversationStateRepository;
use App\Repository\CustomerRepository;
use App\Repository\MessageLogRepository;
use App\Repository\TenantRepository;
use App\Service\AvailabilityService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Panelden yapılan randevu durum değişikliklerinde (onay/iptal/yeni saat isteme) müşteriye
 * WhatsApp bilgilendirme mesajı gönderir. Best-effort: gönderim başarısız olsa da panel işlemi
 * etkilenmez (N8nNotifier'daki sessiz-hata deseniyle aynı gerekçe).
 */
final class WhatsAppNotifier
{
    /** Faz D: bir sayfada gösterilen slot sayısı; 10 satırlık WhatsApp liste sınırında 9. satır
     *  "Daha fazla saat" için ayrılır. n8n workflow 01 ile aynı değer olmalı. */
    private const SLOT_PAGE_SIZE = 8;

    public function __construct(
        private TenantRepository $tenants,
        private CustomerRepository $customers,
        private MessageLogRepository $messageLogs,
        private MetaGraphClient $meta,
        private ?AvailabilityService $availability = null,
        private ?ConversationStateRepository $conversationStates = null
    ) {
    }

    public function notifyAppointment(string $tenantId, string $customerId, ?string $appointmentId, string $text): void
    {
        try {
            $tenant = $this->tenants->findByIdWithToken($tenantId);
            if ($tenant === null || $tenant['whatsapp_status'] !== 'connected' || $tenant['access_token_hex'] === null) {
                return;
            }

            $customer = $this->customers->find($tenantId, $customerId);
            if ($customer === null) {
                return;
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $customer['whatsapp_number'],
                'type' => 'text',
                'text' => ['body' => $text],
            ];

            // Y6: 24 saatlik konuşma penceresi kontrolü (WhatsAppInternalController::send ile aynı mantık).
            // Kapalıysa serbest metin gönderimi Meta'da 131047 ile düşer; boşuna çağrı yapmak yerine
            // nedeni message_log'a 'failed' olarak yazıp çık — panel "gönderildi" yanılsaması vermesin.
            $lastInbound = $this->messageLogs->lastInboundAt($tenantId, $customerId);
            if ($lastInbound === null || (time() - strtotime($lastInbound)) > 24 * 3600) {
                $this->messageLogs->insert(
                    $tenantId,
                    $customerId,
                    $appointmentId,
                    'outbound',
                    null,
                    ['request' => $payload, 'response' => ['error' => ['code' => 131047, 'message' => 'preflight: 24h window closed']]],
                    'failed',
                    '131047',
                    null
                );
                return;
            }

            $accessToken = TokenCipher::decrypt(hex2bin($tenant['access_token_hex']), Env::required('APP_ENCRYPTION_KEY'));
            $result = $this->meta->sendMessage($tenant['phone_number_id'], $accessToken, $payload);
            $success = $result['status'] >= 200 && $result['status'] < 300 && !isset($result['body']['error']);
            $metaErrorCode = $success ? null : (string) ($result['body']['error']['code'] ?? 'unknown');

            // Y3: token geçersiz (190) → tenant'ı disconnected işaretle; aksi halde whatsapp_status
            // yeşil kalır ve tüm panel aksiyonları sessizce düşer.
            if (!$success && $metaErrorCode === '190') {
                $this->tenants->markDisconnected($tenantId);
            }

            $this->messageLogs->insert(
                $tenantId,
                $customerId,
                $appointmentId,
                'outbound',
                null,
                ['request' => $payload, 'response' => $result['body']],
                $success ? 'sent' : 'failed',
                $metaErrorCode,
                null
            );
        } catch (Throwable $e) {
            // Y1: sessiz yutma yok — teşhis için logla (panel aksiyonu yine tamamlanır).
            error_log('WhatsAppNotifier::notifyAppointment: ' . $e->getMessage());
        }
    }

    /**
     * İşletme pending bir randevunun saatini uygun bulmayınca panelden tetiklenir: müşteriye
     * aynı personel+hizmet için 7 günlük bir saat listesi (n8n'in `Aggregate & Build Slots Msg`
     * ile aynı `slot_{staffId}|{serviceId}|{date}T{time}` id şeması) gönderilir ve
     * conversation_states, n8n'in mevcut reschedule sözleşmesiyle (`reschedule_of`) birebir aynı
     * şekilde `awaiting_slot_selection`'a set edilir — müşteri yeni saati seçtiğinde n8n'in
     * mevcut `slot_chosen` → reschedule akışı hiç değişmeden bunu işler.
     */
    public function sendSlotPicker(
        string $tenantId,
        string $customerId,
        string $appointmentId,
        string $staffId,
        string $serviceId,
        string $leadText
    ): void {
        if ($this->availability === null || $this->conversationStates === null) {
            return;
        }

        try {
            $tenant = $this->tenants->findByIdWithToken($tenantId);
            if ($tenant === null || $tenant['whatsapp_status'] !== 'connected' || $tenant['access_token_hex'] === null) {
                return;
            }

            $customer = $this->customers->find($tenantId, $customerId);
            if ($customer === null) {
                return;
            }

            $tenantRow = $this->tenants->findById($tenantId);
            $timezone = (string) ($tenantRow['timezone'] ?? 'Europe/Istanbul');
            $stepMinutes = (int) ($tenantRow['slot_step_minutes'] ?? AvailabilityService::DEFAULT_STEP_MINUTES);
            $today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
            $days = $this->availability->slotsForRange($tenantId, $staffId, $serviceId, $today, 7, $timezone, $stepMinutes);

            // Faz D: tüm slotları düz listeye aç, ilk sayfayı (offset 0) göster; devamı varsa 10. satıra
            // "Daha fazla saat ▶" ekle. Müşteri buna basınca yanıt n8n'e (workflow 01 more_slots) gider
            // ve sonraki sayfa oradan yönetilir — panel yalnızca ilk sayfayı başlatır.
            $flat = [];
            foreach ($days as $date => $times) {
                foreach ($times as $time) {
                    $flat[] = ['date' => $date, 'time' => $time];
                }
            }

            $rows = [];
            foreach (array_slice($flat, 0, self::SLOT_PAGE_SIZE) as $slot) {
                $rows[] = [
                    'id' => "slot_{$staffId}|{$serviceId}|{$slot['date']}T{$slot['time']}",
                    'title' => substr("{$slot['date']} {$slot['time']}", 0, 24),
                    'description' => "Personel: {$staffId}",
                ];
            }
            if (count($flat) > self::SLOT_PAGE_SIZE) {
                $rows[] = ['id' => 'more_slots', 'title' => 'Daha fazla saat ▶'];
            }

            if ($rows === []) {
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to' => $customer['whatsapp_number'],
                    'type' => 'text',
                    'text' => ['body' => "{$leadText} Ne yazık ki önümüzdeki 7 günde uygun saat bulunamadı, lütfen bizi arayın."],
                ];
            } else {
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to' => $customer['whatsapp_number'],
                    'type' => 'interactive',
                    'interactive' => [
                        'type' => 'list',
                        'body' => ['text' => $leadText],
                        'action' => ['button' => 'Saat Seç', 'sections' => [['title' => 'Uygun Saatler', 'rows' => $rows]]],
                    ],
                ];
            }

            $accessToken = TokenCipher::decrypt(hex2bin($tenant['access_token_hex']), Env::required('APP_ENCRYPTION_KEY'));
            $result = $this->meta->sendMessage($tenant['phone_number_id'], $accessToken, $payload);
            $success = $result['status'] >= 200 && $result['status'] < 300 && !isset($result['body']['error']);
            $metaErrorCode = $success ? null : (string) ($result['body']['error']['code'] ?? 'unknown');

            // Y3: token geçersiz (190) → tenant'ı disconnected işaretle.
            if (!$success && $metaErrorCode === '190') {
                $this->tenants->markDisconnected($tenantId);
            }

            $this->messageLogs->insert(
                $tenantId,
                $customerId,
                $appointmentId,
                'outbound',
                null,
                ['request' => $payload, 'response' => $result['body']],
                $success ? 'sent' : 'failed',
                $metaErrorCode,
                null
            );

            if ($success && $rows !== []) {
                // Faz D: slot_offset state'e yazılır; n8n more_slots akışı bunu okuyup ilerletir.
                $this->conversationStates->upsert($tenantId, $customerId, 'awaiting_slot_selection', [
                    'service_id' => $serviceId,
                    'staff_id' => $staffId,
                    'reschedule_of' => $appointmentId,
                    'slot_offset' => 0,
                ]);
            }
        } catch (Throwable $e) {
            // Y1: sessiz yutma yok — teşhis için logla.
            error_log('WhatsAppNotifier::sendSlotPicker: ' . $e->getMessage());
        }
    }

    /**
     * WhatsApp Flows tetikleyici mesajı (randevu formu, PHASE_35). `flow_token` içine
     * `"{tenant_id}:{customer_id}"` gömülür — Meta bunu her `data_exchange` isteğinde
     * değiştirmeden geri yollar, `WhatsAppFlowController` tenant'ı buradan çözer.
     * `prefill*` alanları Flow JSON'a geçirilir ama şu an ekranlarda tüketilmiyor (Meta'nın
     * `init_value`/`init-value` şemasını reddetmesi nedeniyle devre dışı bırakıldı — bilinen
     * bir sınırlama, ekran bileşenlerinin doğru ön doldurma alanı bulununca eklenecek).
     */
    public function sendFlowTrigger(
        string $tenantId,
        string $customerId,
        ?string $appointmentId,
        string $bodyText,
        array $prefillData = []
    ): void {
        try {
            $flowId = Env::get('META_FLOW_ID', '');
            if ($flowId === '') {
                return;
            }

            $tenant = $this->tenants->findByIdWithToken($tenantId);
            if ($tenant === null || $tenant['whatsapp_status'] !== 'connected' || $tenant['access_token_hex'] === null) {
                return;
            }

            $customer = $this->customers->find($tenantId, $customerId);
            if ($customer === null) {
                return;
            }

            $flowToken = "{$tenantId}:{$customerId}";
            // Meta'nın sözleşmesi: data alanı "boş olmayan bir nesne" olmalı — boşsa anahtar
            // hiç gönderilmemeli.
            $flowActionPayload = ['screen' => 'SERVICES'];
            if ($prefillData !== []) {
                $flowActionPayload['data'] = $prefillData;
            }
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $customer['whatsapp_number'],
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'flow',
                    'body' => ['text' => $bodyText],
                    'action' => [
                        'name' => 'flow',
                        'parameters' => [
                            'flow_message_version' => '3',
                            'flow_token' => $flowToken,
                            'flow_id' => $flowId,
                            'flow_cta' => 'Randevu Al',
                            'mode' => Env::get('META_FLOW_MODE', 'published'),
                            'flow_action' => 'navigate',
                            'flow_action_payload' => $flowActionPayload,
                        ],
                    ],
                ],
            ];

            $accessToken = TokenCipher::decrypt(hex2bin($tenant['access_token_hex']), Env::required('APP_ENCRYPTION_KEY'));
            $result = $this->meta->sendMessage($tenant['phone_number_id'], $accessToken, $payload);
            $success = $result['status'] >= 200 && $result['status'] < 300 && !isset($result['body']['error']);
            $metaErrorCode = $success ? null : (string) ($result['body']['error']['code'] ?? 'unknown');

            // Y3: token geçersiz (190) → tenant'ı disconnected işaretle.
            if (!$success && $metaErrorCode === '190') {
                $this->tenants->markDisconnected($tenantId);
            }

            $this->messageLogs->insert(
                $tenantId,
                $customerId,
                $appointmentId,
                'outbound',
                null,
                ['request' => $payload, 'response' => $result['body']],
                $success ? 'sent' : 'failed',
                $metaErrorCode,
                null
            );
        } catch (Throwable $e) {
            // Y1: sessiz yutma yok — teşhis için logla.
            error_log('WhatsAppNotifier::sendFlowTrigger: ' . $e->getMessage());
        }
    }
}
