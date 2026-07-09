<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\TenantRepository;

/**
 * `GET /platform/tenants`, `PATCH /platform/tenants/{id}` (09_SaaS_Deployment.md §5, §6).
 * Platform admin route grubu — `PlatformAdminAuthMiddleware` ile korunur.
 */
final class PlatformTenantController
{
    public function __construct(private TenantRepository $tenants)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json(['data' => $this->tenants->listAll()]);
    }

    public function updateStatus(Request $request, string $tenantId): Response
    {
        $status = (string) $request->input('status', '');
        if (!in_array($status, ['active', 'suspended', 'cancelled'], true)) {
            throw new ApiException('validation_error', 'status must be one of active|suspended|cancelled.', 422);
        }

        $tenant = $this->tenants->updateStatus($tenantId, $status);
        if ($tenant === null) {
            throw new ApiException('not_found', 'Tenant not found.', 404);
        }

        return Response::json(['data' => $tenant]);
    }
}
