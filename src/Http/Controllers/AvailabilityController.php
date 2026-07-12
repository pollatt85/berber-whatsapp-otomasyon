<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\TenantRepository;
use App\Service\AvailabilityService;

/**
 * GET /availability (03_Backend_API.md §3.3).
 */
final class AvailabilityController
{
    public function __construct(
        private AvailabilityService $availability,
        private TenantRepository $tenants
    ) {
    }

    public function index(Request $request, string $tenantId): Response
    {
        $serviceId = $request->query['service_id'] ?? null;
        $staffId = $request->query['staff_id'] ?? null;
        $date = $request->query['date'] ?? null;
        $days = (int) ($request->query['days'] ?? 1);

        if ($serviceId === null || $staffId === null || $date === null) {
            throw new ApiException('validation_error', 'service_id, staff_id, date are required.', 422);
        }

        $tenant = $this->tenants->findById($tenantId);
        if ($tenant === null) {
            throw new ApiException('tenant_not_found', 'Tenant not found.', 404);
        }

        if ($days > 1) {
            $daysMap = $this->availability->slotsForRange(
                $tenantId,
                (string) $staffId,
                (string) $serviceId,
                (string) $date,
                $days,
                (string) $tenant['timezone']
            );

            return Response::json(['days' => $daysMap]);
        }

        $slots = $this->availability->slotsFor(
            $tenantId,
            (string) $staffId,
            (string) $serviceId,
            (string) $date,
            (string) $tenant['timezone']
        );

        return Response::json(['slots' => $slots]);
    }
}
