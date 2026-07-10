<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `campaigns` tablosu (02_Database_Design.md §3.13, 06_Admin_Panel.md §7).
 * Bu repository panelin taslak/planlama CRUD'unu taşır. Gönderim (`status`'un `sent`'e
 * geçişi) burada değil `CampaignScanRepository`'de olur — n8n servis kanalının atomik
 * claim'i, panel kullanıcısının elle düzenlemesiyle çakışmaması için ayrı tutuldu.
 */
final class CampaignRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function listAll(string $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, t.internal_name AS template_name, t.active AS template_active
             FROM campaigns c
             JOIN message_templates t ON t.id = c.template_id
             WHERE c.tenant_id = :tenant_id
             ORDER BY c.created_at DESC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM campaigns WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(
        string $tenantId,
        string $name,
        string $templateId,
        array $targetFilter,
        ?string $scheduledAt,
        string $status
    ): array {
        $stmt = $this->db->prepare(
            'INSERT INTO campaigns (tenant_id, name, template_id, target_filter, scheduled_at, status)
             VALUES (:tenant_id, :name, :template_id, :target_filter, :scheduled_at, :status)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'name' => $name,
            'template_id' => $templateId,
            'target_filter' => json_encode($targetFilter, JSON_UNESCAPED_UNICODE),
            'scheduled_at' => $scheduledAt,
            'status' => $status,
        ]);

        return $stmt->fetch();
    }

    public function update(
        string $tenantId,
        string $id,
        string $name,
        string $templateId,
        array $targetFilter,
        ?string $scheduledAt,
        string $status
    ): ?array {
        $stmt = $this->db->prepare(
            "UPDATE campaigns
             SET name = :name, template_id = :template_id, target_filter = :target_filter,
                 scheduled_at = :scheduled_at, status = :status
             WHERE tenant_id = :tenant_id AND id = :id AND status IN ('draft', 'scheduled')
             RETURNING *"
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'id' => $id,
            'name' => $name,
            'template_id' => $templateId,
            'target_filter' => json_encode($targetFilter, JSON_UNESCAPED_UNICODE),
            'scheduled_at' => $scheduledAt,
            'status' => $status,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** draft/scheduled → cancelled; terminal durumlar (sent/cancelled) değişmez. */
    public function cancel(string $tenantId, string $id): ?array
    {
        $stmt = $this->db->prepare(
            "UPDATE campaigns SET status = 'cancelled'
             WHERE tenant_id = :tenant_id AND id = :id AND status IN ('draft', 'scheduled')
             RETURNING *"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
