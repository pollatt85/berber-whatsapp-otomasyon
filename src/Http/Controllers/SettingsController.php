<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\TenantSettingsRepository;

/**
 * `POST /settings/logo` (06_Admin_Panel.md §8, §10). Statik dosya yükleme — CDN kapsam dışı,
 * dosya `public/uploads/logos/{tenant_id}/` altında saklanır.
 */
final class SettingsController
{
    private const ALLOWED_MIME = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private TenantSettingsRepository $settings)
    {
    }

    /** GET /settings — 06§8'in üç ayar sayfasının ortak veri kaynağı. */
    public function show(Request $request, string $tenantId): Response
    {
        $tenant = $this->settings->find($tenantId);
        if ($tenant === null) {
            throw new ApiException('not_found', 'Tenant not found.', 404);
        }

        return Response::json(['data' => $tenant]);
    }

    /**
     * PATCH /settings — işletme bilgileri + hatırlatma ayarları (06§8). Alan bazlı 422
     * sözleşmesi (03§6): details içinde {alan: mesaj}.
     */
    public function update(Request $request, string $tenantId, array $args, string $role): Response
    {
        if (!in_array($role, ['owner', 'manager'], true)) {
            throw new ApiException('forbidden', 'Role does not permit this action.', 403);
        }

        $errors = [];
        $body = $request->body;

        if (array_key_exists('business_name', $body) && trim((string) $body['business_name']) === '') {
            $errors['business_name'] = 'İşletme adı boş olamaz.';
        }
        if (array_key_exists('timezone', $body) && !in_array($body['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Geçersiz zaman dilimi.';
        }
        foreach (['reminder_hours_before' => [1, 168], 'pending_ttl_minutes' => [1, 1440]] as $field => [$min, $max]) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            if (!is_numeric($body[$field]) || (int) $body[$field] != $body[$field]
                || (int) $body[$field] < $min || (int) $body[$field] > $max) {
                $errors[$field] = "{$min}-{$max} arasında tam sayı olmalı.";
            } else {
                $body[$field] = (int) $body[$field];
            }
        }
        foreach (['location_lat' => 90, 'location_lng' => 180] as $field => $limit) {
            if (array_key_exists($field, $body) && $body[$field] !== null
                && (!is_numeric($body[$field]) || abs((float) $body[$field]) > $limit)) {
                $errors[$field] = 'Geçersiz koordinat.';
            }
        }

        if ($errors !== []) {
            throw new ApiException('validation_error', 'Geçersiz alanlar var.', 422, $errors);
        }

        $tenant = $this->settings->updateFields($tenantId, $body);
        if ($tenant === null) {
            throw new ApiException('not_found', 'Tenant not found.', 404);
        }

        return Response::json(['data' => $tenant]);
    }

    public function uploadLogo(Request $request, string $tenantId, array $args, string $role): Response
    {
        if (!in_array($role, ['owner', 'manager'], true)) {
            throw new ApiException('forbidden', 'Role does not permit this action.', 403);
        }

        $file = $_FILES['logo'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            throw new ApiException('validation_error', 'A valid "logo" file upload is required.', 422);
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new ApiException('validation_error', 'Logo must be at most 2MB.', 422);
        }

        $mime = (string) mime_content_type($file['tmp_name']);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new ApiException('validation_error', 'Only PNG, JPEG or WEBP images are allowed.', 422);
        }

        $dir = dirname(__DIR__, 3) . "/public/uploads/logos/{$tenantId}";
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiException('internal_error', 'Could not create upload directory.', 500);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME[$mime];
        if (!move_uploaded_file($file['tmp_name'], "{$dir}/{$filename}")) {
            throw new ApiException('internal_error', 'Could not store uploaded file.', 500);
        }

        $logoUrl = "/uploads/logos/{$tenantId}/{$filename}";
        $tenant = $this->settings->updateLogoUrl($tenantId, $logoUrl);

        return Response::json(['data' => $tenant]);
    }
}
