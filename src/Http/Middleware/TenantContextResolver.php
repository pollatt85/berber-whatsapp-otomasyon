<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ApiException;
use App\Http\Request;

/**
 * Ortak istemci uçları (03_Backend_API.md §3.3-§3.5) hem panel (JWT) hem n8n (HMAC) tarafından
 * çağrılabilir. JWT kanalında tenant_id token claim'inden gelir (istemci gönderemez); HMAC
 * kanalında tenant_id/context yoktur, body'den okunur ve burada doğrulanır (03§2.1).
 */
final class TenantContextResolver
{
    /**
     * @return array{tenant_id:string, role:string}
     */
    public static function resolve(Request $request): array
    {
        if ($request->bearerToken() !== null) {
            $identity = JwtAuthMiddleware::authenticate($request);
            return ['tenant_id' => $identity['tenant_id'], 'role' => $identity['role']];
        }

        if ($request->header('X-Signature') !== null) {
            ServiceHmacMiddleware::authenticate($request);
            $tenantId = (string) $request->input('tenant_id', '');
            if ($tenantId === '') {
                throw new ApiException('validation_error', 'tenant_id is required on the service channel.', 422);
            }
            return ['tenant_id' => $tenantId, 'role' => 'service'];
        }

        throw new ApiException('unauthorized', 'No JWT or service signature provided.', 401);
    }
}
