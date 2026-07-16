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
     *
     * users.email tenant başına UNIQUE'tir, GLOBAL değil — aynı e-posta birden fazla tenant'ta
     * bulunabilir. Bu yüzden tek satır (`fetch`) değil, TÜM eşleşen aktif satırlar döndürülür;
     * çağıran taraf parolaya göre süzer ve çokluğu 409 ile ele alır (Y5). Aksi halde `fetch()`
     * rastgele bir tenant'a giriş yaptırırdı.
     *
     * @return list<array{id:string,tenant_id:string,email:string,password_hash:string,role:string,tenant_status:string}>
     */
    public function findActiveByEmail(string $email): array
    {
        $stmt = $this->service->prepare(
            'SELECT u.id, u.tenant_id, u.email, u.password_hash, u.role, t.status AS tenant_status
             FROM users u
             JOIN tenants t ON t.id = u.tenant_id
             WHERE u.email = :email AND u.active = true'
        );
        $stmt->execute(['email' => $email]);

        return $stmt->fetchAll();
    }

    /**
     * A3: E-posta herhangi bir tenant'ta kayıtlı mı? users.email tenant başına UNIQUE (global değil),
     * ama login (findActiveByEmail) e-postayı tenant'lar arası ayırt edemez — aynı e-posta iki
     * tenant'ta olursa o kullanıcı 409 ile kilitlenir (Y5). Bu yüzden yeni işletme açarken owner
     * e-postası TÜM tenant'larda benzersiz olmalı; oluşturmadan önce burada kontrol edilir.
     * (email citext → karşılaştırma zaten büyük/küçük harf duyarsız.)
     */
    public function emailExists(string $email): bool
    {
        $stmt = $this->service->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * A3: Yeni işletmenin owner kullanıcısı. Şifre `password_hash(PASSWORD_DEFAULT)` ile hash'lenir
     * (çağıran taraf düz parolayı verir). users(tenant_id, email) UNIQUE — aynı tenant'ta tekrar
     * eklenirse 23505; çağıran transaction bunu ele alır. Servis (BYPASSRLS) bağlantısı beklenir.
     */
    public function create(string $tenantId, string $email, string $passwordHash, string $role = 'owner'): array
    {
        $stmt = $this->service->prepare(
            "INSERT INTO users (tenant_id, email, password_hash, role)
             VALUES (:tenant_id, :email, :hash, :role)
             RETURNING id, tenant_id, email, role"
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'email' => $email,
            'hash' => $passwordHash,
            'role' => $role,
        ]);

        return $stmt->fetch();
    }
}
