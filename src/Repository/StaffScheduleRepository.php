<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `working_hours` + `breaks` + `holidays` (02_Database_Design.md §3.6-§3.8) panel yönetimi
 * (06_Admin_Panel.md §5, /staff/{id}/hours). Haftalık program tek PUT ile tam değiştirilir
 * (replace) — satır bazlı diff yerine sil+yaz, form UI'ının doğal karşılığı.
 */
final class StaffScheduleRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array{working_hours: array, breaks: array, holidays: array} */
    public function schedule(string $tenantId, string $staffId): array
    {
        $hours = $this->db->prepare(
            'SELECT id, day_of_week, start_time, end_time FROM working_hours
             WHERE tenant_id = :tenant_id AND staff_id = :staff_id
             ORDER BY day_of_week, start_time'
        );
        $hours->execute(['tenant_id' => $tenantId, 'staff_id' => $staffId]);

        $breaks = $this->db->prepare(
            'SELECT id, day_of_week, start_time, end_time FROM breaks
             WHERE tenant_id = :tenant_id AND staff_id = :staff_id
             ORDER BY day_of_week, start_time'
        );
        $breaks->execute(['tenant_id' => $tenantId, 'staff_id' => $staffId]);

        $holidays = $this->db->prepare(
            'SELECT id, lower(date_range) AS start_date,
                    (upper(date_range) - 1) AS end_date, reason
             FROM holidays
             WHERE tenant_id = :tenant_id AND staff_id = :staff_id
             ORDER BY lower(date_range)'
        );
        $holidays->execute(['tenant_id' => $tenantId, 'staff_id' => $staffId]);

        return [
            'working_hours' => $hours->fetchAll(),
            'breaks' => $breaks->fetchAll(),
            'holidays' => $holidays->fetchAll(),
        ];
    }

    /**
     * @param array<int, array{day_of_week:int, start_time:string, end_time:string}> $workingHours
     * @param array<int, array{day_of_week:int, start_time:string, end_time:string}> $breaks
     */
    public function replace(string $tenantId, string $staffId, array $workingHours, array $breaks): void
    {
        $this->db->beginTransaction();
        try {
            foreach (['working_hours', 'breaks'] as $table) {
                $stmt = $this->db->prepare(
                    "DELETE FROM {$table} WHERE tenant_id = :tenant_id AND staff_id = :staff_id"
                );
                $stmt->execute(['tenant_id' => $tenantId, 'staff_id' => $staffId]);
            }

            $insertHour = $this->db->prepare(
                'INSERT INTO working_hours (tenant_id, staff_id, day_of_week, start_time, end_time)
                 VALUES (:tenant_id, :staff_id, :day_of_week, :start_time, :end_time)'
            );
            foreach ($workingHours as $row) {
                $insertHour->execute([
                    'tenant_id' => $tenantId,
                    'staff_id' => $staffId,
                    'day_of_week' => $row['day_of_week'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                ]);
            }

            $insertBreak = $this->db->prepare(
                'INSERT INTO breaks (tenant_id, staff_id, day_of_week, start_time, end_time)
                 VALUES (:tenant_id, :staff_id, :day_of_week, :start_time, :end_time)'
            );
            foreach ($breaks as $row) {
                $insertBreak->execute([
                    'tenant_id' => $tenantId,
                    'staff_id' => $staffId,
                    'day_of_week' => $row['day_of_week'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Tarihler dahil aralık: [start, end] → daterange '[start, end+1)'. */
    public function addHoliday(string $tenantId, string $staffId, string $startDate, string $endDate, ?string $reason): array
    {
        $stmt = $this->db->prepare(
            "INSERT INTO holidays (tenant_id, staff_id, date_range, reason)
             VALUES (:tenant_id, :staff_id, daterange(:start_date::date, :end_date::date + 1, '[)'), :reason)
             RETURNING id, lower(date_range) AS start_date, (upper(date_range) - 1) AS end_date, reason"
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => $reason,
        ]);

        return $stmt->fetch();
    }

    public function deleteHoliday(string $tenantId, string $staffId, string $holidayId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM holidays WHERE tenant_id = :tenant_id AND staff_id = :staff_id AND id = :id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'staff_id' => $staffId, 'id' => $holidayId]);

        return $stmt->rowCount() > 0;
    }

    /** @return string[] personelin verebildiği hizmet id'leri (staff_services, 02§3.5) */
    public function serviceIds(string $tenantId, string $staffId): array
    {
        $stmt = $this->db->prepare(
            'SELECT service_id FROM staff_services WHERE tenant_id = :tenant_id AND staff_id = :staff_id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'staff_id' => $staffId]);

        return array_column($stmt->fetchAll(), 'service_id');
    }

    /** @param string[] $serviceIds */
    public function replaceServices(string $tenantId, string $staffId, array $serviceIds): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM staff_services WHERE tenant_id = :tenant_id AND staff_id = :staff_id'
            );
            $stmt->execute(['tenant_id' => $tenantId, 'staff_id' => $staffId]);

            $insert = $this->db->prepare(
                'INSERT INTO staff_services (tenant_id, staff_id, service_id)
                 SELECT :tenant_id, :staff_id, id FROM services
                 WHERE tenant_id = :tenant_id2 AND id = :service_id'
            );
            foreach ($serviceIds as $serviceId) {
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'staff_id' => $staffId,
                    'tenant_id2' => $tenantId,
                    'service_id' => $serviceId,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
