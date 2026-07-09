# 05 — WhatsApp Integration (Meta Cloud API)

Bu doküman, 01'de kararlaştırılan Meta Cloud API kullanımını ve 04'te tanımlanan n8n
"ortak giriş" bloğunu somut protokol seviyesinde detaylandırır. Mimari kararlar (Cloud API
seçimi, `phone_number_id` → tenant eşleştirme, 24 saatlik pencere, tenant başına token)
tekrar edilmez; bkz. 01_System_Architecture.md.

## 1. Tenant Onboarding (WABA Bağlama)

Her berber kendi WhatsApp Business hesabını (WABA) Meta Embedded Signup akışıyla bağlar:

1. Panelden "WhatsApp Bağla" → Meta Embedded Signup (Facebook Login for Business) popup
2. Meta geri dönüşte `code` verir → Backend bunu `access_token` ile değiştirir (Graph API
   `/oauth/access_token`)
3. Backend, tenant'ın `phone_number_id`, `waba_id`, `access_token` (şifreli, KMS/sırlar
   tablosunda) ve `business_id` bilgilerini `tenants` tablosuna yazar
4. Backend, bu `phone_number_id` için Meta App'te tanımlı webhook'a abone olur
   (`/{waba-id}/subscribed_apps` POST)
5. Panel, doğrulama amaçlı bir test mesajı gönderttirir (`hello_world` şablonu) — başarılıysa
   tenant `whatsapp_status = 'connected'` olur

**Not:** `tenants.access_token` alanı 02'deki şemada yoktu; bu faz için migration gerekli
(bkz. §7 Backend Gereksinimleri).

## 2. Webhook Doğrulama ve Kabul

### 2.1 Abonelik Doğrulama (GET)

Meta, webhook URL'sini kaydederken `GET /webhook/whatsapp?hub.mode=subscribe&hub.verify_token=...&hub.challenge=...`
gönderir. Backend (n8n değil — bu adım tek seferlik ve tenant'tan bağımsız, uygulama
genelinde tek `WEBHOOK_VERIFY_TOKEN` ile karşılaştırılır) `hub.verify_token` eşleşirse
`hub.challenge` değerini düz metin olarak döner, aksi halde 403.

### 2.2 Olay Kabulü (POST) — n8n ortak giriş bloğunun genişletilmiş hali

04'te tanımlanan 3 node'a ek olarak burada protokol detayları:

1. **İmza doğrulama:** `X-Hub-Signature-256` header'ı, ham request body + **uygulama
   genelindeki** Meta App Secret ile HMAC-SHA256 hesaplanıp `sha256=<hex>` biçiminde
   karşılaştırılır. Bu adım tenant'tan bağımsızdır (tüm tenantlar aynı Meta App altında,
   farklı WABA/numara ile çalışır).
2. **Ham kayıt:** Backend, imza geçerliyse dahi tenant çözülmeden önce `webhook_events`
   tablosuna `entry[0].changes[0].value.metadata.phone_number_id` ile ham payload'ı yazar
   (denetim izi + tenant eşleşmezse veri kaybını önler).
3. **Tenant çözümü:** `phone_number_id` → `tenants.phone_number_id` eşleşmesi; bulunamazsa
   404 (webhook_events kaydı zaten düşmüş durumda), n8n akışı durur.
4. **Her durumda Meta'ya 200:** Meta 5 saniye içinde 2xx almazsa yeniden dener ve tekrarlı
   denemeler webhook'u geçici olarak devre dışı bırakabilir; bu nedenle n8n workflow'un son
   node'u koşulsuz `200 OK` döner (04 §"Hata/Retry" ile tutarlı).

### 2.3 Gelen Olay Tipleri

`entry[].changes[].value` içinde ayrım yapılması gereken alanlar:

| Alan | Anlam | Bu fazda işlenir mi |
|------|-------|----------------------|
| `messages[]` | Müşteriden gelen mesaj (text/interactive/image/location) | Evet — 04'teki "randevu başlatma" akışını tetikler |
| `statuses[]` | Giden mesajın durumu (sent/delivered/read/failed) | Evet — `message_log.status` günceller |
| `errors[]` | Mesaj gönderim hatası (ör. şablon reddi, numara kayıtlı değil) | Evet — `message_log.status='failed'` + panelde uyarı |

`statuses[]` ve `messages[]` aynı payload'da birlikte gelmez; n8n Switch node'u
`value.messages` / `value.statuses` alanının varlığına göre dallanır.

## 3. Giden Mesaj Türleri

Cloud API'de mesaj gönderimi `POST /{phone_number_id}/messages`, `Authorization: Bearer
{tenant_access_token}` ile yapılır. Backend bu çağrıyı n8n adına yapar (n8n, Backend'in
`/internal/whatsapp/send` endpoint'ini çağırır — token'lar n8n'e asla verilmez, yalnızca
Backend'de tutulur; bu, 03'teki "n8n servis kanalı önceden tanımlı endpoint seti"
prensibiyle tutarlıdır).

### 3.1 Serbest Metin (yalnızca 24 saat penceresi içinde)

Müşteri son 24 saat içinde mesaj göndermişse serbest `type: text` mesajı kullanılabilir
(ör. "Randevunuz onaylandı" gibi dinamik ama şablon dışı yanıtlar). Pencere dışına
çıkıldıysa Backend bu isteği reddeder (`409 window_closed`), n8n şablon mesaja düşer.

### 3.2 Şablon Mesajlar (`type: template`) — 24 saat dışı zorunlu

`message_templates` tablosundaki `meta_template_name` + `variables` kullanılarak
oluşturulur. Şablon kategorileri (Meta tarafı onay gerektirir):

| `template_type` (DB) | Meta kategorisi | Örnek kullanım |
|---|---|---|
| `confirmation` | UTILITY | Randevu onayı |
| `reminder` | UTILITY | Randevu hatırlatma (04'teki cron) |
| `cancellation` | UTILITY | İptal bildirimi |
| `campaign` | MARKETING | Toplu kampanya (`campaigns` tablosu) |

Şablon gövdesi Meta Business Manager'da önceden tanımlanıp onaylanmalıdır; Backend yalnızca
`components[].parameters[]` içine `variables` sırasıyla değişken doldurur. Onaysız/reddedilmiş
şablonla gönderim denemesi Meta'dan `132001` hata koduyla döner → `message_log.status='failed'`
+ panelde "şablon onaylı değil" uyarısı.

### 3.3 Etkileşimli Menüler (`type: interactive`)

İki alt tip kullanılır:

- **Reply Buttons** (`interactive.type: button`, en fazla 3 buton): Evet/Hayır tipi kararlar
  — ör. "Randevunuzu onaylıyor musunuz?" → [Onayla] [İptal Et]
- **List Message** (`interactive.type: list`, en fazla 10 satır/bölüm): Çok seçenekli
  seçimler — ör. hizmet seçimi, personel seçimi, uygun slot listesi (03'teki takvim
  algoritmasının çıktısı buraya `rows[]` olarak eşlenir, her `row.id` = `slot_start_iso`)

Buton/list tıklaması geri webhook'a `messages[0].interactive.button_reply.id` veya
`list_reply.id` olarak düşer; n8n bu `id` değerini 04'teki ilgili state-machine adımına
(hizmet/personel/slot seçimi) parametre olarak geçirir.

### 3.4 Medya ve Konum

- Müşteri konum gönderirse (`messages[0].location`) — bu projede randevu akışı için
  kullanılmaz, yalnızca `message_log.content` içine ham veri olarak kaydedilir.
- Berber tarafı (panel) müşteriye görsel gönderemez (kapsam dışı, MARKETING şablonlarında
  header image Meta tarafında önceden şablona gömülü olabilir — dinamik medya yüklemesi bu
  fazda yok).

## 4. Konuşma Penceresi (24 Saat) Yönetimi

- Her gelen müşteri mesajı `message_log`'a `direction='inbound'` yazılırken, o mesajın
  `sent_at` değeri o tenant-müşteri çifti için "pencere başlangıcı" sayılır.
- Backend, giden serbest metin isteğinde en son inbound mesajı sorgular
  (`SELECT max(sent_at) FROM message_log WHERE tenant_id=... AND customer_id=... AND
  direction='inbound'`); `now() - sent_at > interval '24 hours'` ise `409 window_closed`.
- Bu kontrol yalnızca Backend'de yapılır (n8n'in kendi state'i yok — 04'teki karara paralel).

## 5. Giden Mesaj Hata Kodları (Backend Eşleme Tablosu)

| Meta hata kodu | Anlam | Backend/n8n davranışı |
|---|---|---|
| `131047` | 24 saat penceresi kapalı, şablon gerekli | n8n şablon mesaja düşer |
| `132001` | Şablon onaylı/mevcut değil | `message_log.status='failed'`, panelde uyarı, retry yok |
| `131026` | Alıcı numarası WhatsApp'ta değil | `message_log.status='failed'`, müşteri kaydına işaretlenir |
| `131056` | Hız sınırı (spam benzeri toplu gönderim) | Backend exponential backoff ile yeniden kuyruğa alır (yalnızca kampanya gönderimlerinde) |
| `190` | Access token geçersiz/süresi dolmuş | Tenant `whatsapp_status='disconnected'`, panelde "yeniden bağlan" uyarısı |

Genel kural (04 ile tutarlı): 4xx sınıfı kalıcı hatalar (`132001`, `131026`) yeniden
denenmez; yalnızca `131056` (hız sınırı) ve ağ zaman aşımları retry edilir.

## 6. Panelden Şablon Yönetimi (06'ya devreden kapsam)

Bu fazda yalnızca veri modeli ve senkronizasyon kuralı belirlenir; panel UI 06'da:

- Şablonlar Meta Business Manager'da oluşturulup onaylandıktan sonra, Backend
  `GET /{waba_id}/message_templates` ile senkronize eder ve `message_templates` tablosuna
  yansıtır (yalnızca okuma senkronizasyonu; şablon oluşturma bu fazda panelden yapılmaz).
- Şablon durumu (`PENDING/APPROVED/REJECTED`) `message_templates.active` alanına
  `APPROVED` → `true`, diğerleri → `false` olarak eşlenir.

## 7. Bu Fazda Tespit Edilen Backend Gereksinimleri (henüz eklenmemiş)

Önceki fazlarda olduğu gibi (bkz. 04 madde 7), burada tespit edilenler sonraki migration
turunda işlenecek:

- `tenants` tablosuna eklenmesi gereken kolonlar: `waba_id text`, `access_token_encrypted
  bytea`, `whatsapp_status text CHECK (IN ('pending','connected','disconnected'))`
- Yeni internal endpoint'ler: `POST /internal/whatsapp/send` (n8n → Backend, tüm giden mesaj
  türlerini kapsar), `POST /internal/whatsapp/templates/sync` (Meta → Backend şablon
  senkronizasyonu)
- `message_log.content` şemasına `meta_error_code` alanı eklenmesi önerilir (şu an yalnızca
  `status` var, hata kodu ham JSON içinde gömülü kalıyor — raporlama için ayrı kolon daha
  uygun)

Sonraki faz: **Faz 6 — 06_Admin_Panel.md** (responsive panel, dashboard, takvim, ayarlar,
şablon/kampanya yönetimi UI'ı)
