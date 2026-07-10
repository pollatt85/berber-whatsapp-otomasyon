<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Env;
use App\Http\ApiException;
use App\Http\Request;
use App\Support\Jwt;

/**
 * Panel -> Backend kimlik doğrulama (03_Backend_API.md §2.2).
 * JWT'deki tenant_id claim'i tek doğruluk kaynağıdır; istemci başka bir tenant_id
 * gönderemez (gönderirse bu middleware zaten dikkate almaz, yalnızca token'dan okunur).
 */
final class JwtAuthMiddleware
{
    /**
     * @return array{user_id:string, tenant_id:string, role:string}
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

        foreach (['sub', 'tenant_id', 'role'] as $required) {
            if (empty($claims[$required])) {
                throw new ApiException('unauthorized', 'Malformed token claims.', 401);
            }
        }

        $identity = [
            'user_id' => (string) $claims['sub'],
            'tenant_id' => (string) $claims['tenant_id'],
            'role' => (string) $claims['role'],
        ];

        $request->setAttribute('identity', $identity);

        return $identity;
    }

    public static function requireRole(array $identity, string ...$allowedRoles): void
    {
        if (!in_array($identity['role'], $allowedRoles, true)) {
            throw new ApiException('forbidden', 'Role does not permit this action.', 403);
        }
    }
}
