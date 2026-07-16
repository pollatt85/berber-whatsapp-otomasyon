<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AiSettingsRepository;
use App\Repository\TenantRepository;

/**
 * `GET/PATCH /settings/ai` (06_Admin_Panel.md §8, 07_AI_Module.md §6). Panel alanları:
 * enabled, tone (friendly/formal/concise), knowledge_base.faq [{q,a}],
 * knowledge_base.policies.{cancellation,late_arrival}. Fiyat/süre/çalışma saati buraya
 * GİRİLMEZ — AI prompt'una services/staff/working_hours tablolarından enjekte edilir (07§3).
 * `rate_limit_per_minute` GET'te döner ama PATCH ile değiştirilemez (07§5 — platform
 * korumasıdır, tenant kendi eşiğini yükseltememeli).
 */
final class AiSettingsController
{
    private const TONES = ['friendly', 'formal', 'concise'];

    public function __construct(private AiSettingsRepository $settings, private ?TenantRepository $tenants = null)
    {
    }

    public function show(Request $request, string $tenantId): Response
    {
        return Response::json(['data' => $this->settings->find($tenantId)]);
    }

    public function update(Request $request, string $tenantId, array $args, string $role): Response
    {
        if (!in_array($role, ['owner', 'manager'], true)) {
            throw new ApiException('forbidden', 'Role does not permit this action.', 403);
        }

        $errors = [];

        $enabled = $request->input('enabled');
        if (!is_bool($enabled)) {
            $errors['enabled'] = 'true veya false olmalı.';
        }

        $tone = (string) $request->input('tone', '');
        if (!in_array($tone, self::TONES, true)) {
            $errors['tone'] = 'friendly, formal veya concise olmalı.';
        }

        // 07§3 knowledge_base şeması: {faq: [{q, a}], policies: {cancellation, late_arrival}}
        $kb = $request->input('knowledge_base');
        $knowledgeBase = ['faq' => [], 'policies' => []];
        if (!is_array($kb)) {
            $errors['knowledge_base'] = 'Obje olmalı.';
        } else {
            foreach ((array) ($kb['faq'] ?? []) as $i => $item) {
                $q = trim((string) ($item['q'] ?? ''));
                $a = trim((string) ($item['a'] ?? ''));
                if ($q === '' || $a === '') {
                    $errors['knowledge_base'] = 'faq[' . $i . ']: soru ve cevap boş olamaz.';
                    break;
                }
                $knowledgeBase['faq'][] = ['q' => $q, 'a' => $a];
            }
            foreach (['cancellation', 'late_arrival'] as $policy) {
                $value = trim((string) (((array) ($kb['policies'] ?? []))[$policy] ?? ''));
                if ($value !== '') {
                    $knowledgeBase['policies'][$policy] = $value;
                }
            }
        }

        if ($errors !== []) {
            throw new ApiException('validation_error', 'Geçersiz AI ayarı.', 422, $errors);
        }

        // O4: AI özelliği plana bağlı (ai_enabled). Plan izin vermiyorsa AI açılamaz (kapatma serbest).
        if ($enabled === true && $this->tenants !== null && !$this->tenants->planLimits($tenantId)['ai_enabled']) {
            throw new ApiException('plan_limit', 'AI özelliği planınıza dahil değil.', 403);
        }

        return Response::json(['data' => $this->settings->upsert($tenantId, $enabled, $tone, $knowledgeBase)]);
    }
}
