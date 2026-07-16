<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AiSettingsRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use PDO;
use PDOException;
use Throwable;

/**
 * `GET /platform/tenants`, `POST /platform/tenants`, `PATCH /platform/tenants/{id}`
 * (09_SaaS_Deployment.md §5, §6). Platform admin route grubu — `PlatformAdminAuthMiddleware` ile korunur.
 */
final class PlatformTenantController
{
    /**
     * $users/$aiSettings/$service yalnızca store() için gerekli; index()/updateStatus() sadece
     * $tenants kullanır. store() atomik olmalı → üç repo da AYNI $service PDO ile kurulmalı.
     */
    public function __construct(
        private TenantRepository $tenants,
        private ?UserRepository $users = null,
        private ?AiSettingsRepository $aiSettings = null,
        private ?PDO $service = null
    ) {
    }

    /**
     * A3: Platform admin yeni işletme + owner kullanıcı + ai_settings satırı açar (tek transaction).
     */
    public function store(Request $request): Response
    {
        $businessName = trim((string) $request->input('business_name', ''));
        $planName = (string) $request->input('plan', '');
        $email = trim((string) $request->input('owner_email', ''));
        $password = (string) $request->input('owner_password', '');

        if ($businessName === '' || $email === '' || $password === '') {
            throw new ApiException('validation_error', 'business_name, owner_email ve owner_password zorunludur.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('validation_error', 'Geçerli bir e-posta adresi girin.', 422);
        }
        if (strlen($password) < 8) {
            throw new ApiException('validation_error', 'Parola en az 8 karakter olmalı.', 422);
        }
        if (!$this->tenants->planExists($planName)) {
            throw new ApiException('validation_error', 'Geçersiz plan (starter|pro|business).', 422);
        }
        // Owner e-postası tüm tenant'larda benzersiz olmalı — aksi halde bu kullanıcı login'de
        // 409'a kilitlenir (Y5). Yarım tenant oluşmaması için transaction'dan ÖNCE kontrol et.
        if ($this->users->emailExists($email)) {
            throw new ApiException('conflict', 'Bu e-posta başka bir işletmede zaten kayıtlı.', 409);
        }

        $this->service->beginTransaction();
        try {
            $tenant = $this->tenants->create($businessName, $planName);
            $this->users->create($tenant['id'], $email, password_hash($password, PASSWORD_DEFAULT), 'owner');
            $this->aiSettings->createDefault($tenant['id']);
            $this->service->commit();
        } catch (PDOException $e) {
            $this->service->rollBack();
            if ($e->getCode() === '23505') { // unique_violation — e-posta bu tenant'ta zaten var
                throw new ApiException('conflict', 'Bu e-posta zaten kayıtlı.', 409);
            }
            throw $e;
        } catch (Throwable $e) {
            $this->service->rollBack();
            throw $e;
        }

        return Response::json(['data' => $tenant], 201);
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
