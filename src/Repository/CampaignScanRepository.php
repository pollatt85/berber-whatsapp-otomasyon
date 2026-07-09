<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Kampanya gönderim taraması (BACKLOG.md §A madde 26, 06_Admin_Panel.md §7). Servis rolüyle
 * (BYPASSRLS) çalışır — tüm tenant'ların vadesi gelmiş kampanyaları tek sorguda taranır.
 *
 * Claim, `AppointmentScanRepository`'deki idempotency-check deseninden farklı: appointment
 * taramasında her satır `message_log`'daki idempotency_key varlığıyla kontrol edilirken,
 * bir kampanya N müşteriye fan-out olduğundan aynı garanti burada pratik değil. Bunun yerine
 * `campaigns` satırı `UPDATE ... WHERE status='scheduled' RETURNING *` ile atomik olarak
 * "sent" durumuna claim edilir — Postgres'in satır kilidi aynı kampanyanın iki worker
 * tarafından eşzamanlı claim edilmesini zaten engeller; advisory lock (madde 15 deseni) buna
 * ek bir tur-bazlı mutex katmanı. Trade-off: claim, gerçek Meta gönderiminden ÖNCE gerçekleşir
 * (reminder taramasındaki gibi "en az bir kez" garantisi yok) — n8n bu adımdan sonra çökerse
 * bazı müşteriler mesajı kaçırabilir; kampanyalar zaten en-iyi-çaba (best-effort) toplu
 * gönderim olduğundan bu kabul edilebilir (05§3, appointment hatırlatmalarının aksine kritik
 * randevu bilgisi taşımıyor).
 */
final class CampaignScanRepository
{
    private const LOCK_KEY = 727003;

    public function __construct(private PDO $service)
    {
    }

    /**
     * @return array<int, array{campaign_id:string, tenant_id:string, template_internal_name:string,
     *                           customer_id:string, whatsapp_number:string, customer_name:?string}>
     */
    public function dueForSend(): array
    {
        $stmt = $this->service->prepare('SELECT pg_try_advisory_lock(:key) AS locked');
        $stmt->execute(['key' => self::LOCK_KEY]);
        $locked = (bool) $stmt->fetch()['locked'];

        if (!$locked) {
            return [];
        }

        try {
            $stmt = $this->service->query(
                "WITH claimed AS (
                    UPDATE campaigns
                    SET status = 'sent', sent_at = now()
                    WHERE status = 'scheduled' AND scheduled_at <= now()
                    RETURNING id, tenant_id, template_id, target_filter
                 )
                 SELECT
                     cl.id AS campaign_id,
                     cl.tenant_id,
                     t.internal_name AS template_internal_name,
                     c.id AS customer_id,
                     c.whatsapp_number,
                     c.name AS customer_name
                 FROM claimed cl
                 JOIN message_templates t ON t.id = cl.template_id
                 JOIN customers c ON c.tenant_id = cl.tenant_id
                 WHERE c.whatsapp_number NOT LIKE 'deleted-%'
                   AND (
                       NOT (cl.target_filter ?? 'last_visit_min_days')
                       OR NOT EXISTS (
                           SELECT 1 FROM appointments a
                           WHERE a.customer_id = c.id AND a.status = 'completed'
                             AND lower(a.time_range) > now() -
                                 ((cl.target_filter->>'last_visit_min_days')::int || ' days')::interval
                       )
                   )
                 ORDER BY cl.id, c.id"
            );

            return $stmt->fetchAll();
        } finally {
            $unlock = $this->service->prepare('SELECT pg_advisory_unlock(:key)');
            $unlock->execute(['key' => self::LOCK_KEY]);
        }
    }
}
