<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Google Gemini `generateContent` ham cURL istemcisi (07_AI_Module.md §4, sağlayıcı = Gemini).
 * ClaudeClient'ın kardeşi ve LlmClient sözleşmesinin ikinci implementasyonu: yapılandırılmış
 * çıktı için tek bir "provide_response" function declaration'ı zorunlu tutulur (toolConfig
 * mode=ANY) — serbest metin ayrıştırma yerine function-call ile {reply, intent} alınır.
 *
 * Ücretsiz katman gerekçesi (kullanıcı kararı): Gemini Flash ücretsiz kotayla pilot/test
 * maliyetini sıfırlar. API anahtarı (GEMINI_API_KEY) yalnızca Backend'de tutulur, n8n'e
 * verilmez (§2). Varsayılan model gemini-2.5-flash (§4 "hafif sınıf" — kısa SSS yanıtı).
 */
final class GeminiClient implements LlmClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const DEFAULT_MODEL = 'gemini-2.5-flash';

    /**
     * Yapılandırılmış çıktı sözleşmesi (§4): {reply, intent}. Gemini Schema (OpenAPI alt kümesi)
     * — `additionalProperties` DESTEKLENMEZ, eklenirse 400 döner; bilinçli olarak yok.
     */
    private const RESPONSE_FUNCTION = [
        'name' => 'provide_response',
        'description' => 'Müşteriye gönderilecek yanıtı ve tespit edilen niyeti döndür.',
        'parameters' => [
            'type' => 'object',
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

    private string $model;

    public function __construct(private string $apiKey, string $model = '')
    {
        $this->model = $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    /**
     * @param array<int, array{role:string, content:string}> $messages
     * @return array{reply:string, intent:string}
     */
    public function structuredReply(string $system, array $messages): array
    {
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => $this->toContents($messages),
            'tools' => [['functionDeclarations' => [self::RESPONSE_FUNCTION]]],
            'toolConfig' => [
                'functionCallingConfig' => [
                    'mode' => 'ANY',
                    'allowedFunctionNames' => ['provide_response'],
                ],
            ],
        ];

        // Anahtar URL yerine header ile gönderilir (loglarda sızmasın).
        $url = self::BASE_URL . rawurlencode($this->model) . ':generateContent';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-goog-api-key: ' . $this->apiKey,
            'content-type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Gemini API request failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        if ($status !== 200 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? 'unknown') : 'non-JSON response';
            throw new \RuntimeException("Gemini API error (HTTP {$status}): {$message}");
        }

        foreach ($decoded['candidates'][0]['content']['parts'] ?? [] as $part) {
            $call = $part['functionCall'] ?? null;
            if (!is_array($call) || ($call['name'] ?? '') !== 'provide_response') {
                continue;
            }
            $args = is_array($call['args'] ?? null) ? $call['args'] : [];
            $intent = (string) ($args['intent'] ?? 'unclear');
            if (!in_array($intent, ['faq', 'appointment_action', 'unclear'], true)) {
                $intent = 'unclear';
            }

            return ['reply' => (string) ($args['reply'] ?? ''), 'intent' => $intent];
        }

        throw new \RuntimeException('Gemini API returned no provide_response functionCall part.');
    }

    /**
     * Anthropic role'leri ('user'/'assistant') → Gemini role'leri ('user'/'model'). İçerik
     * Gemini'de {parts:[{text}]} olarak sarılır.
     *
     * @param array<int, array{role:string, content:string}> $messages
     * @return array<int, array{role:string, parts:array<int, array{text:string}>}>
     */
    private function toContents(array $messages): array
    {
        $contents = [];
        foreach ($messages as $m) {
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => (string) ($m['content'] ?? '')]]];
        }

        return $contents;
    }
}
