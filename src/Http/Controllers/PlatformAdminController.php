<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Config\Env;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\PlatformAdminRepository;
use App\Support\Jwt;

/**
 * `POST /platform/auth/login` (09_SaaS_Deployment.md §5, §6). `users` panel login'inden
 * (AuthController) ayrı — `platform_admins` tablosu tenant'lar üstü, `tenant_id` içermez.
 */
final class PlatformAdminController
{
    public function __construct(private PlatformAdminRepository $admins)
    {
    }

    public function login(Request $request): Response
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '') {
            throw new ApiException('validation_error', 'email and password are required.', 422);
        }

        $admin = $this->admins->findActiveByEmail($email);
        if ($admin === null || !password_verify($password, $admin['password_hash'])) {
            throw new ApiException('unauthorized', 'Invalid credentials.', 401);
        }

        $ttl = (int) Env::get('JWT_TTL_SECONDS', '7200');
        $token = Jwt::encode([
            'sub' => $admin['id'],
            'type' => 'platform_admin',
            'iat' => time(),
            'exp' => time() + $ttl,
        ], Env::required('JWT_SECRET'));

        return Response::json(['token' => $token, 'expires_in' => $ttl]);
    }
}
