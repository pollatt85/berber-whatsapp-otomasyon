<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `conversation_states` tablosu (02_Database_Design.md, 04_n8n_Workflows.md §2, §7). n8n'in
 * "müşteri hangi adımda" sorgusu için — Connection::service() (BYPASSRLS), n8n'in JWT'si yok.
 */
final class ConversationStateRepository
{
    public function __construct(private PDO $service)
    {
    }

    public function find(string $tenantId, string $customerId): ?array
    {
        $stmt = $this->service->prepare(
            'SELECT * FROM conversation_states WHERE tenant_id = :tenant_id AND customer_id = :customer_id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'customer_id' => $customerId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * n8n her adımda state'i ilerletir (`PATCH /conversation-state`, 04_n8n_Workflows.md §2).
     * Tek satır tenant+customer başına (migration UNIQUE(tenant_id, customer_id)) — UPSERT.
     */
    public function upsert(string $tenantId, string $customerId, string $step, array $context): array
    {
        $stmt = $this->service->prepare(
            'INSERT INTO conversation_states (tenant_id, customer_id, step, context, updated_at)
             VALUES (:tenant_id, :customer_id, :step, :context, now())
             ON CONFLICT (tenant_id, customer_id)
             DO UPDATE SET step = EXCLUDED.step, context = EXCLUDED.context, updated_at = now()
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'step' => $step,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);

        return $stmt->fetch();
    }
}
