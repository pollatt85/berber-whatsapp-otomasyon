<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `message_templates` tablosu (02_Database_Design.md §3.11, 05_WhatsApp_Integration.md §3, §6).
 */
final class MessageTemplateRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Panel şablon listesi (06_Admin_Panel.md §7) — salt okunur görünüm.
     */
    public function listAll(string $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM message_templates WHERE tenant_id = :tenant_id
             ORDER BY active DESC, template_type, internal_name'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM message_templates WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByInternalName(string $tenantId, string $internalName): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM message_templates WHERE tenant_id = :tenant_id AND internal_name = :internal_name'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'internal_name' => $internalName]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function findByMetaName(string $tenantId, string $metaTemplateName): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM message_templates WHERE tenant_id = :tenant_id AND meta_template_name = :meta_name'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'meta_name' => $metaTemplateName]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * 05_WhatsApp_Integration.md §6: yalnızca okuma senkronizasyonu. Mevcut şablonun `active`
     * durumu güncellenir; Meta'da yeni görülen ama yerelde henüz bilinmeyen şablon, sonradan
     * panelden kategorize edilmek üzere `template_type='other'` ile oluşturulur.
     */
    public function upsertFromSync(string $tenantId, string $metaTemplateName, bool $active): array
    {
        $existing = $this->findByMetaName($tenantId, $metaTemplateName);
        if ($existing !== null) {
            $stmt = $this->db->prepare(
                'UPDATE message_templates SET active = :active WHERE tenant_id = :tenant_id AND id = :id RETURNING *'
            );
            $stmt->execute(['active' => $active, 'tenant_id' => $tenantId, 'id' => $existing['id']]);

            return $stmt->fetch();
        }

        $stmt = $this->db->prepare(
            "INSERT INTO message_templates (tenant_id, internal_name, meta_template_name, template_type, active)
             VALUES (:tenant_id, :internal_name, :meta_name, 'other', :active)
             RETURNING *"
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'internal_name' => $metaTemplateName,
            'meta_name' => $metaTemplateName,
            'active' => $active,
        ]);

        return $stmt->fetch();
    }
}
