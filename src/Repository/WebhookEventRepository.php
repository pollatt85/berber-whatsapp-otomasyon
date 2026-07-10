<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `webhook_events` tablosu (02_Database_Design.md §3.15, 05_WhatsApp_Integration.md §2.2).
 * Tenant henüz çözülmeden ham denetim kaydı için Connection::service() (BYPASSRLS) ile kullanılır.
 */
final class WebhookEventRepository
{
    public function __construct(private PDO $service)
    {
    }

    public function insert(?string $tenantId, string $phoneNumberId, bool $signatureValid, array $payload): array
    {
        $stmt = $this->service->prepare(
            'INSERT INTO webhook_events (tenant_id, phone_number_id, signature_valid, payload)
             VALUES (:tenant_id, :phone_number_id, :signature_valid, :payload)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'phone_number_id' => $phoneNumberId,
            'signature_valid' => $signatureValid,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        return $stmt->fetch();
    }
}
