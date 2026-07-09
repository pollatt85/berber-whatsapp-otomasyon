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

        $user = $this->users->findActiveByEmail($email);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            throw new ApiException('unauthorized', 'Invalid credentials.', 401);
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
