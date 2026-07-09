<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repository\AppointmentScanRepository;

/**
 * `GET /internal/appointments-due-for-reminder`, `GET /internal/appointments-expired-pending`
 * (04_n8n_Workflows.md §5, §6, §7). n8n servis kanalı (HMAC), tüm tenant'lar tek sorguda taranır.
 */
final class InternalScanController
{
    public function __construct(private AppointmentScanRepository $scans)
    {
    }

    public function dueForReminder(Request $request): Response
    {
        return Response::json(['data' => $this->scans->dueForReminder()]);
    }

    public function expiredPending(Request $request): Response
    {
        return Response::json(['data' => $this->scans->expiredPending()]);
    }
}
