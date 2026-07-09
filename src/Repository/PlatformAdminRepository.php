<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `platform_admins` tablosu (09_SaaS_Deployment.md §5, §6). Tenant'lar üstü rol, RLS/tenant_id
 * yok — her zaman Connection::service() (BYPASSRLS) ile kullanılır.
 */
final class PlatformAdminRepository
{
    public function __construct(private PDO $service)
    {
    }

    public function findActiveByEmail(string $email): ?array
    {
        $stmt = $this->service->prepare(
            'SELECT id, email, password_hash FROM platform_admins WHERE email = :email AND active = true'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
