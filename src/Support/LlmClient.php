<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Yapılandırılmış AI yanıt sağlayıcısı sözleşmesi (07_AI_Module.md §4). Sağlayıcıdan bağımsız:
 * ClaudeClient (Anthropic) ve GeminiClient (Google) aynı {reply, intent} çıktısını üretir.
 * AiRespondController bu arayüze bağımlıdır, somut sağlayıcıya değil.
 */
interface LlmClient
{
    /**
     * @param array<int, array{role:string, content:string}> $messages
     * @return array{reply:string, intent:string}
     */
    public function structuredReply(string $system, array $messages): array;
}
