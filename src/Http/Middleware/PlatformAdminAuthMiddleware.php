<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Env;
use App\Http\ApiException;
use App\Http\Request;
use App\Support\Jwt;

/**
 * Platform admin -> Backend kimlik doğrulama (09_SaaS_Deployment.md §5, §6). Panel JWT'sinden
 * (`tenant_id`+`role`, JwtAuthMiddleware) ayrı bir claim seti kullanır (`type: platform_admin`,
 * `tenant_id` yok) — böylece bir tenant kullanıcısının JWT'si platform uçlarında, veya tersi,
 * kullanılamaz.
 */
final class PlatformAdminAuthMiddleware
{
    /**
     * @return array{admin_id:string}
     */
    public static function authenticate(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new ApiException('unauthorized', 'Missing bearer token.', 401);
        }

        try {
            $claims = Jwt::decode($token, Env::required('JWT_SECRET'));
        } catch (\RuntimeException $e) {
            throw new ApiException('unauthorized', 'Invalid or expired token.', 401);
        }

        if (($claims['type'] ?? null) !== 'platform_admin' || empty($claims['sub'])) {
            throw new ApiException('forbidden', 'Not a platform admin token.', 403);
        }

        return ['admin_id' => (string) $claims['sub']];
    }
}
