<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Env;
use App\Support\TokenCipher;
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

    /**
     * A3: Platform admin panelinden yeni işletme aç. WhatsApp henüz bağlı değil — Embedded Signup
     * öncesi zorunlu alanlar placeholder ile doldurulur (`dev_seed.php` ile aynı kanıtlı desen):
     * phone_number_id/waba_id benzersiz 'pending-...' (UNIQUE kısıtı bozulmaz), access_token_encrypted
     * şifreli placeholder (bytea NOT NULL), whatsapp_status='pending'. `connectWhatsApp()` sonradan
     * bunların üzerine gerçek değerleri yazar. Çağıran taraf transaction ve plan doğrulamasını yönetir.
     */
    public function create(string $businessName, string $planName): array
    {
        $stmt = $this->service->prepare(
            "INSERT INTO tenants (business_name, phone_number_id, waba_id, access_token_encrypted,
                                  webhook_verify_token, whatsapp_status, plan_id)
             VALUES (:name, :pnid, :waba, :token, :verify, 'pending',
                     (SELECT id FROM plans WHERE name = :plan))
             RETURNING id, business_name, status, subscription_status, plan_id, whatsapp_status, created_at"
        );
        $stmt->bindValue('name', $businessName);
        $stmt->bindValue('pnid', 'pending-' . bin2hex(random_bytes(8)));
        $stmt->bindValue('waba', 'pending-' . bin2hex(random_bytes(8)));
        $stmt->bindValue('token', TokenCipher::encrypt('pending-placeholder', Env::required('APP_ENCRYPTION_KEY')), PDO::PARAM_LOB);
        $stmt->bindValue('verify', bin2hex(random_bytes(16)));
        $stmt->bindValue('plan', $planName);
        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * A3: Verilen ad'a sahip plan var mı? create() öncesi doğrulama — plan_id NOT NULL, olmayan
     * plan subquery'de NULL döner ve INSERT'i patlatır; önce burada 422 üretmek daha temiz.
     */
    public function planExists(string $planName): bool
    {
        $stmt = $this->service->prepare('SELECT 1 FROM plans WHERE name = :plan');
        $stmt->execute(['plan' => $planName]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * O4: Tenant'ın plan limitleri (`plans` kataloğu, tenant.plan_id üzerinden). Hiçbir yerde
     * okunmuyordu; StaffController (max_staff), CampaignController (campaigns_enabled),
     * AiSettingsController (ai_enabled) bu limitleri uygular. max_staff NULL = sınırsız.
     *
     * @return array{max_staff:?int, ai_enabled:bool, campaigns_enabled:bool}
     */
    public function planLimits(string $tenantId): array
    {
        $stmt = $this->service->prepare(
            'SELECT p.max_staff, p.ai_enabled, p.campaigns_enabled
             FROM tenants t JOIN plans p ON p.id = t.plan_id
             WHERE t.id = :id'
        );
        $stmt->execute(['id' => $tenantId]);
        $row = $stmt->fetch();

        return [
            'max_staff' => $row && $row['max_staff'] !== null ? (int) $row['max_staff'] : null,
            'ai_enabled' => (bool) ($row['ai_enabled'] ?? false),
            'campaigns_enabled' => (bool) ($row['campaigns_enabled'] ?? false),
        ];
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
     * Embedded Signup (05_WhatsApp_Integration.md §1 adım 3): token exchange + subscribed_apps
     * başarıyla tamamlandıktan sonra tenant'ın Meta kimlikleri tek seferde yazılır.
     * $encryptedTokenHex, TokenCipher::encrypt çıktısının bin2hex'i — bytea'ya decode ile girer
     * (findByIdWithToken'ın encode(...,'hex') okumasının simetriği).
     */
    public function connectWhatsApp(string $tenantId, string $phoneNumberId, string $wabaId, string $encryptedTokenHex): ?array
    {
        $stmt = $this->service->prepare(
            "UPDATE tenants
             SET phone_number_id = :phone_number_id, waba_id = :waba_id,
                 access_token_encrypted = decode(:token_hex, 'hex'),
                 whatsapp_status = 'connected', updated_at = now()
             WHERE id = :id
             RETURNING id, business_name, phone_number_id, waba_id, whatsapp_status"
        );
        $stmt->execute([
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'token_hex' => $encryptedTokenHex,
            'id' => $tenantId,
        ]);

        return $stmt->fetch() ?: null;
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
