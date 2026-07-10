<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\StaffRepository;
use App\Repository\StaffScheduleRepository;

/**
 * `/staff/{id}/schedule` + `/staff/{id}/holidays` + `/staff/{id}/services`
 * (06_Admin_Panel.md §5 — haftalık çalışma saati, molalar, tatil takvimi, hizmet ataması).
 */
final class StaffScheduleController
{
    public function __construct(
        private StaffScheduleRepository $schedule,
        private StaffRepository $staff
    ) {
    }

    public function show(Request $request, string $tenantId, string $staffId): Response
    {
        $this->assertStaff($tenantId, $staffId);

        return Response::json(['data' => $this->schedule->schedule($tenantId, $staffId)]);
    }

    /** PUT — haftalık programın tamamını değiştirir (working_hours + breaks). */
    public function replace(Request $request, string $tenantId, string $staffId): Response
    {
        $this->assertStaff($tenantId, $staffId);

        $hours = $this->validateSlots($request->input('working_hours', []), 'working_hours');
        $breaks = $this->validateSlots($request->input('breaks', []), 'breaks');

        $this->schedule->replace($tenantId, $staffId, $hours, $breaks);

        return Response::json(['data' => $this->schedule->schedule($tenantId, $staffId)]);
    }

    public function addHoliday(Request $request, string $tenantId, string $staffId): Response
    {
        $this->assertStaff($tenantId, $staffId);

        $start = (string) $request->input('start_date', '');
        $end = (string) $request->input('end_date', '');
        $reason = $request->input('reason');

        $errors = [];
        foreach (['start_date' => $start, 'end_date' => $end] as $field => $value) {
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($d === false || $d->format('Y-m-d') !== $value) {
                $errors[$field] = 'YYYY-MM-DD biçiminde tarih gerekli.';
            }
        }
        if ($errors === [] && $end < $start) {
            $errors['end_date'] = 'Bitiş, başlangıçtan önce olamaz.';
        }
        if ($errors !== []) {
            throw new ApiException('validation_error', 'Geçersiz tarih aralığı.', 422, $errors);
        }

        try {
            $holiday = $this->schedule->addHoliday($tenantId, $staffId, $start, $end, $reason !== null ? (string) $reason : null);
        } catch (\PDOException $e) {
            if (is_array($e->errorInfo) && ($e->errorInfo[0] ?? null) === '23505') {
                throw new ApiException('validation_error', 'Bu tarih aralığı zaten eklenmiş.', 422);
            }
            throw $e;
        }

        return Response::json(['data' => $holiday], 201);
    }

    public function deleteHoliday(Request $request, string $tenantId, string $staffId, string $holidayId): Response
    {
        $this->assertStaff($tenantId, $staffId);

        if (!$this->schedule->deleteHoliday($tenantId, $staffId, $holidayId)) {
            throw new ApiException('not_found', 'Holiday not found.', 404);
        }

        return Response::json(['data' => ['id' => $holidayId, 'deleted' => true]]);
    }

    public function services(Request $request, string $tenantId, string $staffId): Response
    {
        $this->assertStaff($tenantId, $staffId);

        return Response::json(['data' => ['service_ids' => $this->schedule->serviceIds($tenantId, $staffId)]]);
    }

    /** PUT — personelin hizmet atamalarının tamamını değiştirir (checkbox listesi, 06§5). */
    public function replaceServices(Request $request, string $tenantId, string $staffId): Response
    {
        $this->assertStaff($tenantId, $staffId);

        $serviceIds = $request->input('service_ids');
        if (!is_array($serviceIds)) {
            throw new ApiException('validation_error', 'service_ids dizisi gerekli.', 422);
        }

        $this->schedule->replaceServices($tenantId, $staffId, array_map('strval', $serviceIds));

        return Response::json(['data' => ['service_ids' => $this->schedule->serviceIds($tenantId, $staffId)]]);
    }

    private function assertStaff(string $tenantId, string $staffId): void
    {
        if ($this->staff->find($tenantId, $staffId) === null) {
            throw new ApiException('not_found', 'Staff not found.', 404);
        }
    }

    /**
     * @return array<int, array{day_of_week:int, start_time:string, end_time:string}>
     */
    private function validateSlots(mixed $slots, string $field): array
    {
        if (!is_array($slots)) {
            throw new ApiException('validation_error', "{$field} dizisi gerekli.", 422);
        }

        $clean = [];
        foreach ($slots as $i => $slot) {
            $day = $slot['day_of_week'] ?? null;
            $start = (string) ($slot['start_time'] ?? '');
            $end = (string) ($slot['end_time'] ?? '');

            if (!is_numeric($day) || (int) $day < 0 || (int) $day > 6) {
                throw new ApiException('validation_error', "{$field}[{$i}].day_of_week 0-6 arası olmalı.", 422);
            }
            foreach (['start_time' => $start, 'end_time' => $end] as $key => $time) {
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
                    throw new ApiException('validation_error', "{$field}[{$i}].{$key} HH:MM biçiminde olmalı.", 422);
                }
            }
            if ($end <= $start) {
                throw new ApiException('validation_error', "{$field}[{$i}]: bitiş, başlangıçtan sonra olmalı.", 422);
            }

            $clean[] = ['day_of_week' => (int) $day, 'start_time' => $start, 'end_time' => $end];
        }

        return $clean;
    }
}
