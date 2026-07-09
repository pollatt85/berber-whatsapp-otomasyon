<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Tenant henüz çözülmeden çalışan sorgular; her zaman Connection::service() (BYPASSRLS) ile
 * kullanılır (03_Backend_API.md §3.1).
 */
final class TenantRepository
{
    public function __construct(private PDO $service)
    {
    }

    public function findByPhoneNumberId(string $phoneNumberId): ?array
    {
        $stmt = $this->service->prepare(
            'SELECT id, timezone, whatsapp_status,
                    (SELECT enabled FROM ai_settings WHERE tenant_id = tenants.id) AS ai_enabled
             FROM tenants WHERE phone_number_id = :phone_number_id'
        );
        $stmt->execute(['phone_number_id' => $phoneNumberId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findById(string $tenantId): ?array
    {
        $stmt = $this->service->prepare('SELECT * FROM tenants WHERE id = :id');
        $stmt->execute(['id' => $tenantId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * 05_WhatsApp_Integration.md §3: giden mesaj gönderimi için tenant'ın Meta kimlik bilgileri.
     * `access_token_hex` ham bytea yerine hex olarak döner (PDO_PGSQL bytea okuma tutarsızlığından
     * kaçınmak için) — App\Support\TokenCipher::decrypt(hex2bin(...), ...) ile çözülür.
     */
    public function findByIdWithToken(string $tenantId): ?array
    {
        $stmt = $this->service->prepare(
            "SELECT id, phone_number_id, waba_id, whatsapp_status,
                    encode(access_token_encrypted, 'hex') AS access_token_hex
             FROM tenants WHERE id = :id"
        );
        $stmt->execute(['id' => $tenantId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * 05_WhatsApp_Integration.md §5: Meta hata kodu `190` (token geçersiz/süresi dolmuş).
     */
    public function markDisconnected(string $tenantId): void
    {
        $stmt = $this->service->prepare("UPDATE tenants SET whatsapp_status = 'disconnected' WHERE id = :id");
        $stmt->execute(['id' => $tenantId]);
    }

    /**
     * 09_SaaS_Deployment.md §5, §6: platform admin route grubu — tüm tenant'ların özet listesi.
     */
    public function listAll(): array
    {
        $stmt = $this->service->query(
            'SELECT id, business_name, status, subscription_status, plan_id, whatsapp_status, created_at
             FROM tenants ORDER BY created_at DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * 09_SaaS_Deployment.md §6: platform admin bir tenant'ı askıya alabilir/aktive edebilir.
     */
    public function updateStatus(string $tenantId, string $status): ?array
    {
        $stmt = $this->service->prepare(
            'UPDATE tenants SET status = :status WHERE id = :id
             RETURNING id, business_name, status'
        );
        $stmt->execute(['status' => $status, 'id' => $tenantId]);

        return $stmt->fetch() ?: null;
    }
}
