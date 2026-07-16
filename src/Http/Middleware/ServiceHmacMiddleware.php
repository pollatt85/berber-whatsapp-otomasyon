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
 *
 * Y7: İmza artık yalnızca rawBody'yi değil, `timestamp \n tenant_id \n rawBody` üçlüsünü kapsar:
 *   - timestamp (X-Timestamp header, unix saniye) + ±300s pencere → GET'lerdeki sabit ('' imzalı)
 *     imzanın süresiz replay'i engellenir (nonce yok, ama 5 dk pencere).
 *   - tenant_id (body veya query'den, uygulamanın okuduğu KAYNAKLA aynı) imzaya dahil edilir →
 *     `?tenant_id=<baska>` ile çapraz-tenant okuma (Request::input query fallback) engellenir.
 * n8n tarafındaki tüm Sign node'ları aynı üçlüyü imzalar (bkz. n8n/README.md).
 */
final class ServiceHmacMiddleware
{
    /** Timestamp kabul penceresi (saniye) — saat kayması + ağ gecikmesi payı. */
    private const TIMESTAMP_TOLERANCE = 300;

    public static function authenticate(Request $request): void
    {
        $signature = $request->header('X-Signature');
        if ($signature === null) {
            throw new ApiException('unauthorized', 'Missing X-Signature header.', 401);
        }

        $timestamp = $request->header('X-Timestamp');
        if ($timestamp === null || !ctype_digit($timestamp)) {
            throw new ApiException('unauthorized', 'Missing or invalid X-Timestamp header.', 401);
        }
        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            throw new ApiException('unauthorized', 'Timestamp outside acceptable window.', 401);
        }

        // tenant_id, uygulamanın kullanacağı kaynağın AYNISINDAN okunur (body ?? query) — böylece
        // imza, isteğin gerçekten işlem göreceği tenant'a bağlanır ve query swap edilemez.
        $tenantId = (string) $request->input('tenant_id', '');
        $signedString = $timestamp . "\n" . $tenantId . "\n" . $request->rawBody;

        $expected = hash_hmac('sha256', $signedString, Env::required('N8N_SERVICE_SECRET'));

        if (!hash_equals($expected, $signature)) {
            throw new ApiException('unauthorized', 'Invalid service signature.', 401);
        }
    }
}
