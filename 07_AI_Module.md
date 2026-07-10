# 07 — AI Module

Bu doküman, 02'deki `ai_settings` tablosu ve 03'teki `POST /ai/respond` iskeletini
tamamlayarak doğal dil asistanının nerede devreye girdiğini, neyi bilip neyi bilmediğini ve
04'teki n8n state-machine akışıyla nasıl bir arada çalıştığını tanımlar. Tablo/endpoint
şeması tekrar edilmez.

## 1. Kapsam Sınırı: AI Ne Zaman Devreye Girer

Randevu akışının **çekirdeği** (04'teki hizmet/personel/slot seçimi, onay/iptal) yapılandırılmış
menülerle (05'teki reply-button/list interactive) yürütülmeye devam eder — bu akış
deterministik olmalı (takvim çakışması, exclusion constraint gibi kesin kurallar içeriyor),
serbest metin/LLM'e bırakılmaz.

AI yalnızca şu durumlarda devreye girer:

1. Müşteri mesajı, n8n'in beklediği yapılandırılmış girdiye (buton/list seçimi, sayı, evet/hayır)
   uymuyorsa — ör. "yarın saat kaça kadar açıksınız?", "saç kesimi ne kadar sürer?"
2. Randevu akışı dışında serbest soru geldiğinde (adres, fiyat, iptal politikası vb.)
3. `ai_settings.enabled = false` ise bu adım tamamen atlanır; n8n sabit bir "Anlayamadım,
   lütfen menüden seçim yapın" şablon mesajına düşer (04'teki mevcut hata yolu, değişmedi)

**Netleşmiş kural:** AI hiçbir zaman randevu oluşturmaz/değiştirmez/iptal etmez. Yalnızca
bilgi verir; işlemsel niyet tespit ederse (ör. "randevumu iptal et") kullanıcıyı ilgili
yapılandırılmış akışa yönlendiren bir mesajla birlikte ilgili menüyü tekrar gönderir.

## 2. Akışa Entegrasyon (n8n)

04'teki ortak giriş bloğundan sonra, mevcut "beklenen girdi" state'i (Backend'de tutulan
conversation-state, 04 madde 7) ile gelen mesaj eşleşmezse:

```
1. n8n → Backend: POST /ai/respond { customer_message, conversation_state }
2. Backend: ai_settings.enabled kontrolü
   → false: sabit fallback şablonu, adım biter
3. Backend: knowledge_base + tenant iş verisi (services, staff, working_hours) context'e
   enjekte edilir (§3)
4. Backend → LLM sağlayıcı API çağrısı (§4)
5. Backend: yanıtı guardrail filtresinden geçirir (§5) → { reply_text, intent }
6. n8n: reply_text'i serbest metin olarak gönderir (05 §3.1 — 24 saat penceresi kuralı
   burada da geçerli, pencere kapalıysa AI yanıtı yerine şablon fallback'i devreye girer)
   intent = 'appointment_action' ise ilgili menü tekrar gönderilir (adım 3'teki not)
```

AI yanıtı **Backend** tarafından üretilir, n8n LLM'e doğrudan erişmez — bu, 03/04'teki
"n8n servis kanalı yalnızca önceden tanımlı endpoint setine erişir" prensibiyle tutarlıdır
(LLM API anahtarı yalnızca Backend'de tutulur).

## 3. Bilgi Tabanı (Knowledge Base) Yapısı

`ai_settings.knowledge_base` (jsonb) serbest formatlı SSS/politika metni tutar, panelden
(06 `/settings/ai`) düzenlenir:

```json
{
  "faq": [
    {"q": "Kapıda ödeme var mı?", "a": "Evet, nakit ve kart kabul ediyoruz."},
    {"q": "Otopark var mı?", "a": "Bina önünde ücretsiz otopark mevcut."}
  ],
  "policies": {
    "cancellation": "Randevudan 2 saat öncesine kadar ücretsiz iptal edilebilir.",
    "late_arrival": "10 dakikadan fazla gecikmede randevu iptal sayılabilir."
  }
}
```

Bunun yanında, **işletmeye özel yapılandırılmış veri** (fiyat, süre, çalışma saati) prompt'a
`knowledge_base`'den değil doğrudan `services`, `staff`, `working_hours` tablolarından
enjekte edilir — panelde iki kez aynı bilgiyi girmek zorunda kalmamak ve tutarsızlığı
önlemek için (ör. "saç kesimi ne kadar" sorusu `services.price`/`duration_minutes`'tan
cevaplanır, `knowledge_base.faq`'a fiyat elle yazılmaz).

## 4. LLM Sağlayıcı ve Prompt Yapısı

- Sağlayıcı: Claude API (Anthropic), model seçimi maliyet/gecikme dengesine göre küçük bir
  model (Haiku sınıfı) — bu görev kısa SSS yanıtı, ağır muhakeme gerektirmiyor.
- Sistem promptu her istekte şu bileşenlerden kurulur:
  1. Sabit rol talimatı ("sen bir berber işletmesinin WhatsApp asistanısın, yalnızca
     verilen bilgilerle cevap ver, uydurma")
  2. Tenant'ın `tone` alanı (`friendly/formal/concise` — 02'de mevcut kolon)
  3. Enjekte edilen işletme verisi (§3) — her istekte tenant'a özgü, statik/global prompt
     paylaşılmaz (tenant izolasyonu prompt seviyesinde de korunur)
  4. Son 3-5 mesajlık konuşma geçmişi (`message_log`'dan, yalnızca o müşteri-tenant çifti)
- Yanıt formatı: Backend, LLM'den yapılandırılmış çıktı ister (`{"reply": "...", "intent":
  "faq"|"appointment_action"|"unclear"}`) — serbest metin ayrıştırma yerine tool-use/JSON
  modu kullanılır (Claude API tool-use).

## 5. Guardrail'ler

- **Kapsam dışı konu:** Prompt, işletme dışı sorularda (hava durumu, genel sohbet) kısa bir
  yönlendirme ("Bu konuda yardımcı olamam, ama randevu/hizmetlerimiz hakkında sorabilirsiniz")
  vermesi için talimatlandırılır.
- **Fiyat/süre uydurmama:** `services` tablosunda karşılığı olmayan bir hizmet sorulursa
  ("kaş alımı var mı?") ve `knowledge_base.faq`'da da yoksa, AI "bu konuda net bilgim yok"
  demeli — var olmayan hizmeti onaylamamalı (yanlış bilgi randevu hayal kırıklığına yol açar).
- **PII sızıntısı:** Prompt'a yalnızca ilgili tenant'ın verisi enjekte edilir; LLM
  sağlayıcısına başka tenant'ların verisi hiçbir şekilde gönderilmez (context izolasyonu,
  RLS'nin prompt seviyesindeki karşılığı).
- **Rate/maliyet koruması:** `POST /ai/respond` tenant başına dakikada N istekle sınırlanır
  (03'teki genel rate limit mekanizmasının bu endpoint'e özel eşiği) — LLM maliyeti
  n8n retry döngüsüyle katlanmasın diye.

## 6. Panel Bağlantısı (06 ile tutarlılık)

06'da tanımlanan `/settings/ai` sayfası artık şu alanlarla netleşir:

- `enabled` (aç/kapa switch)
- `tone` (seçim kutusu: friendly/formal/concise)
- `knowledge_base.faq` (soru-cevap çift listesi, ekle/sil/düzenle)
- `knowledge_base.policies.cancellation`, `late_arrival` (serbest metin alanlar)

Fiyat/süre/çalışma saati AI'a otomatik yansır (§3), panelde ayrıca AI'a özel
girilmez — tek veri kaynağı korunur.

## 7. Bu Fazda Tespit Edilen Backend Gereksinimleri (henüz eklenmemiş)

- `POST /ai/respond` için tenant bazlı rate limit eşiği (§5) — 03'teki genel rate limit
  mekanizmasına bu endpoint için özel bir eşik parametresi eklenmesi gerekiyor
- LLM sağlayıcı API anahtarının tenant başına değil **uygulama genelinde** tek anahtar
  olarak saklanması yeterli (WhatsApp token'ının aksine, LLM çağrısı tenant adına değil
  Backend adına yapılır) — mevcut sırlar yönetimine ek gerektirmez, yalnızca not

Sonraki faz: **Faz 8 — 08_Test_Pilot.md** (pilot işletmeler, test senaryoları, hata kayıtları)
