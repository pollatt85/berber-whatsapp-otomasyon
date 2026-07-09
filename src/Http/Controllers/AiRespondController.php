<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AiSettingsRepository;
use App\Repository\MessageLogRepository;
use App\Repository\ServiceRepository;
use App\Repository\StaffRepository;
use App\Repository\StaffScheduleRepository;
use App\Support\LlmClient;
use App\Support\RateLimiter;

/**
 * `POST /ai/respond` (07_AI_Module.md §2/§4/§5). n8n servis kanalı (HMAC), tenant_id body'den,
 * Connection::service() ile çağrılır (/conversation-state deseninin aynısı). AI hiçbir zaman
 * randevu oluşturmaz/değiştirmez (§1) — yalnızca bilgi verir; işlemsel niyette
 * intent='appointment_action' döner ve n8n ilgili menüyü tekrar gönderir.
 *
 * Graceful davranış: enabled=false, API anahtarı yok veya LLM hatası → HER ZAMAN 200 + sabit
 * fallback yanıtı (`source` alanı hangi yola düşüldüğünü belirtir). n8n'e asla 5xx dönmez ki
 * retry döngüsü LLM maliyetini/hatayı katlamasın (§5 rate/maliyet koruması gerekçesi).
 */
final class AiRespondController
{
    /** enabled=false / anahtar yok / hata durumunda n8n'in göndereceği sabit şablon (07§1 madde 3). */
    private const FALLBACK_REPLY =
        'Şu an bu mesajı otomatik yanıtlayamıyorum. Lütfen menüden bir seçim yaparak devam edin, '
        . 'kısa süre içinde size yardımcı olacağız.';

    public function __construct(
        private AiSettingsRepository $aiSettings,
        private ServiceRepository $services,
        private StaffRepository $staff,
        private StaffScheduleRepository $schedules,
        private MessageLogRepository $messages,
        private RateLimiter $rateLimiter,
        private ?LlmClient $llm
    ) {
    }

    public function respond(Request $request): Response
    {
        $tenantId = (string) $request->input('tenant_id', '');
        $customerId = (string) $request->input('customer_id', '');
        $message = trim((string) $request->input('customer_message', ''));

        if ($tenantId === '' || $message === '') {
            throw new ApiException('validation_error', 'tenant_id and customer_message are required.', 422);
        }

        $settings = $this->aiSettings->find($tenantId);

        // (a) enabled=false → LLM çağrısı YOK, sabit fallback (07§1 madde 3, §2 adım 2).
        if (!$this->isEnabled($settings['enabled'] ?? false)) {
            return $this->fallback('disabled');
        }

        // (b) Yapılandırılmış sağlayıcı yok (API anahtarı boş — dev'de .env'de BOŞ) → gerçek
        // çağrı yapma, graceful hata yolu.
        if ($this->llm === null) {
            return $this->fallback('no_api_key');
        }

        // (c) Tenant bazlı dakikalık LLM çağrı limiti (07§5, BACKLOG §A m.11/16) — n8n retry
        // döngüsünün sınırsız LLM maliyeti oluşturmasını engeller. Redis erişilemezse fail-open.
        $limit = (int) ($settings['rate_limit_per_minute'] ?? 10);
        if (!$this->rateLimiter->allow("ai_respond:{$tenantId}", $limit)) {
            return $this->fallback('rate_limited');
        }

        $system = $this->buildSystemPrompt($tenantId, $settings, $customerId);
        $messages = [['role' => 'user', 'content' => $message]];

        try {
            $result = $this->llm->structuredReply($system, $messages);
        } catch (\Throwable $e) {
            error_log('[ai/respond] LLM call failed: ' . $e->getMessage());
            return $this->fallback('llm_error');
        }

        return Response::json(['data' => [
            'reply' => $result['reply'],
            'intent' => $result['intent'],
            'source' => 'llm',
        ]]);
    }

    private function fallback(string $reason): Response
    {
        return Response::json(['data' => [
            'reply' => self::FALLBACK_REPLY,
            'intent' => 'unclear',
            'source' => 'fallback:' . $reason,
        ]]);
    }

    /**
     * pgsql boolean PDO'da 't'/'f' string dönebilir; ai_settings satırı yoksa DEFAULTS'tan
     * PHP false gelir. Her iki gösterimi de karşıla.
     */
    private function isEnabled(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1 || $value === 'true';
    }

    /**
     * Sistem promptu (§4): sabit rol + tone + tenant'a özgü işletme verisi (services/staff/
     * working_hours — yalnızca bu tenant, PII izolasyonu §5) + knowledge_base + son 3-5 mesaj.
     * Statik/global prompt paylaşılmaz; her istek tek tenant'ın verisiyle kurulur.
     */
    private function buildSystemPrompt(string $tenantId, array $settings, string $customerId): string
    {
        $tone = (string) ($settings['tone'] ?? 'friendly');
        $toneHint = [
            'friendly' => 'Sıcak, samimi ve yardımsever bir ton kullan.',
            'formal' => 'Resmi, saygılı ve profesyonel bir ton kullan.',
            'concise' => 'Kısa, net ve öz yanıtlar ver; gereksiz açıklamadan kaçın.',
        ][$tone] ?? 'Sıcak ve yardımsever bir ton kullan.';

        $parts = [];

        // 1. Sabit rol talimatı + guardrail'ler (§4 madde 1, §5).
        $parts[] = <<<TXT
        Sen bir berber/kuaför işletmesinin WhatsApp asistanısın. Görevin, aşağıda verilen
        işletme bilgileriyle müşterinin sorularını yanıtlamaktır.

        Katı kurallar:
        - YALNIZCA aşağıda verilen bilgilerle cevap ver. Bilgi yoksa uydurma; "bu konuda net
          bilgim yok, dilerseniz işletmeyle iletişime geçebilirsiniz" de.
        - Fiyat, süre veya hizmet listesini ASLA uydurma. Sorulan hizmet listede yoksa
          "bu hizmet hakkında net bilgim yok" de, var olmayan hizmeti onaylama.
        - Randevu OLUŞTURMA, DEĞİŞTİRME veya İPTAL ETME yetkin yok. Müşteri böyle bir işlem
          isterse (ör. "randevumu iptal et", "saat 3'e al"), bunu yapamayacağını kısaca söyle
          ve menüden ilerlemesini iste. Bu durumda intent'i 'appointment_action' olarak işaretle.
        - İşletme dışı konularda (hava durumu, genel sohbet, siyaset vb.) yardımcı olma:
          "Bu konuda yardımcı olamam, ama randevu ve hizmetlerimiz hakkında sorabilirsiniz" de.
        - Yalnızca bu işletmenin bilgileriyle konuş; başka işletmelerden bahsetme.
        - {$toneHint}
        - Yanıtını her zaman provide_response tool'u ile döndür.
        TXT;

        // 2. İşletme verisi (§3) — services / staff / working_hours, doğrudan tablolardan.
        $parts[] = "## İşletme Bilgileri\n\n" . $this->businessDataBlock($tenantId);

        // 3. Bilgi tabanı (§3): SSS + politikalar (jsonb).
        $kb = $this->decodeKnowledgeBase($settings['knowledge_base'] ?? null);
        $kbBlock = $this->knowledgeBaseBlock($kb);
        if ($kbBlock !== '') {
            $parts[] = "## Sık Sorulan Sorular ve Politikalar\n\n" . $kbBlock;
        }

        // 4. Son 3-5 mesaj (§4 madde 4) — yalnızca bu müşteri-tenant çifti.
        if ($customerId !== '') {
            $history = $this->conversationHistoryBlock($tenantId, $customerId);
            if ($history !== '') {
                $parts[] = "## Son Konuşma Geçmişi\n\n" . $history;
            }
        }

        return implode("\n\n", $parts);
    }

    private function businessDataBlock(string $tenantId): string
    {
        $lines = [];

        $services = $this->services->all($tenantId);
        if ($services !== []) {
            $lines[] = 'Hizmetler:';
            foreach ($services as $s) {
                $lines[] = sprintf(
                    '- %s: %s dk, %s TL',
                    $s['name'],
                    $s['duration_minutes'],
                    $s['price']
                );
            }
        } else {
            $lines[] = 'Hizmetler: (tanımlı hizmet yok)';
        }

        $staff = $this->staff->all($tenantId);
        $lines[] = '';
        $lines[] = 'Personel ve çalışma saatleri:';
        if ($staff === []) {
            $lines[] = '- (tanımlı personel yok)';
        }
        $days = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
        foreach ($staff as $member) {
            $hours = $this->schedules->schedule($tenantId, $member['id'])['working_hours'];
            if ($hours === []) {
                $lines[] = sprintf('- %s: çalışma saati tanımlı değil', $member['name']);
                continue;
            }
            $byDay = [];
            foreach ($hours as $h) {
                $label = $days[(int) $h['day_of_week']] ?? ('Gün ' . $h['day_of_week']);
                $byDay[] = sprintf('%s %s-%s', $label, substr((string) $h['start_time'], 0, 5), substr((string) $h['end_time'], 0, 5));
            }
            $lines[] = sprintf('- %s: %s', $member['name'], implode(', ', $byDay));
        }

        return implode("\n", $lines);
    }

    /** @return array{faq: array<int, array{q:string, a:string}>, policies: array<string, string>} */
    private function decodeKnowledgeBase(mixed $raw): array
    {
        $kb = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($kb)) {
            return ['faq' => [], 'policies' => []];
        }

        return [
            'faq' => is_array($kb['faq'] ?? null) ? $kb['faq'] : [],
            'policies' => is_array($kb['policies'] ?? null) ? $kb['policies'] : [],
        ];
    }

    private function knowledgeBaseBlock(array $kb): string
    {
        $lines = [];
        foreach ($kb['faq'] as $item) {
            $q = trim((string) ($item['q'] ?? ''));
            $a = trim((string) ($item['a'] ?? ''));
            if ($q !== '' && $a !== '') {
                $lines[] = "S: {$q}\nC: {$a}";
            }
        }
        $policyLabels = ['cancellation' => 'İptal politikası', 'late_arrival' => 'Geç kalma politikası'];
        foreach ($policyLabels as $key => $label) {
            $value = trim((string) ($kb['policies'][$key] ?? ''));
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }

        return implode("\n\n", $lines);
    }

    private function conversationHistoryBlock(string $tenantId, string $customerId): string
    {
        $lines = [];
        foreach ($this->messages->recentForCustomer($tenantId, $customerId, 5) as $row) {
            $who = ($row['direction'] ?? '') === 'inbound' ? 'Müşteri' : 'Asistan';
            $text = $this->extractText($row['content'] ?? null);
            if ($text !== '') {
                $lines[] = "{$who}: {$text}";
            }
        }

        return implode("\n", $lines);
    }

    /** message_log.content jsonb: {text:"..."} serbest metin, {template:"..."} şablon adı. */
    private function extractText(mixed $content): string
    {
        $data = is_string($content) ? json_decode($content, true) : $content;
        if (!is_array($data)) {
            return '';
        }
        if (isset($data['text']) && is_string($data['text'])) {
            return trim($data['text']);
        }
        if (isset($data['template']) && is_string($data['template'])) {
            return '[şablon: ' . $data['template'] . ']';
        }

        return '';
    }
}
