<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Env;
use App\Http\ApiException;
use App\Http\Request;

/**
 * n8n -> Backend kimlik doğrulama (03_Backend_API.md §2.1).
 * Tenant context taşımaz; yalnızca önceden tanımlı endpoint setinde kullanılır
 * (/internal/* ve resolve-tenant) — panel CRUD endpoint'lerinde bu middleware kullanılmaz.
 */
final class ServiceHmacMiddleware
{
    public static function authenticate(Request $request): void
    {
        $signature = $request->header('X-Signature');
        if ($signature === null) {
            throw new ApiException('unauthorized', 'Missing X-Signature header.', 401);
        }

        $expected = hash_hmac('sha256', $request->rawBody, Env::required('N8N_SERVICE_SECRET'));

        if (!hash_equals($expected, $signature)) {
            throw new ApiException('unauthorized', 'Invalid service signature.', 401);
        }
    }
}
