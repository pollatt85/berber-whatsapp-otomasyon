<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `services` tablosu (02_Database_Design.md §3.4). Tenant-scoped bağlantı üzerinden çalışır;
 * her sorguya WHERE tenant_id eklenir (RLS ikinci savunma katmanıdır, bkz. 02§5).
 */
final class ServiceRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function all(string $tenantId, bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM services WHERE tenant_id = :tenant_id';
        if ($onlyActive) {
            $sql .= ' AND active = true';
        }
        $sql .= ' ORDER BY name';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM services WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(string $tenantId, string $name, int $durationMinutes, string $price): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO services (tenant_id, name, duration_minutes, price)
             VALUES (:tenant_id, :name, :duration_minutes, :price)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'name' => $name,
            'duration_minutes' => $durationMinutes,
            'price' => $price,
        ]);

        return $stmt->fetch();
    }

    public function update(string $tenantId, string $id, array $fields): ?array
    {
        $allowed = ['name', 'duration_minutes', 'price', 'active'];
        $set = [];
        $params = ['tenant_id' => $tenantId, 'id' => $id];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        if ($set === []) {
            return $this->find($tenantId, $id);
        }

        $sql = 'UPDATE services SET ' . implode(', ', $set)
            . ' WHERE tenant_id = :tenant_id AND id = :id RETURNING *';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * 03_Backend_API.md §3.2: DELETE soft yapılır (active=false), kayıt silinmez.
     */
    public function deactivate(string $tenantId, string $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE services SET active = false WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
