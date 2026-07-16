<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\StaffRepository;
use App\Repository\TenantRepository;

/**
 * `/staff` CRUD (03_Backend_API.md §3.2).
 */
final class StaffController
{
    public function __construct(private StaffRepository $staff, private ?TenantRepository $tenants = null)
    {
    }

    public function index(Request $request, string $tenantId): Response
    {
        $serviceId = $request->query['service_id'] ?? null;
        $rows = $serviceId !== null
            ? $this->staff->forService($tenantId, (string) $serviceId)
            : $this->staff->all($tenantId);

        return Response::json(['data' => $rows]);
    }

    public function store(Request $request, string $tenantId): Response
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            throw new ApiException('validation_error', 'name is required.', 422);
        }

        // O4: Plan personel limiti (max_staff NULL = sınırsız). Aktif personel sayısı limite ulaştıysa 403.
        if ($this->tenants !== null) {
            $maxStaff = $this->tenants->planLimits($tenantId)['max_staff'];
            if ($maxStaff !== null && count($this->staff->all($tenantId, true)) >= $maxStaff) {
                throw new ApiException('plan_limit', "Planınızın personel limitine ({$maxStaff}) ulaştınız.", 403);
            }
        }

        $phone = $request->input('phone');

        return Response::json(['data' => $this->staff->create($tenantId, $name, $phone)], 201);
    }

    public function update(Request $request, string $tenantId, string $id): Response
    {
        $staff = $this->staff->update($tenantId, $id, $request->body);
        if ($staff === null) {
            throw new ApiException('not_found', 'Staff not found.', 404);
        }

        return Response::json(['data' => $staff]);
    }

    public function destroy(Request $request, string $tenantId, string $id): Response
    {
        if (!$this->staff->deactivate($tenantId, $id)) {
            throw new ApiException('not_found', 'Staff not found.', 404);
        }

        return Response::json(['data' => ['id' => $id, 'active' => false]]);
    }
}
