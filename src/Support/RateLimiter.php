<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sabit dakikalık pencere sayacı (07_AI_Module.md §5, BACKLOG §A m.11/16): `ai_settings.
 * rate_limit_per_minute` eşiğini Redis'te INCR+EXPIRE ile uygular. Anahtar dakika damgasıyla
 * (`floor(time()/60)`) değiştiği için pencere geçişinde otomatik sıfırlanır, ayrı bir reset
 * job'a gerek yok.
 *
 * Fail-open: Redis'e ulaşılamazsa (dev makinesinde kapalı olabilir) istek engellenmez, sadece
 * loglanır — AiRespondController'ın "n8n'e asla 5xx dönme" ilkesiyle tutarlı (bkz. o dosyanın
 * sınıf yorumu).
 */
final class RateLimiter
{
    public function __construct(private readonly RedisClient $redis)
    {
    }

    /** @return bool true: izinli, false: limit aşıldı */
    public function allow(string $key, int $limitPerMinute): bool
    {
        if ($limitPerMinute <= 0) {
            return true;
        }

        $bucket = (int) floor(time() / 60);
        $redisKey = "ratelimit:{$key}:{$bucket}";

        try {
            $count = (int) $this->redis->command(['INCR', $redisKey]);
            if ($count === 1) {
                // Sayaç bu pencerede ilk kez oluşturuldu; pencere geçince kendiliğinden silinsin.
                $this->redis->command(['EXPIRE', $redisKey, 60]);
            }
        } catch (\Throwable $e) {
            error_log('[RateLimiter] Redis unavailable, failing open: ' . $e->getMessage());
            return true;
        }

        return $count <= $limitPerMinute;
    }
}
