<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class UserRepository
{
    public function __construct(private PDO $service)
    {
    }

    /**
     * Login anında tenant henüz bilinmediği için servis (BYPASSRLS) bağlantısıyla,
     * e-posta üzerinden arama yapılır (03_Backend_API.md §2.2).
     */
    public function findActiveByEmail(string $email): ?array
    {
        $stmt = $this->service->prepare(
            'SELECT id, tenant_id, email, password_hash, role
             FROM users WHERE email = :email AND active = true'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
