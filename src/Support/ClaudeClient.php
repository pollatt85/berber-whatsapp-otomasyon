<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Anthropic Messages API ham cURL istemcisi (07_AI_Module.md §4).
 * MetaGraphClient'ın kardeşi: yapılandırılmış çıktı için tek bir "provide_response" tool'u
 * zorunlu tutulur (tool_choice) — serbest metin ayrıştırma yerine tool-use ile {reply, intent}
 * alınır. LLM anahtarı (ANTHROPIC_API_KEY) yalnızca Backend'de tutulur, n8n'e verilmez (§2).
 *
 * Model claude-haiku-4-5 (§4 "Haiku sınıfı" — kısa SSS yanıtı, ağır muhakeme yok).
 */
final class ClaudeClient implements LlmClient
{
    private const BASE_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5';
    private const ANTHROPIC_VERSION = '2023-06-01';

    /** Yapılandırılmış çıktı sözleşmesi (§4): {reply, intent}. */
    private const RESPONSE_TOOL = [
        'name' => 'provide_response',
        'description' => 'Müşteriye gönderilecek yanıtı ve tespit edilen niyeti döndür.',
        'input_schema' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'reply' => [
                    'type' => 'string',
                    'description' => 'Müşteriye WhatsApp üzerinden gönderilecek serbest metin yanıt.',
                ],
                'intent' => [
                    'type' => 'string',
                    'enum' => ['faq', 'appointment_action', 'unclear'],
                    'description' => 'faq: bilgi sorusu; appointment_action: randevu oluştur/'
                        . 'değiştir/iptal işlemi istendi (menüye yönlendir); unclear: anlaşılamadı.',
                ],
            ],
            'required' => ['reply', 'intent'],
        ],
    ];

    public function __construct(private string $apiKey)
    {
    }

    /**
     * @param array<int, array{role:string, content:string}> $messages
     * @return array{reply:string, intent:string}
     */
    public function structuredReply(string $system, array $messages): array
    {
        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 1024,
            'system' => $system,
            'messages' => $messages,
            'tools' => [self::RESPONSE_TOOL],
            'tool_choice' => ['type' => 'tool', 'name' => 'provide_response'],
        ];

        $ch = curl_init(self::BASE_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
            'content-type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Claude API request failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        if ($status !== 200 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? 'unknown') : 'non-JSON response';
            throw new \RuntimeException("Claude API error (HTTP {$status}): {$message}");
        }

        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? '') !== 'tool_use' || ($block['name'] ?? '') !== 'provide_response') {
                continue;
            }
            $input = is_array($block['input'] ?? null) ? $block['input'] : [];
            $intent = (string) ($input['intent'] ?? 'unclear');
            if (!in_array($intent, ['faq', 'appointment_action', 'unclear'], true)) {
                $intent = 'unclear';
            }

            return ['reply' => (string) ($input['reply'] ?? ''), 'intent' => $intent];
        }

        throw new \RuntimeException('Claude API returned no provide_response tool_use block.');
    }
}
