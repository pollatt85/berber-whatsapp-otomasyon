<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use App\Repository\AppointmentRepository;
use App\Repository\ServiceRepository;
use App\Repository\StaffRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Uygun Slot Hesaplama (03_Backend_API.md §4).
 *
 * Girdi: tenant_id (context), staff_id, service_id, date.
 * 1. duration = services.duration_minutes
 * 2. gün_aralığı = working_hours (boşsa [] döner)
 * 3. kapalı_dönemler = breaks ∪ holidays (tüm gün tatilse [] döner)
 * 4. mevcut_randevular = appointments (pending/confirmed, o gün)
 * 5. serbest_aralıklar = gün_aralığı − kapalı_dönemler − mevcut_randevular
 * 6. slotlar = serbest_aralıklar içinde duration uzunluğunda, STEP_MINUTES adımla kayan pencere
 *
 * Önemli ilke (03§4): Bu yalnızca ÖNERİ üretir; nihai doğruluk POST /appointments anındaki
 * exclusion constraint'e aittir. Algoritma ile INSERT arasında slot dolabilir (409 slot_taken).
 */
final class AvailabilityService
{
    private const STEP_MINUTES = 15;
    private const MIN_LEAD_MINUTES = 30; // "şu andan min. X dk sonra" (03§4 adım 6)

    public function __construct(
        private ServiceRepository $services,
        private StaffRepository $staff,
        private AppointmentRepository $appointments
    ) {
    }

    /**
     * @return string[] "HH:MM" formatında uygun slot başlangıçları
     */
    public function slotsFor(string $tenantId, string $staffId, string $serviceId, string $date, string $timezone): array
    {
        $service = $this->services->find($tenantId, $serviceId);
        if ($service === null) {
            throw new ApiException('not_found', 'Service not found.', 404);
        }
        $duration = (int) $service['duration_minutes'];

        $tz = new DateTimeZone($timezone);
        $dayStart = new DateTimeImmutable("{$date} 00:00:00", $tz);
        $dayEnd = $dayStart->modify('+1 day');
        $dayOfWeek = (int) $dayStart->format('w'); // 0=Pazar, 02_Database_Design.md §3.6 ile birebir

        if ($this->staff->hasHolidayOn($tenantId, $staffId, $date)) {
            return [];
        }

        $workingHours = $this->staff->workingHours($tenantId, $staffId, $dayOfWeek);
        if ($workingHours === []) {
            return [];
        }

        $free = array_map(
            fn (array $row) => $this->timeRowToMinutes($row['start_time'], $row['end_time']),
            $workingHours
        );

        $breaks = array_map(
            fn (array $row) => $this->timeRowToMinutes($row['start_time'], $row['end_time']),
            $this->staff->breaks($tenantId, $staffId, $dayOfWeek)
        );
        foreach ($breaks as $break) {
            $free = $this->subtractInterval($free, $break);
        }

        $existing = $this->appointments->activeForStaffOnDate(
            $tenantId,
            $staffId,
            $dayStart->format(DATE_ATOM),
            $dayEnd->format(DATE_ATOM)
        );
        foreach ($existing as $row) {
            $busy = $this->parseRangeToMinutes($row['time_range'], $dayStart);
            if ($busy !== null) {
                $free = $this->subtractInterval($free, $busy);
            }
        }

        $earliestAllowed = (int) floor(
            (time() - $dayStart->getTimestamp()) / 60
        ) + self::MIN_LEAD_MINUTES;

        $slots = [];
        foreach ($free as [$start, $end]) {
            for ($cursor = $start; $cursor + $duration <= $end; $cursor += self::STEP_MINUTES) {
                if ($cursor < $earliestAllowed) {
                    continue;
                }
                $slots[] = $dayStart->add(new DateInterval("PT{$cursor}M"))->format('H:i');
            }
        }

        return $slots;
    }

    /** @return array{0:int,1:int} dakika cinsinden [start, end) */
    private function timeRowToMinutes(string $start, string $end): array
    {
        return [$this->timeToMinutes($start), $this->timeToMinutes($end)];
    }

    private function timeToMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));
        return $h * 60 + $m;
    }

    /**
     * Postgres tstzrange metnini ("[2026-07-04 09:00:00+03,2026-07-04 09:30:00+03)")
     * $dayStart'a göre dakika ofsetine çevirir.
     */
    private function parseRangeToMinutes(string $rangeText, DateTimeImmutable $dayStart): ?array
    {
        if (!preg_match('/[\[(]"?([^,"]+)"?,"?([^,")]+)"?[\])]/', $rangeText, $m)) {
            return null;
        }

        $start = new DateTimeImmutable(trim($m[1]));
        $end = new DateTimeImmutable(trim($m[2]));

        $startMin = (int) round(($start->getTimestamp() - $dayStart->getTimestamp()) / 60);
        $endMin = (int) round(($end->getTimestamp() - $dayStart->getTimestamp()) / 60);

        return [$startMin, $endMin];
    }

    /**
     * @param array<int, array{0:int,1:int}> $intervals
     * @param array{0:int,1:int} $subtract
     * @return array<int, array{0:int,1:int}>
     */
    private function subtractInterval(array $intervals, array $subtract): array
    {
        [$subStart, $subEnd] = $subtract;
        $result = [];

        foreach ($intervals as [$start, $end]) {
            if ($subEnd <= $start || $subStart >= $end) {
                $result[] = [$start, $end];
                continue;
            }
            if ($subStart > $start) {
                $result[] = [$start, min($subStart, $end)];
            }
            if ($subEnd < $end) {
                $result[] = [max($subEnd, $start), $end];
            }
        }

        return $result;
    }
}
