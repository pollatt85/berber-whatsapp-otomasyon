<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;

/**
 * Backend'in Meta'dan aldığı ve tenant'ını çözdüğü gelen mesajı n8n'e iletir
 * (04_n8n_Workflows.md §1/§2 — n8n orkestrasyonu Backend'in çözdüğü olaylarla tetiklenir,
 * ham Meta trafiğini doğrudan görmez). `N8N_INCOMING_WEBHOOK_URL` boşsa (n8n henüz
 * kurulmamış/yerel geliştirme) sessizce atlanır — Meta'ya verilen yanıtı bloklamaz, olay
 * zaten `webhook_events`'te durur (BACKLOG.md §B).
 *
 * n8n tarafı, aynı paylaşılan sırrı (`N8N_SERVICE_SECRET`) `X-Signature` HMAC'i olarak
 * doğrular — ServiceHmacMiddleware'in ayna görüntüsü, yön tersine döner.
 */
final class N8nNotifier
{
    public function notifyIncomingMessage(string $tenantId, string $phoneNumberId, array $payload): void
    {
        $url = Env::get('N8N_INCOMING_WEBHOOK_URL', '');
        if ($url === null || $url === '') {
            return;
        }

        $body = json_encode([
            'tenant_id' => $tenantId,
            'phone_number_id' => $phoneNumberId,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE);

        $signature = hash_hmac('sha256', $body, Env::required('N8N_SERVICE_SECRET'));

        // Gerçek fire-and-forget: Backend PHP built-in server tek iş parçacıklıdır — bu istek
        // n8n'in tüm workflow'u bitirmesini (birkaç saniye sürebilir, ör. müsaitlik taraması)
        // beklerse, backend o süre boyunca BAŞKA hiçbir isteğe (n8n'in bu workflow içinde
        // backend'e geri çağırdığı Upsert Customer/Get Availability/Send gibi uçlar dahil)
        // cevap veremez — kendi kendini kilitler (bu oturumda gözlemlendi: bir adım
        // gereksiz yere 15+ saniye sürdü). Çözüm: yanıtı hiç bekleme, isteği gönderip
        // birkaç yüz milisaniye içinde vazgeç — n8n isteği alıp işlemeye devam eder,
        // backend'in bunu bilmesine gerek yok (dönüş değeri zaten kullanılmıyor).
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "X-Signature: {$signature}"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 400);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 300);

        try {
            curl_exec($ch);
        } finally {
            curl_close($ch);
        }
    }
}
