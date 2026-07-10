<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `staff` tablosu (02_Database_Design.md §3.3).
 */
final class StaffRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function all(string $tenantId, bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM staff WHERE tenant_id = :tenant_id';
        if ($onlyActive) {
            $sql .= ' AND active = true';
        }
        $sql .= ' ORDER BY name';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll();
    }

    /**
     * 03_Backend_API.md §3.2: GET /staff?service_id= — bir hizmeti verebilen personeller.
     */
    public function forService(string $tenantId, string $serviceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.* FROM staff s
             JOIN staff_services ss ON ss.staff_id = s.id
             WHERE s.tenant_id = :tenant_id AND ss.service_id = :service_id AND s.active = true
             ORDER BY s.name'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'service_id' => $serviceId]);

        return $stmt->fetchAll();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(string $tenantId, string $name, ?string $phone): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO staff (tenant_id, name, phone) VALUES (:tenant_id, :name, :phone) RETURNING *'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'name' => $name, 'phone' => $phone]);

        return $stmt->fetch();
    }

    public function update(string $tenantId, string $id, array $fields): ?array
    {
        $allowed = ['name', 'phone', 'photo_url', 'active'];
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

        $sql = 'UPDATE staff SET ' . implode(', ', $set)
            . ' WHERE tenant_id = :tenant_id AND id = :id RETURNING *';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function deactivate(string $tenantId, string $id): bool
    {
        $stmt = $this->db->prepare('UPDATE staff SET active = false WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<int, array{day_of_week:int, start_time:string, end_time:string}>
     */
    public function workingHours(string $tenantId, string $staffId, int $dayOfWeek): array
    {
        $stmt = $this->db->prepare(
            'SELECT day_of_week, start_time, end_time FROM working_hours
             WHERE tenant_id = :tenant_id AND staff_id = :staff_id AND day_of_week = :day
             ORDER BY start_time'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'staff_id' => $staffId, 'day' => $dayOfWeek]);

        return $stmt->fetchAll();
    }

    public function breaks(string $tenantId, string $staffId, int $dayOfWeek): array
    {
        $stmt = $this->db->prepare(
            'SELECT day_of_week, start_time, end_time FROM breaks
             WHERE tenant_id = :tenant_id AND staff_id = :staff_id AND day_of_week = :day
             ORDER BY start_time'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'staff_id' => $staffId, 'day' => $dayOfWeek]);

        return $stmt->fetchAll();
    }

    /**
     * `staff_id IS NULL` = tüm işletme kapalı (02_Database_Design.md §3.8).
     */
    public function hasHolidayOn(string $tenantId, string $staffId, string $date): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM holidays
             WHERE tenant_id = :tenant_id AND (staff_id = :staff_id OR staff_id IS NULL)
               AND date_range @> :date::date
             LIMIT 1'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'staff_id' => $staffId, 'date' => $date]);

        return (bool) $stmt->fetch();
    }
}
