<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repository\CampaignScanRepository;

/**
 * `GET /internal/campaigns-due-for-send` (BACKLOG.md §A madde 26). n8n servis kanalı (HMAC),
 * `InternalScanController`'daki appointment taramalarıyla aynı desen — tüm tenant'lar tek
 * sorguda taranır, servis rolüyle (BYPASSRLS) çalışır.
 */
final class CampaignScanController
{
    public function __construct(private CampaignScanRepository $scans)
    {
    }

    public function dueForSend(Request $request): Response
    {
        return Response::json(['data' => $this->scans->dueForSend()]);
    }
}
