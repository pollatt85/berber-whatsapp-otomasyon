<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\MessageLogRepository;

/**
 * `GET /messages/log` — message_log tablosunun filtrelenebilir panel görünümü
 * (06_Admin_Panel.md §7). Salt okunur; yazan taraf n8n/WhatsAppInternalController.
 */
final class MessageLogController
{
    private const DIRECTIONS = ['inbound', 'outbound'];
    private const STATUSES = ['sent', 'delivered', 'read', 'failed'];

    public function __construct(private MessageLogRepository $messages)
    {
    }

    public function index(Request $request, string $tenantId): Response
    {
        $q = $request->query;
        $errors = [];

        $direction = isset($q['direction']) ? (string) $q['direction'] : null;
        if ($direction !== null && !in_array($direction, self::DIRECTIONS, true)) {
            $errors['direction'] = 'inbound veya outbound olmalı.';
        }

        $status = isset($q['status']) ? (string) $q['status'] : null;
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            $errors['status'] = 'sent, delivered, read veya failed olmalı.';
        }

        $dates = [];
        foreach (['date_from', 'date_to'] as $field) {
            $dates[$field] = isset($q[$field]) ? (string) $q[$field] : null;
            if ($dates[$field] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dates[$field])) {
                $errors[$field] = 'YYYY-MM-DD biçiminde olmalı.';
            }
        }

        if ($errors !== []) {
            throw new ApiException('validation_error', 'Geçersiz filtre.', 422, $errors);
        }

        $rows = $this->messages->listByFilters(
            $tenantId,
            $direction,
            $status,
            $dates['date_from'],
            $dates['date_to'],
            isset($q['customer_id']) ? (string) $q['customer_id'] : null
        );

        return Response::json(['data' => $rows]);
    }
}
