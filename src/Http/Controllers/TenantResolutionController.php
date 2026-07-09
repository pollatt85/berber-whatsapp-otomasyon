<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\TenantRepository;

/**
 * POST /internal/resolve-tenant (03_Backend_API.md §3.1). n8n servis kanalı, HMAC ile korunur
 * (ServiceHmacMiddleware front controller'da uygulanır).
 */
final class TenantResolutionController
{
    public function __construct(private TenantRepository $tenants)
    {
    }

    public function resolve(Request $request): Response
    {
        $phoneNumberId = (string) $request->input('phone_number_id', '');
        if ($phoneNumberId === '') {
            throw new ApiException('validation_error', 'phone_number_id is required.', 422);
        }

        $tenant = $this->tenants->findByPhoneNumberId($phoneNumberId);
        if ($tenant === null) {
            throw new ApiException('tenant_not_found', 'No tenant matches this phone_number_id.', 404);
        }

        return Response::json([
            'tenant_id' => $tenant['id'],
            'timezone' => $tenant['timezone'],
            'ai_enabled' => (bool) $tenant['ai_enabled'],
        ]);
    }
}
