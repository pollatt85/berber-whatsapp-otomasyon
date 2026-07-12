<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Config\Env;
use App\Http\Request;
use App\Http\Response;
use App\Repository\ServiceRepository;
use App\Repository\StaffRepository;
use App\Repository\TenantRepository;
use App\Service\AvailabilityService;
use App\Support\FlowCrypto;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * `POST /webhook/whatsapp-flow` — WhatsApp Flows data-exchange endpoint (randevu formu,
 * PHASE_35). HMAC yok: Meta'nın kendi RSA/AES şifrelemesi kimlik doğrulama + bütünlük
 * sağlıyor (`FlowCrypto`). Çözülemeyen istekte Meta'nın beklediği gibi düz metin **421** döner,
 * aksi halde her yanıt şifrelenip düz metin (base64) olarak döner — asla JSON sarmalayıcı yok.
 *
 * Ekranlar arası state, WhatsApp'ın kendisi taşır (her `data_exchange` isteği önceki ekranın
 * `data` alanlarını + o ekranda toplanan form değerlerini birlikte gönderir) — backend'de ayrı
 * bir oturum/state saklamaya gerek yok. Tenant kimliği `flow_token`'dan çözülür (mesaj
 * gönderilirken `"{tenant_id}:{customer_id}"` olarak set edilir, Meta bunu değiştirmeden
 * her istekte geri yollar).
 */
final class WhatsAppFlowController
{
    public function __construct(
        private ServiceRepository $services,
        private StaffRepository $staff,
        private AvailabilityService $availability,
        private TenantRepository $tenants
    ) {
    }

    public function handle(Request $request): Response
    {
        $body = $request->body;

        try {
            $privateKeyPem = file_get_contents(Env::required('WHATSAPP_FLOW_PRIVATE_KEY_PATH'));
            if ($privateKeyPem === false) {
                throw new \RuntimeException('WhatsApp Flow private key dosyası okunamadı.');
            }

            $decrypted = FlowCrypto::decrypt(
                (string) ($body['encrypted_flow_data'] ?? ''),
                (string) ($body['encrypted_aes_key'] ?? ''),
                (string) ($body['initial_vector'] ?? ''),
                $privateKeyPem
            );
        } catch (Throwable $e) {
            // Meta'nın beklediği sözleşme: şifre çözülemezse 421, gövde önemsiz.
            return Response::text('decryption failed', 421);
        }

        $payload = $decrypted['payload'];
        $aesKey = $decrypted['aesKey'];
        $iv = $decrypted['iv'];
        $action = (string) ($payload['action'] ?? '');

        if ($action === 'ping') {
            return $this->encrypted(['data' => ['status' => 'active']], $aesKey, $iv);
        }

        try {
            $flowToken = (string) ($payload['flow_token'] ?? '');
            $tenantId = strstr($flowToken, ':', true) ?: $flowToken;
            $data = (array) ($payload['data'] ?? []);
            $screen = (string) ($payload['screen'] ?? '');

            $rescheduleOf = (string) ($data['reschedule_of'] ?? '');

            if ($action === 'INIT') {
                return $this->encrypted($this->buildServicesScreen($tenantId, $rescheduleOf), $aesKey, $iv);
            }

            if ($action === 'data_exchange' && $screen === 'SERVICES') {
                $serviceId = (string) ($data['service_id'] ?? '');
                return $this->encrypted($this->buildStaffScreen($tenantId, $serviceId, $rescheduleOf), $aesKey, $iv);
            }

            if ($action === 'data_exchange' && ($screen === 'STAFF' || $screen === 'SLOT')) {
                $serviceId = (string) ($data['service_id'] ?? '');
                $staffId = (string) ($data['staff_id'] ?? '');
                $date = (string) ($data['date'] ?? '');
                return $this->encrypted($this->buildSlotScreen($tenantId, $serviceId, $staffId, $date, $rescheduleOf), $aesKey, $iv);
            }

            // Bilinmeyen action/screen — Meta'nın "acknowledged" sözleşmesi.
            return $this->encrypted(['data' => ['acknowledged' => true]], $aesKey, $iv);
        } catch (Throwable $e) {
            return $this->encrypted([
                'data' => ['error' => 'server_error', 'error_message' => 'Bir hata oluştu, lütfen tekrar deneyin.'],
            ], $aesKey, $iv);
        }
    }

    private function buildServicesScreen(string $tenantId, string $rescheduleOf): array
    {
        $rows = array_map(
            fn (array $s) => [
                'id' => $s['id'],
                'title' => (string) $s['name'],
                'description' => "{$s['duration_minutes']} dk - {$s['price']} TL",
            ],
            $this->services->all($tenantId)
        );

        return [
            'screen' => 'SERVICES',
            'data' => [
                'services' => $rows,
                'reschedule_of' => $rescheduleOf,
            ],
        ];
    }

    private function buildStaffScreen(string $tenantId, string $serviceId, string $rescheduleOf): array
    {
        $rows = array_map(
            fn (array $s) => ['id' => $s['id'], 'title' => (string) $s['name']],
            $this->staff->forService($tenantId, $serviceId)
        );

        return [
            'screen' => 'STAFF',
            'data' => [
                'service_id' => $serviceId,
                'reschedule_of' => $rescheduleOf,
                'staff' => $rows,
            ],
        ];
    }

    private function buildSlotScreen(string $tenantId, string $serviceId, string $staffId, string $requestedDate, string $rescheduleOf): array
    {
        $tenant = $this->tenants->findById($tenantId);
        $timezone = (string) ($tenant['timezone'] ?? 'Europe/Istanbul');
        $today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');

        $days = $this->availability->slotsForRange($tenantId, $staffId, $serviceId, $today, 7, $timezone);
        $dates = array_keys($days);
        $date = ($requestedDate !== '' && isset($days[$requestedDate])) ? $requestedDate : ($dates[0] ?? $today);

        $times = array_map(fn (string $t) => ['id' => $t, 'title' => $t], $days[$date] ?? []);

        return [
            'screen' => 'SLOT',
            'data' => [
                'service_id' => $serviceId,
                'staff_id' => $staffId,
                'reschedule_of' => $rescheduleOf,
                'min_date' => $dates[0] ?? $today,
                'max_date' => $dates[count($dates) - 1] ?? $today,
                'times' => $times,
            ],
        ];
    }

    private function encrypted(array $payload, string $aesKey, string $iv): Response
    {
        return Response::text(FlowCrypto::encrypt($payload, $aesKey, $iv));
    }
}
