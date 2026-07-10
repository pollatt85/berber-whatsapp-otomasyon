<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repository\MessageTemplateRepository;

/**
 * `GET /messages/templates` — panelin salt okunur şablon listesi (06_Admin_Panel.md §7).
 * Panelden şablon oluşturulamaz/düzenlenemez; tek yazma yolu Meta senkronizasyonudur
 * (`POST /messages/templates/sync` sarmalayıcısı → WhatsAppInternalController::syncTemplates).
 */
final class MessageTemplateController
{
    public function __construct(private MessageTemplateRepository $templates)
    {
    }

    public function index(Request $request, string $tenantId): Response
    {
        return Response::json(['data' => $this->templates->listAll($tenantId)]);
    }
}
