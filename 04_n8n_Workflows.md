# 04 — n8n Workflows

## 1. İlke

n8n yalnızca **orkestrasyon** yapar: webhook alır, Backend API'yi çağırır, WhatsApp mesajı
gönderir/alır, zamanlanmış tetikleyicileri yönetir. İş kuralı, veri doğrulama, çakışma kontrolü
her zaman Backend'de (03_Backend_API.md) çalışır — n8n asla PostgreSQL'e dokunmaz veya kendi
başına karar (örn. "bu slot uygun mu") vermez.

Her workflow'un girişinde ortak adım: **Meta webhook imza doğrulama** ve **tenant çözümü**
(`POST /internal/resolve-tenant`) — bu iki adım tekrar tekrar aşağıda yazılmayacak, "Ortak Giriş"
olarak referans verilecek.

### Ortak Giriş (tüm webhook tetiklemeli workflow'larda ilk 3 node)

```
1. Webhook node (POST /webhook/whatsapp)
2. Function node: X-Hub-Signature-256 doğrula (app secret ile HMAC-SHA256)
   → geçersizse: 403 döndür, akışı durdur
3. HTTP Request: POST /internal/resolve-tenant { phone_number_id }
   → 404 ise: webhook_events'e zaten Backend tarafından ham kayıt düşer, akışı durdur
   → 200 ise: tenant_id, timezone, ai_enabled sonraki node'lara aktarılır
```

## 2. Workflow: Gelen Mesaj → Randevu Akışı Başlatma

**Tetikleyici:** Meta webhook (`messages` event, ortak giriş sonrası).

```
4. Switch node: mesaj tipi
   - metin ("randevu", "merhaba" vb. anahtar kelime) → Adım 5
   - interaktif buton/liste yanıtı (customer_response_id) → Adım 8 (mevcut oturuma devam)
   - serbest metin (AI açık ise) → 07_AI_Module.md akışına yönlendirilir (Faz 7'de bağlanacak)
5. HTTP Request: POST /customers (upsert, whatsapp_number + tenant_id)
6. HTTP Request: GET /services (tenant_id context'inden)
7. HTTP Request: WhatsApp interaktif liste mesajı gönder (hizmetler + fiyatlar)
   → Meta mesaj gönderim node'u (05_WhatsApp_Integration.md'de şablon/etkileşim detayları)
```

**Oturum durumu:** n8n'in kendi hafızası yoktur; "müşteri hangi adımda" bilgisi Backend'de
tutulur (`message_log` + son etkileşim durumu) veya n8n'in workflow static data'sında
`customer_id` anahtarlı kısa ömürlü state olarak — tercih: **Backend'de tutulur**, çünkü n8n
yatay ölçeklenirse (birden fazla worker) static data paylaşılmaz. Bu nedenle her adımda n8n,
"bir sonraki adım ne" sorusunu Backend'e sorar (`GET /conversation-state?customer_id=`), Backend
kendi state machine'ini döner. *(Bu endpoint 03_Backend_API.md'ye migration ile eklenecek — bu
fazda tespit edilen bir gereksinim, madde 6'da not düşülüyor.)*

## 3. Workflow: Hizmet/Personel/Slot Seçimi

```
1. Ortak Giriş
2. Müşterinin interaktif yanıtı: service_id seçildi
   → HTTP Request: GET /staff?service_id=  (o hizmeti verebilen personeller, staff_services join)
   → WhatsApp buton mesajı: personel listesi
3. Müşteri personel seçti (veya "farketmez" seçeneği)
   → HTTP Request: GET /availability?service_id=&staff_id=&date=bugün..+7gün
   → WhatsApp liste mesajı: uygun slotlar (tarih + saat)
4. Müşteri slot seçti
   → HTTP Request: POST /appointments { customer_id, staff_id, service_id, start_time }
   → 201: WhatsApp özet + "Onaylıyor musunuz?" buton mesajı (pending)
   → 409 (slot_taken): GET /availability tekrar çağrılır, güncel liste "Bu saat az önce
     doldu, alternatifler:" mesajıyla sunulur (03_Backend_API.md §4 ilkesiyle birebir)
```

## 4. Workflow: Onay / İptal / Değiştir

```
Tetikleyici: müşteri butonuna basar (interactive reply id: confirm_<appointment_id> /
             cancel_<appointment_id> / reschedule_<appointment_id>)

1. Ortak Giriş
2. Switch: reply id öneki
   - confirm_  → PATCH /appointments/{id}/confirm → WhatsApp onay mesajı + panel bildirimi
                 (panel bildirimi: Backend'in kendi event'i, ayrıca n8n gerekmez —
                 Backend panel'e websocket/polling ile bildirir, 06_Admin_Panel.md'de netleşecek)
   - cancel_   → PATCH /appointments/{id}/cancel → WhatsApp iptal onayı
   - reschedule_ → Adım 3 (Workflow §3) yeniden tetiklenir, mevcut appointment_id
                   `reschedule` endpoint'ine bağlanır (eskiyi cancel etmez, time_range günceller)
```

## 5. Workflow: Hatırlatma (Zamanlanmış)

**Tetikleyici:** Cron node — her tenant için panelden ayarlanan `reminder_hours_before`
değerine göre **her 15 dakikada bir** taranır (tek cron tüm tenant'lara hizmet eder, n8n'de
tenant başına ayrı cron açmak ölçeklenmez).

```
1. Cron (*/15 * * * *)
2. HTTP Request: GET /internal/appointments-due-for-reminder
   → Backend, tüm tenant'lar için "confirmed" + (appointment_time - reminder_hours_before)
     şu andan itibaren 15 dk penceresinde olan randevuları döner (tenant filtresi
     Backend'de, n8n bunu tek sorguda tüm tenant'lar için ister — bu servis endpoint'i
     RLS'i servis rolüyle bypass eder, yalnızca n8n servis tokenıyla erişilir)
3. Loop (her randevu için):
   a. HTTP Request: GET /message-templates?type=reminder&tenant_id=<randevunun tenant'ı>
   b. WhatsApp şablon mesajı gönder (24 saatlik pencere dışına düştüğü varsayılır,
      01_System_Architecture.md §4 gereği şablon zorunlu)
   c. HTTP Request: POST /messages/send (message_log'a işlenir, idempotency_key =
      appointment_id + '_reminder' — aynı randevu için ikinci kez hatırlatma gitmez)
```

## 6. Workflow: Pending Randevu Otomatik İptali (TTL)

```
1. Cron (*/5 * * * *)
2. HTTP Request: GET /internal/appointments-expired-pending
   → Backend, status='pending' AND created_at + tenants.pending_ttl_minutes < now() olan
     kayıtları döner (tüm tenant'lar, servis rolü)
3. Loop: PATCH /appointments/{id}/cancel (reason: "timeout")
   → slot otomatik olarak yeniden açılır (exclusion constraint WHERE koşulu sayesinde)
```

## 7. Bu Fazda Tespit Edilen ve Sonraki Faza Bırakılan Backend Gereksinimleri

02_Database_Design.md ve 03_Backend_API.md tamamlandıktan sonra n8n akışları tasarlanırken
ortaya çıkan, ileride migration/endpoint olarak eklenecek maddeler (kod bu fazda yazılmıyor,
yalnızca kayda geçiriliyor — Development Pack ilkesi: "tamamlanan maddeleri tekrar üretme"):

- `GET /conversation-state?customer_id=` — n8n'in oturum adımını sorgulaması için (§2)
- `GET /internal/appointments-due-for-reminder` ve `GET /internal/appointments-expired-pending`
  — n8n servis tokenıyla tüm tenant'lar için tek sorguda tarama (§5, §6)
- `tenants.reminder_hours_before` ve `tenants.pending_ttl_minutes` kolonları (panelden ayarlanır)
- `idempotency_key` kolonu (`appointments`, `message_log`) — 03_Backend_API.md §7'de zaten not
  düşülmüştü, hatırlatma tekilliği için burada da kullanılıyor

## 8. Hata ve Yeniden Deneme Stratejisi

- Her HTTP Request node'unda n8n'in yerleşik retry (3 deneme, exponential backoff) açık; yalnızca
  5xx ve ağ hatalarında retry edilir, 4xx (validation/slot_taken) retry edilmez — bunlar akış
  mantığıyla (Switch/alternatif sunma) ele alınır.
- Idempotency-key tüm mutasyon çağrılarında gönderilir (03_Backend_API.md §7); n8n retry'ı çift
  randevu/mesaj oluşturmaz.
- Kritik hata (webhook imza geçersiz, tenant çözülemedi) durumunda akış sessizce durur; Backend
  zaten `webhook_events` tablosuna ham kaydı düşürdüğü için veri kaybı olmaz, yalnızca kullanıcıya
  yanıt gitmez (Meta webhook 200 dönmemiz gerektiği için n8n her durumda Meta'ya 200 döner —
  aksi halde Meta webhook'u devre dışı bırakabilir).

---
**STATUS: PHASE_4_COMPLETE**
