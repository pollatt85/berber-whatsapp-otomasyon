<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `appointments` tablosu (02_Database_Design.md §3.10). Çakışma kontrolü uygulama kodunda
 * değil DB'nin `EXCLUDE USING gist` kısıtında yapılır (02§6, 03§4) — bu repository yalnızca
 * INSERT/UPDATE dener ve 23P01/23505'i controller'a yansıtır.
 */
final class AppointmentRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Belirli bir personelin, verilen gün aralığında (00:00-24:00) aktif randevuları.
     * 03_Backend_API.md §4 adım 4.
     */
    public function activeForStaffOnDate(string $tenantId, string $staffId, string $dayStartIso, string $dayEndIso): array
    {
        $stmt = $this->db->prepare(
            "SELECT time_range FROM appointments
             WHERE tenant_id = :tenant_id AND staff_id = :staff_id
               AND status IN ('pending','confirmed')
               AND time_range && tstzrange(:day_start, :day_end)"
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'day_start' => $dayStartIso,
            'day_end' => $dayEndIso,
        ]);

        return $stmt->fetchAll();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM appointments WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByIdempotencyKey(string $tenantId, string $idempotencyKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM appointments WHERE tenant_id = :tenant_id AND idempotency_key = :key'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'key' => $idempotencyKey]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @throws \PDOException SQLSTATE 23P01 (exclusion_violation) çakışan slot, 23505 idempotency tekrarı
     */
    public function create(
        string $tenantId,
        string $customerId,
        string $staffId,
        string $serviceId,
        string $startIso,
        string $endIso,
        ?string $idempotencyKey
    ): array {
        $stmt = $this->db->prepare(
            'INSERT INTO appointments (tenant_id, customer_id, staff_id, service_id, time_range, status, idempotency_key)
             VALUES (:tenant_id, :customer_id, :staff_id, :service_id, tstzrange(:start_iso, :end_iso), \'pending\', :idempotency_key)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'staff_id' => $staffId,
            'service_id' => $serviceId,
            'start_iso' => $startIso,
            'end_iso' => $endIso,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $stmt->fetch();
    }

    /**
     * 03_Backend_API.md §3.4/§5: eskiyi cancel edip yeni satır açmak yerine `time_range`
     * yerinde güncellenir (exclusion constraint UPDATE'te de geçerlidir, §5).
     *
     * @throws \PDOException SQLSTATE 23P01 (exclusion_violation) çakışan slot
     */
    public function reschedule(string $tenantId, string $id, string $startIso, string $endIso): ?array
    {
        $stmt = $this->db->prepare(
            "UPDATE appointments SET time_range = tstzrange(:start_iso, :end_iso), updated_at = now()
             WHERE tenant_id = :tenant_id AND id = :id AND status IN ('pending','confirmed')
             RETURNING *"
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'id' => $id,
            'start_iso' => $startIso,
            'end_iso' => $endIso,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * 03_Backend_API.md §5 durum makinesi: yalnızca izin verilen geçişlere satır döner.
     */
    public function transition(string $tenantId, string $id, string $fromStatuses, string $toStatus): ?array
    {
        $sql = "UPDATE appointments SET status = :to_status, updated_at = now()
                WHERE tenant_id = :tenant_id AND id = :id AND status IN ({$fromStatuses})
                RETURNING *";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id, 'to_status' => $toStatus]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function listByFilters(string $tenantId, ?string $staffId, ?string $date, ?string $status, ?string $customerId = null): array
    {
        // Panel liste görünümü (06§4) ad kolonlarına ihtiyaç duyar — JOIN'ler ek alan döndürür,
        // mevcut alanları değiştirmez (n8n çağrıları için geriye uyumlu).
        $sql = 'SELECT a.*, c.name AS customer_name, c.whatsapp_number AS customer_whatsapp,
                       st.name AS staff_name, sv.name AS service_name
                FROM appointments a
                JOIN customers c ON c.id = a.customer_id
                JOIN staff st ON st.id = a.staff_id
                JOIN services sv ON sv.id = a.service_id
                WHERE a.tenant_id = :tenant_id';
        $params = ['tenant_id' => $tenantId];

        if ($staffId !== null) {
            $sql .= ' AND a.staff_id = :staff_id';
            $params['staff_id'] = $staffId;
        }
        if ($status !== null) {
            $sql .= ' AND a.status = :status';
            $params['status'] = $status;
        }
        if ($date !== null) {
            $sql .= " AND a.time_range && tstzrange(:date::date, :date::date + interval '1 day')";
            $params['date'] = $date;
        }
        if ($customerId !== null) {
            $sql .= ' AND a.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        $sql .= ' ORDER BY lower(a.time_range)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
