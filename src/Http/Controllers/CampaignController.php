<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\CampaignRepository;
use App\Repository\MessageTemplateRepository;

/**
 * `/campaigns` CRUD (06_Admin_Panel.md §7): hedef filtre (`target_filter` jsonb, ör. "son
 * ziyaretten X gün önce") + şablon seçimi — yalnızca `template_type='campaign'` ve
 * `active=true` şablonlar kabul edilir. Yazma işlemleri owner/manager rolü ister.
 * Kampanya GÖNDERİMİ bu fazda yok (n8n kampanya workflow'u yazılmadı) — durum makinesi
 * panelde draft/scheduled/cancelled arasında ilerler, 'sent' geçişini gönderen taraf yapacak.
 */
final class CampaignController
{
    public function __construct(
        private CampaignRepository $campaigns,
        private MessageTemplateRepository $templates
    ) {
    }

    public function index(Request $request, string $tenantId): Response
    {
        return Response::json(['data' => $this->campaigns->listAll($tenantId)]);
    }

    public function store(Request $request, string $tenantId, array $args, string $role): Response
    {
        $this->requireManager($role);
        [$name, $templateId, $targetFilter, $scheduledAt] = $this->validatePayload($request, $tenantId);

        $campaign = $this->campaigns->create(
            $tenantId,
            $name,
            $templateId,
            $targetFilter,
            $scheduledAt,
            $scheduledAt !== null ? 'scheduled' : 'draft'
        );

        return Response::json(['data' => $campaign], 201);
    }

    public function update(Request $request, string $tenantId, string $id, string $role): Response
    {
        $this->requireManager($role);
        [$name, $templateId, $targetFilter, $scheduledAt] = $this->validatePayload($request, $tenantId);

        $campaign = $this->campaigns->update(
            $tenantId,
            $id,
            $name,
            $templateId,
            $targetFilter,
            $scheduledAt,
            $scheduledAt !== null ? 'scheduled' : 'draft'
        );
        if ($campaign === null) {
            throw new ApiException('validation_error', 'Kampanya bulunamadı ya da artık düzenlenemez (sent/cancelled).', 422);
        }

        return Response::json(['data' => $campaign]);
    }

    public function cancel(Request $request, string $tenantId, string $id, string $role): Response
    {
        $this->requireManager($role);

        $campaign = $this->campaigns->cancel($tenantId, $id);
        if ($campaign === null) {
            throw new ApiException('validation_error', 'Kampanya bulunamadı ya da zaten terminal durumda.', 422);
        }

        return Response::json(['data' => $campaign]);
    }

    private function requireManager(string $role): void
    {
        if (!in_array($role, ['owner', 'manager'], true)) {
            throw new ApiException('forbidden', 'Role does not permit this action.', 403);
        }
    }

    /**
     * Alan bazlı 422 sözleşmesi (03§6). target_filter şimdilik tek anahtar taşır:
     * `last_visit_min_days` (son ziyaretten en az X gün geçmiş müşteriler, 06§7 örneği).
     *
     * @return array{0:string, 1:string, 2:array, 3:?string}
     */
    private function validatePayload(Request $request, string $tenantId): array
    {
        $errors = [];

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            $errors['name'] = 'Kampanya adı gerekli.';
        }

        $templateId = (string) $request->input('template_id', '');
        if ($templateId === '') {
            $errors['template_id'] = 'Şablon seçimi gerekli.';
        } else {
            $template = $this->templates->find($tenantId, $templateId);
            if ($template === null) {
                $errors['template_id'] = 'Şablon bulunamadı.';
            } elseif ($template['template_type'] !== 'campaign' || !$template['active']) {
                // 06§7: yalnızca template_type='campaign' ve active=true şablonlar seçilebilir.
                $errors['template_id'] = 'Yalnızca aktif kampanya türü şablonlar seçilebilir.';
            }
        }

        $targetFilter = [];
        $minDays = $request->input('last_visit_min_days');
        if ($minDays !== null && $minDays !== '') {
            if (!is_numeric($minDays) || (int) $minDays < 1) {
                $errors['last_visit_min_days'] = 'Pozitif bir gün sayısı olmalı.';
            } else {
                $targetFilter['last_visit_min_days'] = (int) $minDays;
            }
        }

        $scheduledAt = $request->input('scheduled_at');
        if ($scheduledAt !== null && $scheduledAt !== '') {
            if (strtotime((string) $scheduledAt) === false) {
                $errors['scheduled_at'] = 'Geçerli bir tarih/saat olmalı.';
            } else {
                $scheduledAt = (string) $scheduledAt;
            }
        } else {
            $scheduledAt = null;
        }

        if ($errors !== []) {
            throw new ApiException('validation_error', 'Geçersiz kampanya verisi.', 422, $errors);
        }

        return [$name, $templateId, $targetFilter, $scheduledAt];
    }
}
