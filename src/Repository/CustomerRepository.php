<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `customers` tablosu (02_Database_Design.md §3.9). 03_Backend_API.md §3.5: n8n ilk temasta
 * upsert eder (whatsapp_number + tenant_id benzersiz).
 */
final class CustomerRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByWhatsappNumber(string $tenantId, string $whatsappNumber): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM customers WHERE tenant_id = :tenant_id AND whatsapp_number = :whatsapp_number'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'whatsapp_number' => $whatsappNumber]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Panel liste görünümü (06§6): isim/telefon araması + her satırda son randevu tarihi.
     * İptal edilen randevular "son randevu" sayılmaz; anonimleştirilmiş müşteriler geçmiş
     * kayıt olarak listede kalır (satır silinmediği için).
     */
    public function list(string $tenantId, ?string $search): array
    {
        $sql = "SELECT c.id, c.name, c.whatsapp_number, c.created_at,
                       max(lower(a.time_range)) FILTER (WHERE a.status <> 'cancelled') AS last_appointment_at,
                       count(a.id) FILTER (WHERE a.status <> 'cancelled') AS appointment_count
                FROM customers c
                LEFT JOIN appointments a ON a.tenant_id = c.tenant_id AND a.customer_id = c.id
                WHERE c.tenant_id = :tenant_id";
        $params = ['tenant_id' => $tenantId];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (c.name ILIKE :search OR c.whatsapp_number ILIKE :search_num)';
            $params['search'] = '%' . $search . '%';
            $params['search_num'] = '%' . $search . '%';
        }

        $sql .= ' GROUP BY c.id ORDER BY max(lower(a.time_range)) DESC NULLS LAST, c.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function upsert(string $tenantId, string $whatsappNumber, ?string $name): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO customers (tenant_id, whatsapp_number, name)
             VALUES (:tenant_id, :whatsapp_number, :name)
             ON CONFLICT (tenant_id, whatsapp_number)
             DO UPDATE SET name = COALESCE(EXCLUDED.name, customers.name)
             RETURNING *'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'whatsapp_number' => $whatsappNumber, 'name' => $name]);

        return $stmt->fetch();
    }

    /**
     * KVKK/GDPR silme talebi (06_Admin_Panel.md §6, 09_SaaS_Deployment.md §6 madde 10).
     * Randevu geçmişi `appointments.customer_id` (ON DELETE RESTRICT) tarafından korunduğu için
     * gerçek satır silinmez; kimliği ifşa eden alanlar anonimleştirilir.
     */
    public function anonymize(string $tenantId, string $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE customers SET name = NULL, whatsapp_number = 'deleted-' || id
             WHERE tenant_id = :tenant_id AND id = :id AND whatsapp_number NOT LIKE 'deleted-%'"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
