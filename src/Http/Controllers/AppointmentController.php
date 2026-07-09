<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AppointmentRepository;
use App\Repository\CustomerRepository;
use App\Repository\ServiceRepository;
use DateInterval;
use DateTimeImmutable;
use PDOException;

/**
 * `/appointments` (03_Backend_API.md §3.4, §5). Nihai çakışma doğruluğu DB'nin exclusion
 * constraint'ine aittir (02§6) — bu controller yalnızca 23P01/23505 SQLSTATE'lerini standart
 * hata sözleşmesine (§6) çevirir.
 */
final class AppointmentController
{
    private const EXCLUSION_VIOLATION = '23P01';
    private const UNIQUE_VIOLATION = '23505';

    public function __construct(
        private AppointmentRepository $appointments,
        private ServiceRepository $services,
        private CustomerRepository $customers
    ) {
    }

    public function index(Request $request, string $tenantId): Response
    {
        $rows = $this->appointments->listByFilters(
            $tenantId,
            $request->query['staff_id'] ?? null,
            $request->query['date'] ?? null,
            $request->query['status'] ?? null,
            $request->query['customer_id'] ?? null
        );

        return Response::json(['data' => $rows]);
    }

    public function store(Request $request, string $tenantId): Response
    {
        $staffId = (string) $request->input('staff_id', '');
        $serviceId = (string) $request->input('service_id', '');
        $startTime = (string) $request->input('start_time', '');
        $customerId = $request->input('customer_id');
        $whatsappNumber = $request->input('whatsapp_number');
        $idempotencyKey = $request->input('idempotency_key');

        if ($staffId === '' || $serviceId === '' || $startTime === '') {
            throw new ApiException('validation_error', 'staff_id, service_id, start_time are required.', 422);
        }
        if ($customerId === null && $whatsappNumber === null) {
            throw new ApiException('validation_error', 'customer_id or whatsapp_number is required.', 422);
        }

        if ($customerId === null) {
            $customer = $this->customers->findByWhatsappNumber($tenantId, (string) $whatsappNumber);
            if ($customer === null) {
                throw new ApiException('not_found', 'Customer not found; create it first via POST /customers.', 404);
            }
            $customerId = $customer['id'];
        }

        $service = $this->services->find($tenantId, $serviceId);
        if ($service === null) {
            throw new ApiException('not_found', 'Service not found.', 404);
        }

        if ($idempotencyKey !== null) {
            $existing = $this->appointments->findByIdempotencyKey($tenantId, (string) $idempotencyKey);
            if ($existing !== null) {
                return Response::json(['data' => $existing], 200);
            }
        }

        $start = new DateTimeImmutable($startTime);
        $end = $start->add(new DateInterval("PT{$service['duration_minutes']}M"));

        try {
            $appointment = $this->appointments->create(
                $tenantId,
                (string) $customerId,
                $staffId,
                $serviceId,
                $start->format(DATE_ATOM),
                $end->format(DATE_ATOM),
                $idempotencyKey !== null ? (string) $idempotencyKey : null
            );
        } catch (PDOException $e) {
            if ($this->sqlState($e) === self::EXCLUSION_VIOLATION) {
                throw new ApiException('slot_taken', 'Seçilen saat az önce doldu.', 409);
            }
            if ($this->sqlState($e) === self::UNIQUE_VIOLATION) {
                $existing = $idempotencyKey !== null
                    ? $this->appointments->findByIdempotencyKey($tenantId, (string) $idempotencyKey)
                    : null;
                if ($existing !== null) {
                    return Response::json(['data' => $existing], 200);
                }
            }
            throw $e;
        }

        return Response::json(['data' => $appointment], 201);
    }

    public function confirm(Request $request, string $tenantId, string $id): Response
    {
        $appointment = $this->appointments->transition($tenantId, $id, "'pending'", 'confirmed');
        if ($appointment === null) {
            throw new ApiException('validation_error', 'Appointment not found or not in a confirmable state.', 422);
        }

        return Response::json(['data' => $appointment]);
    }

    public function cancel(Request $request, string $tenantId, string $id): Response
    {
        $appointment = $this->appointments->transition($tenantId, $id, "'pending','confirmed'", 'cancelled');
        if ($appointment === null) {
            throw new ApiException('validation_error', 'Appointment not found or already in a terminal state.', 422);
        }

        return Response::json(['data' => $appointment]);
    }

    /**
     * 03_Backend_API.md §3.4/§5: yerinde saat değişikliği — eskiyi cancel edip yeni satır
     * açmak yerine `time_range` güncellenir. Süre mevcut `service_id`'den alınır.
     */
    public function reschedule(Request $request, string $tenantId, string $id): Response
    {
        $startTime = (string) $request->input('start_time', '');
        if ($startTime === '') {
            throw new ApiException('validation_error', 'start_time is required.', 422);
        }

        $existing = $this->appointments->find($tenantId, $id);
        if ($existing === null) {
            throw new ApiException('not_found', 'Appointment not found.', 404);
        }

        $service = $this->services->find($tenantId, $existing['service_id']);
        if ($service === null) {
            throw new ApiException('not_found', 'Service not found.', 404);
        }

        $start = new DateTimeImmutable($startTime);
        $end = $start->add(new DateInterval("PT{$service['duration_minutes']}M"));

        try {
            $appointment = $this->appointments->reschedule(
                $tenantId,
                $id,
                $start->format(DATE_ATOM),
                $end->format(DATE_ATOM)
            );
        } catch (PDOException $e) {
            if ($this->sqlState($e) === self::EXCLUSION_VIOLATION) {
                throw new ApiException('slot_taken', 'Seçilen saat az önce doldu.', 409);
            }
            throw $e;
        }

        if ($appointment === null) {
            throw new ApiException('validation_error', 'Appointment not found or already in a terminal state.', 422);
        }

        return Response::json(['data' => $appointment]);
    }

    /** 06§4: confirmed → completed panelden manuel işaretlenir. */
    public function complete(Request $request, string $tenantId, string $id): Response
    {
        $appointment = $this->appointments->transition($tenantId, $id, "'confirmed'", 'completed');
        if ($appointment === null) {
            throw new ApiException('validation_error', 'Appointment not found or not in a completable state.', 422);
        }

        return Response::json(['data' => $appointment]);
    }

    /** 06§4: confirmed → no_show (müşteri gelmedi) panelden manuel işaretlenir. */
    public function noShow(Request $request, string $tenantId, string $id): Response
    {
        $appointment = $this->appointments->transition($tenantId, $id, "'confirmed'", 'no_show');
        if ($appointment === null) {
            throw new ApiException('validation_error', 'Appointment not found or not in a markable state.', 422);
        }

        return Response::json(['data' => $appointment]);
    }

    private function sqlState(PDOException $e): ?string
    {
        return is_array($e->errorInfo) ? ($e->errorInfo[0] ?? null) : null;
    }
}
