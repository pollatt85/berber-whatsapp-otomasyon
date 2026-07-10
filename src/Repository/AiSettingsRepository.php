<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `ai_settings` tablosu (02 §3.14, 07_AI_Module.md). Satır tenant başına en fazla birdir
 * (tenant_id PK); henüz hiç yapılandırma yapılmamış tenant için `find` satır oluşturmadan
 * varsayılanları döndürür — conversation_states'in "kayıt yoksa idle döner" deseniyle aynı.
 */
final class AiSettingsRepository
{
    /** 0001/0002 migration'larındaki kolon varsayılanlarının aynası. */
    private const DEFAULTS = [
        'enabled' => false,
        'tone' => 'friendly',
        'knowledge_base' => '{}',
        'rate_limit_per_minute' => 10,
        'updated_at' => null,
    ];

    public function __construct(private PDO $db)
    {
    }

    public function find(string $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT enabled, tone, knowledge_base, rate_limit_per_minute, updated_at
             FROM ai_settings WHERE tenant_id = :tenant_id'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch();

        return $row ?: self::DEFAULTS;
    }

    public function upsert(string $tenantId, bool $enabled, string $tone, array $knowledgeBase): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ai_settings (tenant_id, enabled, tone, knowledge_base, updated_at)
             VALUES (:tenant_id, :enabled, :tone, :kb, now())
             ON CONFLICT (tenant_id) DO UPDATE
                 SET enabled = EXCLUDED.enabled, tone = EXCLUDED.tone,
                     knowledge_base = EXCLUDED.knowledge_base, updated_at = now()
             RETURNING enabled, tone, knowledge_base, rate_limit_per_minute, updated_at'
        );
        $stmt->bindValue('tenant_id', $tenantId);
        $stmt->bindValue('enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue('tone', $tone);
        $stmt->bindValue('kb', json_encode($knowledgeBase, JSON_UNESCAPED_UNICODE));
        $stmt->execute();

        return $stmt->fetch();
    }
}
