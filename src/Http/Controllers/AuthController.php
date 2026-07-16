<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Config\Env;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\UserRepository;
use App\Support\Jwt;

/**
 * POST /auth/login (03_Backend_API.md §2.2).
 */
final class AuthController
{
    public function __construct(private UserRepository $users)
    {
    }

    public function login(Request $request): Response
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '') {
            throw new ApiException('validation_error', 'email and password are required.', 422);
        }

        // Y5: e-posta tenant başına benzersiz (global değil). Tüm eşleşen aktif kullanıcılardan
        // yalnızca PAROLASI tutanları süz; tam bir tane tutmalı. Böylece yanlış tenant'a sessiz
        // giriş imkânsız: 0 tutan → 401, >1 tutan → 409 (belirsiz), 1 tutan → giriş.
        $matched = array_values(array_filter(
            $this->users->findActiveByEmail($email),
            static fn (array $u): bool => password_verify($password, $u['password_hash'])
        ));

        if ($matched === []) {
            throw new ApiException('unauthorized', 'Invalid credentials.', 401);
        }
        if (count($matched) > 1) {
            throw new ApiException(
                'account_ambiguous',
                'Bu e-posta birden fazla işletmede aynı parolayla kayıtlı; yönetici ile iletişime geçin.',
                409
            );
        }

        $user = $matched[0];

        // O3: askıya alınmış / iptal edilmiş tenant giriş yapamaz (panel "Askıya Al" butonu etkili olsun).
        if ($user['tenant_status'] !== 'active') {
            throw new ApiException('tenant_inactive', 'İşletme hesabı aktif değil.', 403);
        }

        $ttl = (int) Env::get('JWT_TTL_SECONDS', '7200');
        $token = Jwt::encode([
            'sub' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'role' => $user['role'],
            'iat' => time(),
            'exp' => time() + $ttl,
        ], Env::required('JWT_SECRET'));

        return Response::json(['token' => $token, 'expires_in' => $ttl]);
    }
}
