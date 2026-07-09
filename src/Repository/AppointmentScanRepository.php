<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * n8n cron'larının tüm-tenant tarama uçları (04_n8n_Workflows.md §5, §6, §7). Servis rolüyle
 * (BYPASSRLS) çalışır — tenant context'i yoktur, sorgu her tenant'ı `tenants` tablosundaki
 * kendi `reminder_hours_before`/`pending_ttl_minutes` değeriyle değerlendirir.
 *
 * Postgres advisory lock (09_SaaS_Deployment.md §6 madde 15): birden fazla n8n worker/instance
 * aynı taramayı eşzamanlı tetiklerse yalnızca biri çalışır, diğerleri boş sonuç döner —
 * aynı hatırlatmanın/iptalin iki kez tetiklenmesini engeller.
 */
final class AppointmentScanRepository
{
    private const REMINDER_LOCK_KEY = 727001;
    private const EXPIRED_PENDING_LOCK_KEY = 727002;

    public function __construct(private PDO $service)
    {
    }

    public function dueForReminder(): array
    {
        return $this->withAdvisoryLock(self::REMINDER_LOCK_KEY, function (): array {
            $stmt = $this->service->query(
                "SELECT a.*, c.whatsapp_number, c.name AS customer_name
                 FROM appointments a
                 JOIN tenants t ON t.id = a.tenant_id
                 JOIN customers c ON c.id = a.customer_id
                 WHERE a.status = 'confirmed'
                   AND (lower(a.time_range) - (t.reminder_hours_before || ' hours')::interval)
                       BETWEEN now() - interval '15 minutes' AND now()
                   AND NOT EXISTS (
                       SELECT 1 FROM message_log ml
                       WHERE ml.tenant_id = a.tenant_id AND ml.idempotency_key = a.id::text || '_reminder'
                   )
                 ORDER BY lower(a.time_range)"
            );

            return $stmt->fetchAll();
        });
    }

    public function expiredPending(): array
    {
        return $this->withAdvisoryLock(self::EXPIRED_PENDING_LOCK_KEY, function (): array {
            $stmt = $this->service->query(
                "SELECT a.*
                 FROM appointments a
                 JOIN tenants t ON t.id = a.tenant_id
                 WHERE a.status = 'pending'
                   AND a.created_at + (t.pending_ttl_minutes || ' minutes')::interval < now()
                 ORDER BY a.created_at"
            );

            return $stmt->fetchAll();
        });
    }

    private function withAdvisoryLock(int $lockKey, callable $work): array
    {
        $stmt = $this->service->prepare('SELECT pg_try_advisory_lock(:key) AS locked');
        $stmt->execute(['key' => $lockKey]);
        $locked = (bool) $stmt->fetch()['locked'];

        if (!$locked) {
            return [];
        }

        try {
            return $work();
        } finally {
            $unlock = $this->service->prepare('SELECT pg_advisory_unlock(:key)');
            $unlock->execute(['key' => $lockKey]);
        }
    }
}
