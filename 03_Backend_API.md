# 03 — Backend API

## 1. Kapsam ve Sorumluluk

Backend (PHP 8.2+), n8n ve Admin Panel'in **tek** veri erişim noktasıdır. n8n asla doğrudan
PostgreSQL'e bağlanmaz (01_System_Architecture.md). Backend üç istemciye hizmet verir:

| İstemci | Kimlik doğrulama | Amaç |
|---------|-------------------|------|
| n8n (otomasyon) | Servis token (HMAC imzalı, tenant'a bağlı değil) | Webhook sonrası tenant çözme, randevu CRUD, takvim sorgusu, mesaj kuyruğu |
| Admin Panel | Oturum (JWT, kullanıcıya bağlı `tenant_id` claim'i) | Panel CRUD'ları (hizmet, personel, ayar, rapor) |
| (yok) Meta doğrudan Backend'e bağlanmaz | — | Webhook her zaman n8n üzerinden geçer |

## 2. Kimlik Doğrulama ve Yetkilendirme

### 2.1 n8n → Backend (servis-servis)

- Paylaşımlı bir gizli anahtar (`N8N_SERVICE_SECRET`) ile her istek `X-Signature: HMAC-SHA256(body, secret)` taşır.
- Bu kanalın **tenant** context'i yoktur; `phone_number_id` veya `tenant_id` body'de gelir, Backend her endpoint'te doğrular/çözer. n8n'in tenant sınırlarını atlayabileceği tek yer olduğundan, bu rol yalnızca önceden tanımlı endpoint setine (randevu, takvim, mesaj kuyruğu) izin verir — panel CRUD'larına erişemez.

### 2.2 Panel → Backend (kullanıcı bazlı)

- Giriş: `POST /auth/login` (email + password) → JWT (`sub=user_id`, `tenant_id`, `role`, `exp` 2 saat).
- Her istekte `Authorization: Bearer <jwt>`; middleware JWT'yi doğrular, `tenant_id` claim'ini bağlantı seviyesinde `SET app.current_tenant` olarak enjekte eder (02_Database_Design.md §5 RLS ile birebir eşleşir).
- Rol bazlı yetki: `owner` tüm CRUD, `manager` randevu/müşteri/rapor, `staff` yalnızca kendi randevularını görüntüler (`GET /appointments?staff_id=me`).
- Panel kullanıcısı asla başka bir `tenant_id` isteyemez — JWT'deki `tenant_id` her sorguya sunucu tarafında eklenir, istemci parametre olarak gönderemez (gönderirse yok sayılır).

### 2.3 Ortak middleware sırası

```
Request → HMAC/JWT doğrulama → tenant_id çözümü → SET app.current_tenant
        → rate limit → route handler → repository (WHERE tenant_id=? + RLS) → response
```

İki savunma katmanı (repository filtresi + RLS) 02_Database_Design.md §5'te tanımlanan ilkeyle aynıdır; Backend bunu her istek yaşam döngüsünde tekrarlar.

## 3. Endpoint Tasarımı

### 3.1 Tenant çözümleme (n8n webhook girişi)

```
POST /internal/resolve-tenant
Body: { "phone_number_id": "..." }
200: { "tenant_id": "...", "timezone": "...", "ai_enabled": true }
404: eşleşme yok → n8n isteği durdurur, webhook_events'e ham kayıt düşürülür
```

### 3.2 Hizmet / Personel / Çalışma Saatleri (panel CRUD)

```
GET    /services            GET    /staff
POST   /services            POST   /staff
PUT    /services/{id}       PUT    /staff/{id}
DELETE /services/{id}       DELETE /staff/{id}   (soft: active=false)

GET    /staff/{id}/working-hours
PUT    /staff/{id}/working-hours      (tam hafta gönderilir, replace-all)
GET    /staff/{id}/breaks
PUT    /staff/{id}/breaks
POST   /holidays
DELETE /holidays/{id}
```

Tüm liste endpoint'leri `tenant_id` filtresini middleware'den alır; istek gövdesinde/parametresinde tenant_id kabul edilmez.

### 3.3 Müsaitlik / Takvim

```
GET /availability?service_id=..&staff_id=..&date=YYYY-MM-DD
200: { "slots": ["09:00","09:30","10:15", ...] }   (bkz. §4 algoritma)
```

### 3.4 Randevu

```
POST   /appointments                 { customer_id|whatsapp_number, staff_id, service_id, start_time }
        201: { id, status:"pending", time_range }
        409: { error:"slot_taken" }   -- exclusion constraint 23P01 yakalandı
PATCH  /appointments/{id}/confirm
PATCH  /appointments/{id}/cancel
PATCH  /appointments/{id}/reschedule { start_time }
GET    /appointments?staff_id=&date=&status=
```

### 3.5 Müşteri

```
GET  /customers?whatsapp_number=...
POST /customers        (n8n ilk temasta upsert eder)
```

### 3.6 Mesajlaşma

```
POST /messages/send            { customer_id, template_id|freeform_text }
GET  /message-templates
POST /message-templates
POST /campaigns
POST /campaigns/{id}/dispatch  -- n8n cron tetikler
```

### 3.7 AI Modülü (Faz 7 ile genişler, iskelet burada)

```
GET  /ai-settings
PUT  /ai-settings       { enabled, tone, knowledge_base }
POST /ai/respond        { tenant_id çözülmüş, customer_message } → { reply_text }
```

## 4. Takvim Algoritması — Uygun Slot Hesaplama

Girdi: `tenant_id` (context), `staff_id`, `service_id`, `date`.

```
1. duration = services.duration_minutes (service_id)
2. gün_aralığı = working_hours WHERE staff_id AND day_of_week = weekday(date)
   → boşsa: [] döndür (personel o gün çalışmıyor)
3. kapalı_dönemler = breaks WHERE staff_id AND day_of_week = weekday(date)
                    ∪ holidays WHERE (staff_id = ? OR staff_id IS NULL)
                                 AND date <@ date_range
   → holiday tüm günü kapatıyorsa: [] döndür
4. mevcut_randevular = appointments
                       WHERE tenant_id AND staff_id
                       AND status IN ('pending','confirmed')
                       AND time_range && [date 00:00, date 24:00)
5. serbest_aralıklar = gün_aralığı − kapalı_dönemler − mevcut_randevular
   (interval subtraction; her adımda sıralı aralık listesi güncellenir)
6. slotlar = serbest_aralıklar içinde `duration` uzunluğunda, sabit adımla
   (ör. 15 dk) kayan pencere; geçmiş saat + "şu andan min. X dk sonra" filtresi uygulanır
7. return slotlar
```

**Önemli ilke:** Bu algoritma yalnızca **öneri** üretir. Nihai doğruluk, `POST /appointments`
anında veritabanının `EXCLUDE USING gist` kısıtına aittir (02_Database_Design.md §6). Algoritma
ile INSERT arasında geçen sürede başka bir müşteri aynı slotu doldurabilir — bu durumda Backend
`23P01` hatasını yakalar ve `409 slot_taken` döner; n8n kullanıcıya "az önce doldu" mesajıyla
güncel `/availability` sonucunu tekrar sunar. Yani algoritma UX optimizasyonu, DB kısıtı ise
doğruluk garantisidir.

## 5. Randevu Durum Makinesi

```
pending ──confirm──▶ confirmed ──(randevu saati geçti)──▶ completed
   │                     │
   └──cancel──▶ cancelled ◀──cancel──┘
                     │
              (no-show işaretlenirse) no_show
```

- `pending → confirmed`: müşteri WhatsApp'ta onay butonuna bastığında (n8n → `PATCH /confirm`).
- `pending` durumu X dakika (panelden ayarlanır, `tenants` altına eklenecek `pending_ttl_minutes`) onaylanmazsa n8n cron ile otomatik `cancelled` yapılır — slot yeniden açılır.
- `reschedule`: eskiyi `cancelled` yapıp yeni satır açmak yerine `time_range` güncellenir (exclusion constraint UPDATE'te de geçerlidir); geçmiş izini korumak için `message_log`'da referans tutulur.

## 6. Hata Sözleşmesi (n8n ve Panel ortak formatı)

```json
{ "error": "slot_taken", "message": "Seçilen saat az önce doldu.", "details": {} }
```

Standart `error` kodları: `tenant_not_found`, `unauthorized`, `forbidden`, `validation_error`,
`slot_taken`, `not_found`, `rate_limited`, `internal_error`. n8n akışları bu kodlara göre dallanır
(05_WhatsApp_Integration.md'de mesaj eşlemesi tanımlanacak).

## 7. Rate Limiting ve Güvenlik

- Panel: kullanıcı başına 60 istek/dk (JWT `sub` bazlı).
- n8n servis kanalı: `phone_number_id` başına 20 istek/sn (Meta'nın kendi rate limitine yaklaşmadan önce Backend'de kesme).
- Tüm mutasyon endpoint'leri (`POST/PUT/PATCH/DELETE`) idempotency-key destekler (n8n retry'larında çift randevu/mesaj oluşmasın diye). Bu, `appointments` ve `message_log` tablolarına eklenecek `idempotency_key text` + `UNIQUE (tenant_id, idempotency_key)` kısıtı gerektirir — 02_Database_Design.md'ye bu faz kapsamında eklenmemiş bir alan olduğundan burada not düşülüyor, migration olarak işlenecek.

---
**STATUS: PHASE_3_COMPLETE**
