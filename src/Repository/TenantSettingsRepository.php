<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Panelden (JWT, tenant-scoped bağlantı) yapılan `tenants` öz-ayar güncellemeleri
 * (06_Admin_Panel.md §8). `tenants` kök tablo olduğu için RLS politikası yoktur (02§5) —
 * izolasyon burada WHERE id = :tenant_id ile, tenant_id JWT claim'inden geldiği için güvenlidir.
 */
final class TenantSettingsRepository
{
    /**
     * Panelin görebileceği tenant alanları — access_token_encrypted / webhook_verify_token
     * gibi sırlar asla dahil edilmez (05§1).
     */
    private const VISIBLE_COLUMNS = 'id, business_name, logo_url, address, location_lat, location_lng,
        timezone, phone_number_id, whatsapp_status, reminder_hours_before, pending_ttl_minutes';

    /** 06§8: panelden düzenlenebilir alanlar. whatsapp_status bilinçli olarak yok —
     *  yalnızca Embedded Signup/webhook akışı değiştirir (05§1). */
    private const EDITABLE = [
        'business_name', 'address', 'location_lat', 'location_lng', 'timezone',
        'reminder_hours_before', 'pending_ttl_minutes',
    ];

    public function __construct(private PDO $db)
    {
    }

    public function find(string $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::VISIBLE_COLUMNS . ' FROM tenants WHERE id = :id'
        );
        $stmt->execute(['id' => $tenantId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateFields(string $tenantId, array $fields): ?array
    {
        $set = [];
        $params = ['id' => $tenantId];

        foreach ($fields as $key => $value) {
            if (!in_array($key, self::EDITABLE, true)) {
                continue;
            }
            $set[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        if ($set === []) {
            return $this->find($tenantId);
        }

        $sql = 'UPDATE tenants SET ' . implode(', ', $set) . ', updated_at = now()
                WHERE id = :id RETURNING ' . self::VISIBLE_COLUMNS;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateLogoUrl(string $tenantId, string $logoUrl): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE tenants SET logo_url = :logo_url, updated_at = now() WHERE id = :id RETURNING id, logo_url'
        );
        $stmt->execute(['logo_url' => $logoUrl, 'id' => $tenantId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
