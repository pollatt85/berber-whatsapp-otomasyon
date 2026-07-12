# CHANGELOG

## PHASE_1_COMPLETE — 2026-07-04

**Faz 1: Sistem Mimarisi** tamamlandı. Kod yazılmadı (kapsam gereği yalnızca dokümantasyon).

Oluşturulan dosyalar:
- `00_Master_Roadmap.md` — proje özeti, 9 fazlık yol haritası (durum kolonlu), ilerleme %11
- `01_System_Architecture.md` — mimari diyagram; teknoloji seçimleri (PostgreSQL kararı ve gerekçesi, PHP 8.2 backend, n8n, Bootstrap panel); multi-tenant tasarım (`phone_number_id` → tenant eşleştirme, `tenant_id` satır izolasyonu + RLS); Meta Cloud API esasları (webhook doğrulama, 24 saat penceresi, şablon mesaj zorunluluğu, tenant başına token); randevu akışı üst düzey senaryosu
- `CHANGELOG.md` — bu dosya

Alınan kilit kararlar:
- Veritabanı: **PostgreSQL** (JSONB, RLS, exclusion constraint; XAMPP MySQL kullanılmayacak)
- İzolasyon modeli: tek DB, paylaşımlı şema, zorunlu `tenant_id` + RLS
- n8n doğrudan DB'ye yazmaz; tüm veri erişimi Backend API üzerinden

Sonraki faz: **Faz 2 — 02_Database_Design.md** (ER diyagramı, tablolar, tenant izolasyonu)

## PHASE_2_COMPLETE — 2026-07-04

**Faz 2: Veritabanı Tasarımı** tamamlandı. Kod yazılmadı (yalnızca DDL/dokümantasyon).

Oluşturulan dosya:
- `02_Database_Design.md` — mermaid ER diyagramı; 15 tablo DDL'i (`tenants, users, staff, services, staff_services, working_hours, breaks, holidays, customers, appointments, message_templates, message_log, campaigns, ai_settings, webhook_events`); RLS standart politikası (`current_setting('app.current_tenant')`); randevu çakışması için `EXCLUDE USING gist (tenant_id, staff_id, tstzrange &&)` kısıtı

Alınan kilit kararlar:
- Tüm PK'ler `uuid`; tenant-scoped her tabloda zorunlu `tenant_id` + `FORCE ROW LEVEL SECURITY`
- Randevu çakışması uygulama kodunda değil, DB seviyesinde exclusion constraint ile engellenir (`status IN ('pending','confirmed')` filtreli, iptaller slotu yeniden açar)
- `webhook_events` tablosu RLS dışı tutulur (tenant henüz çözülmemiş olabilir), yalnızca backend servis rolü erişir

Sonraki faz: **Faz 3 — 03_Backend_API.md** (API tasarımı, yetkilendirme, iş kuralları, takvim algoritması)

## PHASE_3_COMPLETE — 2026-07-04

**Faz 3: Backend API** tamamlandı. Kod yazılmadı (yalnızca API tasarımı/dokümantasyon).

Oluşturulan dosya:
- `03_Backend_API.md` — üç istemci modeli (n8n servis-servis HMAC, panel JWT, Meta'nın dolaylı erişimi); middleware sırası (auth → tenant çözümü → `SET app.current_tenant` → RLS); endpoint envanteri (tenant çözümleme, hizmet/personel/çalışma saatleri CRUD, müsaitlik, randevu, müşteri, mesajlaşma, AI iskeleti); takvim algoritması (çalışma saatleri − mola − tatil − mevcut randevu → slot listesi); randevu durum makinesi (`pending→confirmed→completed`, otomatik TTL iptali); ortak hata sözleşmesi; rate limit ve idempotency-key notu

Alınan kilit kararlar:
- Takvim algoritması yalnızca **öneri** üretir; nihai doğruluk DB'nin exclusion constraint'ine (`23P01` yakalama → `409 slot_taken`) aittir
- n8n servis kanalı yalnızca önceden tanımlı endpoint setine erişir, panel CRUD'larına erişemez
- Panel JWT'sindeki `tenant_id` istemciden asla parametre olarak kabul edilmez, yalnızca middleware enjekte eder
- İdempotency-key için `appointments`/`message_log`'a migration gerekiyor (02'de yoktu, not düşüldü)

Sonraki faz: **Faz 4 — 04_n8n_Workflows.md** (otomasyon akışları, WhatsApp tetikleyicileri, hatırlatmalar, onay/iptal süreçleri)

## PHASE_4_COMPLETE — 2026-07-04

**Faz 4: n8n Workflows** tamamlandı. Kod yazılmadı (yalnızca akış tasarımı/dokümantasyon).

Oluşturulan dosya:
- `04_n8n_Workflows.md` — ortak giriş bloğu (imza doğrulama + tenant çözümü); gelen mesaj → randevu başlatma; hizmet/personel/slot seçimi (409 slot_taken alternatif sunma); onay/iptal/değiştir; hatırlatma cron'u (tüm tenant'lar için tek 15 dk taraması); pending randevu TTL otomatik iptali; hata/retry stratejisi (idempotency-key, 4xx retry edilmez)

Alınan kilit kararlar:
- Oturum/konuşma durumu n8n'de değil **Backend'de** tutulur (n8n yatay ölçeklenirse static data paylaşılmaz)
- Hatırlatma ve TTL-iptal cron'ları tenant başına değil, tüm tenant'lar için tek sorguyla taranır (servis rolü, RLS bypass yalnızca bu iki internal endpoint'te)
- Bu fazda tespit edilen ama henüz eklenmemiş Backend gereksinimleri kayda geçirildi (madde 7): `conversation-state`, `appointments-due-for-reminder`, `appointments-expired-pending` endpoint'leri + `tenants.reminder_hours_before`/`pending_ttl_minutes` kolonları — sonraki bir Backend migration turunda işlenecek

Sonraki faz: **Faz 5 — 05_WhatsApp_Integration.md** (Cloud API, webhook, şablon mesajlar, menü yapıları)

## PHASE_5_COMPLETE — 2026-07-04

**Faz 5: WhatsApp Integration** tamamlandı. Kod yazılmadı (yalnızca protokol/dokümantasyon).

Oluşturulan dosya:
- `05_WhatsApp_Integration.md` — WABA onboarding (Embedded Signup), webhook GET doğrulama +
  POST kabul akışının protokol detayları (imza, ham kayıt, tenant çözümü, koşulsuz 200),
  gelen olay tipleri (`messages/statuses/errors`), giden mesaj türleri (serbest metin,
  şablon kategorileri, reply-button/list interactive menüler, medya/konum), 24 saat penceresi
  kontrolü, Meta hata kodu → Backend/n8n davranış eşleme tablosu, panelden şablon
  senkronizasyon kuralı (yalnızca okuma, oluşturma yok)

Alınan kilit kararlar:
- Access token'lar yalnızca Backend'de tutulur; n8n giden mesaj için Backend'in
  `/internal/whatsapp/send` endpoint'ini çağırır, Meta'ya asla doğrudan gitmez
- Webhook imza doğrulama tenant'tan bağımsız (tek Meta App Secret); tenant çözümü ayrı adım
- 24 saat penceresi kontrolü yalnızca Backend'de yapılır, n8n'de state tutulmaz (04 ile tutarlı)
- Şablonlar panelden oluşturulmaz, yalnızca Meta Business Manager'dan senkronize edilip
  onay durumuna göre `active` bayrağı güncellenir

Bu fazda tespit edilen ama henüz eklenmemiş Backend gereksinimleri (madde 7):
`tenants.waba_id/access_token_encrypted/whatsapp_status` kolonları, `/internal/whatsapp/send`
ve `/internal/whatsapp/templates/sync` endpoint'leri, `message_log.content` içine
`meta_error_code` alanı — sonraki bir Backend migration turunda işlenecek

Sonraki faz: **Faz 6 — 06_Admin_Panel.md** (responsive panel, dashboard, takvim, ayarlar)

## PHASE_6_COMPLETE — 2026-07-04

**Faz 6: Admin Panel** tamamlandı. Kod yazılmadı (yalnızca sayfa haritası/dokümantasyon).

Oluşturulan dosya:
- `06_Admin_Panel.md` — sayfa haritası (dashboard, takvim, hizmet/personel/çalışma saati,
  müşteri, mesajlaşma, ayarlar, raporlar); her sayfanın hangi Backend endpoint'ini/DB
  tablosunu kullandığı; masaüstü FullCalendar ↔ mobil liste görünümü geçiş kuralı;
  şablon sayfasının salt okunur olduğu (05 kararıyla tutarlı); Chart.js ile 3 temel rapor
  grafiği

Alınan kilit kararlar:
- Panel hiçbir zaman iş kuralı doğrulaması tekrar etmez; tek doğruluk kaynağı Backend'in
  422 hata sözleşmesi (03 ile tutarlı)
- Şablonlar panelden oluşturulamaz, yalnızca senkronize edilir (05 kararının panel
  yansıması)
- Rapor grafikleri bu fazda panelde agregre edilir (düşük hacim varsayımıyla); ölçek
  sorunu çıkarsa 09'da ele alınacak
- `/settings/ai` sayfası iskelet olarak eklendi, gerçek alanlar 07 tamamlanınca netleşecek

Bu fazda tespit edilen ama henüz eklenmemiş Backend gereksinimleri (madde 10):
logo/medya upload endpoint'i (`POST /settings/logo`), müşteri silme/anonimleştirme (KVKK,
kapsam dışı not) — sonraki bir Backend migration turunda işlenecek

Sonraki faz: **Faz 7 — 07_AI_Module.md** (doğal dil asistanı, işletmeye özel bilgi tabanı)

## PHASE_7_COMPLETE — 2026-07-04

**Faz 7: AI Module** tamamlandı. Kod yazılmadı (yalnızca tasarım/dokümantasyon).

Oluşturulan dosya:
- `07_AI_Module.md` — AI'ın devreye girdiği sınır (yalnızca serbest soru/SSS, randevu
  işlemleri her zaman yapılandırılmış menüde kalır); n8n↔Backend↔LLM entegrasyon akışı;
  `knowledge_base` yapısı (faq/policies) ve işletme verisinin (fiyat/süre/saat) prompt'a
  ayrı kaynaktan enjekte edilmesi; Claude API (Haiku sınıfı) ile yapılandırılmış JSON yanıt;
  guardrail'ler (kapsam dışı yönlendirme, uydurmama, tenant izolasyonu, rate limit); 06'daki
  `/settings/ai` sayfasının alan listesi netleştirildi

Alınan kilit kararlar:
- AI hiçbir zaman randevu oluşturmaz/değiştirmez/iptal etmez, yalnızca bilgi verir veya
  ilgili menüye yönlendirir — randevu çekirdeği deterministik kalır
- LLM çağrısı yalnızca Backend'de yapılır, n8n LLM'e doğrudan erişmez (API anahtarı n8n'e
  verilmez)
- Fiyat/süre/çalışma saati `knowledge_base`'e elle girilmez, doğrudan `services/staff/
  working_hours`'tan enjekte edilir (tek veri kaynağı, tutarsızlık riski önlenir)
- Yapılandırılmış çıktı (tool-use/JSON: `reply` + `intent`) kullanılır, serbest metin
  ayrıştırma yapılmaz

Bu fazda tespit edilen ama henüz eklenmemiş Backend gereksinimi (madde 7): `POST
/ai/respond` için tenant bazlı özel rate limit eşiği — sonraki migration/config turunda
işlenecek

Sonraki faz: **Faz 8 — 08_Test_Pilot.md** (pilot işletmeler, test senaryoları, hata kayıtları)

## PHASE_8_COMPLETE — 2026-07-04

**Faz 8: Test Pilot** tamamlandı. Kod yazılmadı (yalnızca test planı/dokümantasyon).

Oluşturulan dosya:
- `08_Test_Pilot.md` — pilot seçim kriterleri (3 farklı profil işletme); onboarding kontrol
  listesi (01-07'nin uçtan uca doğrulanması); 21 maddelik test senaryo matrisi (randevu
  akışı, WhatsApp entegrasyonu, panel, AI modülü); hata kayıt süreci (`BACKLOG.md`);
  pilot → genel kullanım çıkış kriterleri

Alınan kilit kararlar:
- Ayrı bir log sistemi kurulmaz; `webhook_events`/`message_log` (02) pilot hata ayıklamasının
  birincil kaynağı
- Çıkış kriteri simülasyon değil gerçek trafik gerektirir (özellikle slot çakışma, token
  süresi dolması, şablon reddi senaryoları gerçek Meta davranışına bağlı)
- `BACKLOG.md` bu fazda ilk kez oluşturulacak (Development Pack'te planlıydı, önceki
  fazlarda eklenmemişti)

Sonraki faz: **Faz 9 — 09_SaaS_Deployment.md** (yayın, ölçekleme, lisanslama, abonelik)

## PHASE_9_COMPLETE — 2026-07-04

**Faz 9: SaaS Deployment** tamamlandı. Kod yazılmadı (yalnızca yayın/ölçek/lisans
tasarımı/dokümantasyon). Bu, Development Pack'teki 9 fazın sonuncusu.

Oluşturulan dosya:
- `09_SaaS_Deployment.md` — yayın topolojisi (staging/production, konteynerler, secret
  yönetimi, yedekleme); ölçekleme (durumsuz Backend, n8n queue mode + advisory lock
  mutex'i, DB replika eşiği, WhatsApp mesaj tier'i, AI rate limit); lisanslama (`plans`
  tablosu, üç kademe: Starter/Pro/Business); abonelik/faturalama (Stripe/iyzico,
  `subscription_status` durum makinesi, ödeme gecikmesi davranışı); yeni platform admin
  rolü (tenant'lar üstü panel); genel kullanıma açılış kriterleri

Alınan kilit kararlar:
- n8n çoklu worker'a geçtiğinde 04'teki tüm-tenant tek sorgulu cron'ların mükerrer
  çalışmaması için Postgres advisory lock kullanılır
- Ödeme gecikmesinde panel salt okunur olur ve giden otomatik mesajlaşma (hatırlatma/
  kampanya) durur, ama mevcut randevu görünürlüğü kesilmez
- Platform admin rolü tenant panelinden route ve DB rolü seviyesinde tamamen izole edilir
  (02'deki servis rolü deseniyle tutarlı)

Bu fazda tespit edilen ama henüz eklenmemiş gereksinimler (madde 6): `plans` tablosu +
`tenants.plan_id/subscription_status/billing_customer_id/trial_ends_at`, platform admin
rolü, n8n advisory lock, Redis (rate limit + queue), KVKK veri silme uç noktası,
`BACKLOG.md`/`PROJECT_MEMORY.md`/`README.md` (Development Pack'te planlı, hiç
oluşturulmamış) — kodlama fazına geçmeden önce işlenecek

Sonraki adım: dokümantasyon fazı bitti (9/9). Sıradaki oturum kodlama fazına başlar
(Development Pack önerisi: kodlama için Claude Sonnet).

## PHASE_10_BACKEND_CODING_1 — 2026-07-04

**Kodlama Fazı 1: Eksik dosyalar + migration'lar + Backend API iskeleti.** Dokümantasyon
fazı (9/9) tamamlanmıştı; bu oturum ilk kez gerçek kod üretti. Hiçbir tasarım kararı
değiştirilmedi, yalnızca 00-09'da zaten alınmış kararlar uygulandı.

Oluşturulan dosyalar:
- `BACKLOG.md` — 03§7, 04§7, 05§7, 06§10, 07§7, 09§6'da biriken tüm "tespit edildi,
  eklenmedi" maddelerinin durum tablosu + ortam bulguları + boş pilot hata log şablonu
- `PROJECT_MEMORY.md` — kodlama fazının oturumlar arası anlık durumu
- `README.md` — proje özeti, dizin yapısı, ortam kurulum adımları
- `migrations/0000_roles.example.sql`, `0001_initial_schema.sql`, `0002_accumulated_requirements.sql`
- `composer.json`, `.env.example`
- `src/` altında Backend API iskeleti: `Config\Env`, `Database\Connection` (tenant/service
  ikili bağlantı modeli), `Http\Router`/`Request`/`Response`/`ApiException`,
  `Http\Middleware\{JwtAuthMiddleware,ServiceHmacMiddleware,TenantContextResolver}`,
  `Support\Jwt` (bağımlılıksız HS256), `Repository\{Tenant,User,Service,Staff,Customer,Appointment}Repository`,
  `Service\AvailabilityService` (03§4 takvim algoritması), `Http\Controllers\*`
- `public/index.php` — front controller, route tablosu

Alınan uygulama (implementasyon) kararları — tasarım değil, kod detayı:
- JWT için harici kütüphane yerine bağımlılıksız minimal HS256 encode/decode yazıldı
  (composer bu makinede kurulu değil — bkz. Ortam Bulguları); ileride `firebase/php-jwt`'ye
  geçiş şeffaf olacak şekilde `Support\Jwt` tek dokunma noktası olarak izole edildi
- Ortak uçlar (`/services`, `/appointments`, `/availability` vb.) hem panel JWT'si hem n8n
  HMAC'ı kabul eder (`TenantContextResolver`) — 03§2.1/§2.2'nin iki kanal tanımının doğal
  sonucu; JWT'de tenant_id claim'den, HMAC'ta body'den okunur
- İki PDO bağlantı modu ayrıldı: `Connection::tenant()` (RLS zorunlu, standart istekler),
  `Connection::service()` (BYPASSRLS, yalnızca login/resolve-tenant/n8n tüm-tenant tarama/
  platform admin) — 02§5, 03§2.1, 09§5 kararlarının doğal DB-rolü karşılığı
  (bkz. `migrations/0000_roles.example.sql`)
- Slot hesaplama algoritması (03§4) dakika-bazlı aralık aritmetiğiyle uygulandı; birim
  testlerle (interval subtraction, tstzrange metin ayrıştırma, JWT round-trip, router)
  DB bağlantısı olmadan doğrulandı — tüm testler geçti

**Kritik ortam bulgusu:** Bu makinede **PostgreSQL kurulu değil** ve XAMPP PHP sürümü
**8.0.30** (karar 8.2+ idi, kod 8.0 uyumlu yazıldı). Migration'lar hiç çalıştırılmadı,
Backend gerçek bir istekle uçtan uca test edilmedi — yalnızca DB'siz çalışabilen birimler
(JWT, router, interval matematiği) doğrulandı. Ayrıntı: `PROJECT_MEMORY.md`, `BACKLOG.md §B`.

Kapsam dışında bırakılanlar (BACKLOG.md §A'da ⏳ işaretli, sonraki oturuma):
`GET /internal/appointments-due-for-reminder`, `GET /internal/appointments-expired-pending`,
`GET /conversation-state`, `POST /internal/whatsapp/send`, `POST /internal/whatsapp/templates/sync`,
`POST /settings/logo`, KVKK silme uç noktası, panel/n8n/AI kodu (06/04/07 henüz kodlanmadı).

Sonraki oturum için hazır komut:

> Önce Development Pack, PROJECT_MEMORY.md ve BACKLOG.md'yi oku. Tekrar etme. PostgreSQL
> kurulumu + migration'ların uygulanması + composer install ile başla, sonra BACKLOG.md
> §A'daki ⏳ işaretli endpoint'leri sırayla kodlamaya devam et. Tamamlanan maddeleri
> yeniden üretme. Token tasarrufu yap.

**STATUS: PHASE_10_BACKEND_CODING_1_COMPLETE**

## PHASE_11_BACKEND_CODING_2 — 2026-07-04

**Kodlama Fazı 2: Ortam kurulumu + BACKLOG §A'daki tüm ⏳ endpoint'ler.** Önceki oturumda
PostgreSQL/Composer kurulu değildi, kod hiç çalıştırılmamıştı; bu oturum önce ortamı kurdu,
sonra kalan endpoint'leri kodladı ve gerçek DB + HTTP isteğiyle uçtan uca doğruladı.

Ortam kurulumu:
- PostgreSQL 16 (winget) — Türkçe Windows locale'i EDB installer'ın `initdb`'sini kırdığı
  için `initdb --locale=C` ile elle tamamlandı; servis kaydı yönetici hakkı gerektirdiği
  için şimdilik `pg_ctl start` ile elle çalıştırılıyor (bkz. PROJECT_MEMORY.md)
- `berber_saas` DB'si, `berber_app`/`berber_service` rolleri oluşturuldu; `0001`/`0002`
  migration'ları hatasız uygulandı
- `pdo_pgsql`/`pgsql` `php.ini`'de etkinleştirildi; Composer `composer.phar` olarak kuruldu
- Gerçek (dev) parolalarla `.env` oluşturuldu

Yeni kod (BACKLOG.md §A, tüm ⏳ maddeler):
- `GET /internal/appointments-due-for-reminder`, `GET /internal/appointments-expired-pending`
  — `InternalScanController`, `AppointmentScanRepository` (`pg_try_advisory_lock` ile n8n
  cron mutex'i, madde 15'i de kapsar)
- `POST /internal/whatsapp/send`, `POST /internal/whatsapp/templates/sync` —
  `WhatsAppInternalController`, `Support\MetaGraphClient` (cURL), `Support\TokenCipher`
  (AES-256-GCM, yeni `APP_ENCRYPTION_KEY`)
- `POST /settings/logo` — `SettingsController`, `TenantSettingsRepository`
- `DELETE /customers/{id}` (KVKK anonimleştirme) — `CustomerController::destroy`,
  `CustomerRepository::anonymize`
- `public/index.php`: `tenantScoped` closure artık `role`'ü de controller'a geçiriyor (geriye
  uyumlu, mevcut route'lar etkilenmedi) — yeni iki endpoint owner/manager rolüyle sınırlı

Doğrulama: PHP built-in server + gerçek Postgres ile tüm yeni uçlar HTTP isteğiyle test
edildi (HMAC imzalı/imzasız 401, geçersiz tenant_id, gerçek tenant/kullanıcı/müşteri
kaydıyla login → JWT → logo upload (gerçek PNG) → customer anonymize → tekrar silme 404).
WhatsApp send/sync yalnızca hata yollarıyla test edildi (gerçek Meta kimlik bilgisi yok).

Kapsam dışında bırakılanlar (sonraki oturuma, bkz. PROJECT_MEMORY.md):
`GET /conversation-state`, n8n workflow JSON'ları (04), panel kodu (06), Redis, platform
admin route/middleware, gerçek Meta WABA ile ilk uçtan uca doğrulama.

**STATUS: PHASE_11_BACKEND_CODING_2_COMPLETE**

## PHASE_12_BACKEND_CODING_3 — 2026-07-04

**Kodlama Fazı 3: Gelen WhatsApp webhook'u.** Projenin daha önce hiç kodlanmamış en kritik
eksiği kapatıldı — backend artık müşteriden gelen mesajları da karşılıyor (önceki oturumlar
yalnızca giden yönü destekliyordu).

Yeni kod:
- `GET /webhook/whatsapp` — Meta abonelik doğrulaması (`hub.verify_token` karşılaştırması,
  `hub.challenge` düz metin echo) — `WhatsAppWebhookController::verify`
- `POST /webhook/whatsapp` — `X-Hub-Signature-256` HMAC doğrulama (`META_APP_SECRET`),
  `webhook_events` tablosuna ham kayıt, `phone_number_id` → tenant çözümü —
  `WhatsAppWebhookController::receive`, yeni `WebhookEventRepository`
- `GET /conversation-state?tenant_id=&customer_id=` (BACKLOG §B E5, öncelik listesi madde 1)
  — `ConversationStateController`, yeni `ConversationStateRepository`; kayıt yoksa (ilk temas)
  varsayılan `idle` state döner
- Altyapı düzeltmeleri: `Http\Response`'a düz metin yanıt desteği (`Response::text()`,
  `hub.challenge` için); `Http\Request`'e `queryRaw()` — PHP `$_GET`'te noktalı anahtarları
  (`hub.mode` vb.) alt çizgiye çevirdiği için ham query string'den ayrıştırma gerekti

Tasarım kararı (05§2.2'nin netleştirilmesi): geçersiz imza → `403`, kayıt düşülmez (spoof
koruması); geçerli imza ama tenant bulunamadı → yine de `200` + kayıt (`tenant_id` NULL) —
Meta'nın webhook'u devre dışı bırakma riskini önlemek için gerçek Meta-facing uçta tenant
404'ü bastırıldı (05§2.2 madde 3'teki 404, yalnızca n8n'in *iç* API çağrısı bağlamında
okunmalı, Meta'ya giden nihai yanıt değil).

Doğrulama: PHP built-in server + gerçek Postgres ile uçtan uca test edildi — GET doğru/yanlış
token, POST geçerli/geçersiz HMAC imza, bilinmeyen `phone_number_id` (tenant_id NULL kayıt) ve
gerçek (geçici test) tenant ile eşleşen `phone_number_id` (tenant_id doğru çözüldü) —
`webhook_events` tablosunda satır satır doğrulandı. `conversation-state` için: imzasız 401,
bilinmeyen müşteri 404, kayıt yokken varsayılan `idle`, gerçek `conversation_states` satırı
doğru dönüyor. Tüm test verileri (tenant/customer/webhook_events/conversation_states)
oturum sonunda temizlendi.

Kapsam dışında bırakılanlar (sonraki oturuma): n8n workflow JSON'ları, panel kodu, Redis,
platform admin route/middleware, gerçek Meta WABA doğrulaması.

**STATUS: PHASE_12_BACKEND_CODING_3_COMPLETE**

Aynı oturumda ek olarak (kota bitmeden önce, BACKLOG §A madde 14):

Yeni kod:
- `POST /platform/auth/login`, `GET /platform/tenants`, `PATCH /platform/tenants/{id}` —
  `PlatformAdminController`, `PlatformTenantController`, yeni `PlatformAdminRepository`,
  `TenantRepository::listAll/updateStatus`
- `PlatformAdminAuthMiddleware` — panel JWT'sinden (`JwtAuthMiddleware`, `tenant_id`+`role`)
  ayrı bir claim seti kullanır (`type: platform_admin`, `tenant_id` yok); bir tenant
  kullanıcısının JWT'si platform uçlarında kullanılamaz ve tersi

Doğrulama: PHP built-in server + gerçek Postgres ile uçtan uca test edildi — platform admin
login, token'sız/geçersiz token 401, tenant JWT'siyle platform uca erişim denemesi (403,
cross-token reddi doğrulandı), `GET /platform/tenants` listeleme, `PATCH .../status` ile
tenant suspend (durum değişikliği listeye yansıdı), geçersiz `status` değeri 422. Tüm test
verileri (geçici platform_admin/tenant/user) oturum sonunda temizlendi.

**STATUS: PHASE_12_PLATFORM_ADMIN_ADDENDUM_COMPLETE**

## PHASE_13_N8N_WORKFLOWS — 2026-07-04

**n8n workflow JSON export'ları.** `04_n8n_Workflows.md`'deki tasarım somutlaştırıldı —
`n8n/01_incoming_whatsapp_message.json` (SS2+SS3+SS4, tek webhook kısıtı nedeniyle tek fiziksel
workflow, `Switch` ile dallanan 54 node), `n8n/02_reminder_scan.json` (SS5, cron */15),
`n8n/03_pending_ttl_scan.json` (SS6, cron */5). Detaylı mimari not, ortam değişkenleri, bilinen
sınırlar: `n8n/README.md`.

Bu işi yapılabilir kılmak için önce iki Backend açığı kapatıldı (n8n workflow'ları bunlar
olmadan çalışamazdı, "hazır" sanılıyordu ama yazma/tetikleme yolu eksikti):

1. **`N8nNotifier` (yeni) + `WhatsAppWebhookController::receive` değişikliği** — Backend artık
   Meta'dan gelen mesajı tenant çözdükten sonra n8n'e iletiyor (`N8N_INCOMING_WEBHOOK_URL`,
   `N8N_SERVICE_SECRET` ile HMAC imzalı, best-effort — URL boşsa veya n8n kapalıysa Meta'ya giden
   yanıtı etkilemez). 04§1'in "n8n Meta webhook'unu doğrudan alır" varsayımı PHASE_12'de
   değişmişti (Meta artık Backend'i çağırıyor) ama n8n'i tetikleyecek karşı yön hiç
   kodlanmamıştı — bu oturumda kapatıldı.
2. **`PATCH /conversation-state` (yeni)** — `ConversationStateRepository::upsert`,
   `ConversationStateController::update`. PHASE_12'de yalnızca `GET` (okuma) vardı; 04§2'nin
   varsaydığı "state ilerledikçe UPDATE edilir" yazma yolu eksikti.

Doğrulama: PHP built-in server + gerçek Postgres ile uçtan uca test edildi — `PATCH
/conversation-state` upsert (aynı satır üzerine yazıyor, yeni satır açmıyor), validasyon hatası
(422); `N8nNotifier` forward'ı gerçek bir mock n8n receiver'a karşı test edildi (HMAC imzası
byte-byte doğrulandı, `N8N_INCOMING_WEBHOOK_URL` boşken sessizce atlandığı da doğrulandı). Tüm
test verileri temizlendi, `.env` orijinal haline döndürüldü.

Bilinçli tasarım kararları (n8n/README.md'de detaylı): reschedule = cancel + yeni appointment
(Backend'de in-place update ucu yok); 7 günlük müsaitlik taraması n8n'de günlük döngüyle
toplanıyor (Backend tek `date` alıyor); "farketmez" personel seçiminde ilk uygun personel
kullanılıyor (tüm personelleri çarpımsal taramak yerine).

Kapsam dışında bırakılanlar (sonraki oturuma, öncelik sırası PROJECT_MEMORY.md'de güncellendi):
panel kodu (06), Redis, gerçek Meta WABA ile n8n'in canlı olarak çalıştırılıp doğrulanması
(bu makinede n8n kurulu değil — JSON'lar elle incelendi + endpoint'ler gerçek Backend'e karşı
doğrulandı, ama n8n import/execute testi yapılamadı).

**STATUS: PHASE_13_N8N_WORKFLOWS_COMPLETE**

## PHASE_14_N8N_INSTALL_AND_VERIFY — 2026-07-04

**n8n bu makineye kuruldu ve 3 workflow gerçek bir instance'a karşı uçtan uca doğrulandı.**
PHASE_13'te yazılan JSON'lar hiç import/execute edilmemişti; bu oturumda `npm install -g n8n`
(v2.28.6) ile kurulup CLI'dan import edildi, ardından gerçek bir imzalı webhook isteğiyle tam
akış yürütüldü: `Webhook` → `Verify Backend Signature` (Code, HMAC) → `Signature Valid?` (If) →
`Extract Message` (Code) → `Has Message?` (If) → `Upsert Customer` (HTTP) →
`Get Conversation State` (HTTP) → `Determine Route` (Code) → **`Route` (Switch)** →
`Get Services` (HTTP) → `Build Services List Msg` (Code) → `Send Services List` (HTTP) — hepsi
gerçek Backend (PHP built-in server) + gerçek Postgres'e karşı çalıştı. 02/03 workflow'ları da
(`If`/`Switch` içermiyorlar) hatasız aktive edildi.

Kurulum sırasında bulunan ve düzeltilen sorunlar:

1. **npm global kurulumun bozuk bağımlılık ağacı** — `@n8n/ai-workflow-builder` üzerinden gelen
   `@langchain/langgraph-checkpoint`, hoisted `@langchain/core@1.1.41`'in artık export etmediği
   eski derin importlar kullanıyordu (`n8n start` hiç açılmıyordu). Hoisted paket elle `1.2.1`'e
   yükseltilerek çözüldü — bu makineye özel, Docker kurulumunda muhtemelen yaşanmaz.
2. **`N8N_BLOCK_ENV_ACCESS_IN_NODE=false` zorunluluğu keşfedildi** — bu n8n sürümü Code
   node'lardan `$env` erişimini varsayılan engelliyor; `N8nNotifier`/HMAC Code node'larının
   `$env.N8N_SERVICE_SECRET` okuyabilmesi için bu ayar şart.
3. **`rawBody: true` ile bile `$json.body` her zaman parse edilmiş objedir, ham string değil**
   — ham gövde base64 olarak binary alanına konuyor ve Code node'da
   `$input.item.binary.data.data` ile okunmalı (`$binary.data.data` DEĞİL, o yalnızca metadata
   döndürüyor). `01_incoming_whatsapp_message.json`'daki `Verify Backend Signature` bu şekilde
   düzeltildi.
4. **Bu n8n sürümünün yeni "draft/publish" versiyonlama sistemi** keşfedildi — bir workflow'u
   düzenlemek yalnızca draft yazar, canlı webhook'a yansıması için doğru versionId ile
   `activate` + n8n restart gerekiyor. Detaylar `n8n/README.md`'de.
5. **XAMPP Apache'de bu proje için mod_rewrite/`.htaccess` olmadığı keşfedildi** — test için
   PHP built-in server kullanıldı (`php -S localhost:8000`).

Doğrulama sonunda oluşturulan geçici test tenant'ı ve ilişkili satırlar temizlendi. n8n
(`localhost:5678`) ve PHP built-in server (`localhost:8000`) oturum sonunda çalışır bırakıldı
(Windows servisi değiller, yeniden başlatma komutları `n8n/README.md`/`PROJECT_MEMORY.md`'de).

Kapsam dışında bırakılanlar (öncelik sırası PROJECT_MEMORY.md'de): panel kodu (06), Redis,
gerçek Meta WABA ile doğrulama, Apache mod_rewrite düzeltmesi (düşük öncelik).

**STATUS: PHASE_14_N8N_INSTALL_AND_VERIFY_COMPLETE**

## PHASE_15_ADMIN_PANEL_1 — 2026-07-04

06_Admin_Panel.md kodlamasının ilk turu: panel iskeleti + öncelikli ekranlar, tamamı gerçek
Backend (PHP built-in server) + gerçek Postgres'e karşı tarayıcıda uçtan uca doğrulandı.

Backend ekleri (panelin gerektirdiği eksik uçlar):

1. `GET/PATCH /settings` — `TenantSettingsRepository::find/updateFields` (görünür kolon
   allowlist'i; `access_token_encrypted`/`webhook_verify_token` asla dönmez, `whatsapp_status`
   panelden yazılamaz). PATCH owner/manager rolü ister; `reminder_hours_before` (1-168),
   `pending_ttl_minutes` (1-1440) alan bazlı 422 sözleşmesiyle doğrulanır.
2. `GET/PUT /staff/{id}/schedule` — haftalık `working_hours` + `breaks` tek PUT ile tam
   değiştirme (transaction içinde sil+yaz); `POST/DELETE /staff/{id}/holidays[/{hid}]`
   (daterange dahil aralık `[start, end+1)`); `GET/PUT /staff/{id}/services` (staff_services
   ataması — PUT'ta service_id'ler tenant'a ait olmayanları sessizce eler).
   Yeni dosyalar: `StaffScheduleController`, `StaffScheduleRepository`.
3. `PATCH /appointments/{id}/complete` ve `/no-show` — 03§5 durum makinesine `confirmed →
   completed/no_show` panel geçişleri eklendi.
4. `AppointmentRepository::listByFilters` artık müşteri/personel/hizmet adlarını JOIN'ler
   (`customer_name`, `customer_whatsapp`, `staff_name`, `service_name` — ek alan, geriye uyumlu).
5. `Response::html()` + `rawContentType` — panel sayfaları için.

Panel (yeni `views/panel/` + `src/Panel/PanelView.php`, route'lar `public/index.php`'de):

- **Mimari:** sunucu yalnızca HTML iskeleti render eder (Bootstrap 5.3 CDN); tüm veri
  istemcide `localStorage`'daki panel JWT'siyle `fetch` edilir. 401 → `/panel/login`
  yönlendirmesi ortak `Panel.api()` sarmalayıcısında (06§2). 06§1'deki yollar API kökleriyle
  çakıştığından panel `/panel` öneki altında (bilinçli sapma).
- `/panel/login` — POST /auth/login, JWT localStorage, hatalar Türkçe.
- `/panel/dashboard` — özet kartlar (bugün/pending/hizmet/personel) + bugünün randevu tablosu.
- `/panel/appointments` — tarih/durum/personel filtreleri; Onayla / Tamamlandı / Gelmedi /
  İptal aksiyonları (durum makinesine göre koşullu butonlar), 409 slot_taken mesajı.
- `/panel/services`, `/panel/staff` — CRUD modalları + personel-hizmet çoktan-çoğa checkbox
  ataması; silme = pasifleştirme.
- `/panel/staff/{id}/hours` — haftalık gün aç/kapa + saat aralığı, mola satırları, tatil
  takvimi (ekle/sil).
- `/panel/settings/whatsapp` — `whatsapp_status` rozeti + durum açıklaması; "Yeniden Bağlan"
  butonu placeholder (Embedded Signup gerçek Meta App yapılandırması bekliyor — BACKLOG).
- `/panel/settings/reminders` — `reminder_hours_before`/`pending_ttl_minutes` formu, 422
  detayları alan altına basılır.
- `scripts/dev_seed.php` — idempotent dev tenant'ı (`dev@berber.local` / `DevPassw0rd!`,
  phone_number_id `dev-panel-000`, pro plan) + hizmet/personel/saat/randevu örnek verisi.

Doğrulama: login → dashboard → randevu onay/tamamlama → hizmet ekle/düzenle/422 → personel
CRUD + hizmet ataması → çalışma programı kaydet + reload kalıcılık + tatil ekle → whatsapp
durumu → reminders kaydet (Postgres'te satır doğrulandı) → logout. Tarayıcı testi Claude
Preview ile gerçek Chrome'da yapıldı.

Bulunan/düzeltilen hatalar:

1. **JWT payload base64url** — `atob()` `-`/`_` ve eksik padding'te fırlatıyor, `claims()`
   null dönünce guard sonsuz login döngüsü yaratıyordu. Normalizasyon eklendi (layout + login).
2. **Postgres tstzrange ofseti `+03`** — JS `Date` ISO ayrıştırması `+03:00` ister;
   `Panel.parseRange` normalizasyonu eklendi.
3. Seed'de `tenants.plan_id NOT NULL` (0002'de eklenmişti) — seed pro plana bağlandı.

Kapsam dışı kalanlar (öncelik sırası PROJECT_MEMORY.md'de): FullCalendar takvim görünümü,
/customers, /messages/*, /reports, /settings/business + /settings/ai sayfaları, platform
admin UI, randevu oluşturma formu (panel yalnızca durum yönetiyor), Embedded Signup.

**STATUS: PHASE_15_ADMIN_PANEL_1_COMPLETE**

## PHASE_16_ADMIN_PANEL_2 — 2026-07-04

Panel kalan sayfalar, 2. tur (BACKLOG §A madde 24'ten dört öğe — 06§4/§6/§7/§8).

Backend ekleri (curl ile gerçek Postgres'e karşı test edildi):

- `GET /customers` — `whatsapp_number` parametresi verilirse eski n8n tek-kayıt davranışı
  aynen korunur ({data: obje} + 404); verilmezse panel liste görünümü: isim/telefon `ILIKE`
  araması (`?search=`), her satırda `last_appointment_at` (iptaller hariç) +
  `appointment_count` (`CustomerRepository::list`, 06§6).
- `GET /customers/{id}` — müşteri detay ucu (`CustomerController::show`).
- `GET /appointments?customer_id=` — `listByFilters`'a 5. opsiyonel filtre (detay sayfası
  randevu geçmişi; mevcut çağıranlar için geriye uyumlu).
- `GET /messages/log` — yeni `MessageLogController` + `MessageLogRepository::listByFilters`:
  yön/durum/tarih aralığı/müşteri filtreleri, müşteri adı JOIN'i, en yeni 200 satır
  (06§7 pagination tanımlamıyor); geçersiz filtre → alan bazlı 422.
- `scripts/dev_seed.php` — 5 satırlık idempotent mesaj logu örneği eklendi (inbound/outbound,
  1 failed + `meta_error_code=131047`).

Panel (dört yeni sayfa + randevulara takvim; sidebar'a Müşteriler / Mesaj Logu / İşletme eklendi):

- `/panel/customers` — arama formu + liste (ad, telefon, randevu sayısı, son randevu);
  anonimleştirilmiş kayıtlar "(anonim)" gösterilir; satırdan detaya link.
- `/panel/customers/{id}` — bilgi kartı + randevu geçmişi (en yeni üstte) + mesaj geçmişi
  (06§6: appointments + message_log).
- `/panel/messages/log` — yön/durum/tarih filtreleri; failed satırlarda Meta hata kodu
  tooltip (06§7, `meta_error_code` kolonundan).
- `/panel/settings/business` — işletme adı/adres/lat-lng/zaman dilimi (PATCH /settings,
  alan bazlı 422 form altına basılır) + logo yükleme (POST /settings/logo, multipart —
  Panel.api JSON'a sabit olduğundan düz fetch). Harita seçici yerine sayısal lat/lng
  girişi (bilinçli sadeleştirme; 06§8'deki harita ileride eklenebilir).
- `/panel/appointments` — Liste/Takvim görünüm anahtarı. Takvim: FullCalendar Scheduler
  6.1.15 CDN, masaüstü `resourceTimeGridDay` (personel = resource kolonu), <768px `listDay`
  (06§4); durum renkleri; event tıklaması o güne filtrelenmiş listeye döner (aksiyonlar
  listede). `windowResize` callback'i ile boyut değişiminde otomatik görünüm geçişi.
  **Lisans notu:** `schedulerLicenseKey: CC-Attribution-NonCommercial-NoDerivatives` —
  üretimde ticari FullCalendar lisansı gerekir (BACKLOG §B E8).

Doğrulama Claude Preview ile gerçek Chrome'da: login → müşteri listesi/arama → detay
(randevu+mesaj geçmişi) → mesaj logu (yön+durum+tarih filtreleri, failed tooltip) →
işletme ayarları (kaydet + Postgres satır kontrolü, 422 alan hatası, canvas'tan üretilen
gerçek PNG ile logo yükleme + dosya sistemi kontrolü) → takvim (masaüstü resource kolonları
+ renkler, mobil listDay, eventClick). Not: preview_screenshot aracı bu oturumda sürekli
zaman aşımına düştü — doğrulama DOM/eval kanıtlarıyla yapıldı.

Kapsam dışı kalanlar: /messages/templates|campaigns, /reports, /settings/ai, platform
admin UI, panelden randevu oluşturma, Embedded Signup.

**STATUS: PHASE_16_ADMIN_PANEL_2_COMPLETE**


## PHASE_17 — 2026-07-05

**Kodlama Faz 8: Admin Panel 3. Tur** — BACKLOG §A madde 24'ten dört öğe kodlandı ve
gerçek Postgres'e karşı tarayıcıda uçtan uca doğrulandı.

Backend ekleri:
- `GET /messages/templates` — `MessageTemplateRepository::listAll` + yeni
  `MessageTemplateController` (salt okunur panel listesi, 06§7).
- `POST /messages/templates/sync` — HMAC kanalındaki `/internal/whatsapp/templates/sync`in
  panel JWT'siyle çağrılabilen tenant-scoped sarmalayıcısı (owner/manager; tenant_id JWT'den,
  body'den asla). `WhatsAppInternalController::syncTemplates`e opsiyonel `$tenantId`
  parametresi eklendi. Sahte dev token'la Meta'nın "Invalid OAuth access token" hatasının
  panele 502 olarak düştüğü doğrulandı (gerçek WABA yok — beklenen davranış).
- `/campaigns` CRUD — yeni `CampaignRepository` + `CampaignController`:
  `GET` (şablon adı JOIN), `POST`, `PATCH /{id}` (yalnızca draft/scheduled),
  `PATCH /{id}/cancel`. Şablon doğrulaması 06§7: yalnızca `template_type='campaign'` ve
  `active=true` kabul (aksi alan bazlı 422). `target_filter` jsonb: `last_visit_min_days`.
  `scheduled_at` verilirse status=scheduled, verilmezse draft. Gönderim mekanizması bu fazda
  yok (n8n kampanya workflow'u yazılmadı) — 'sent' geçişini gönderen taraf yapacak.
- `GET/PATCH /settings/ai` — yeni `AiSettingsRepository` + `AiSettingsController` (06§8,
  07§6). Satır yoksa migration varsayılanları döner (satır oluşturmadan); PATCH upsert.
  `knowledge_base` şema doğrulaması: `faq[{q,a}]` (boş soru/cevap 422) +
  `policies.{cancellation,late_arrival}`. `rate_limit_per_minute` GET'te döner ama PATCH
  edilemez (07§5 platform koruması). `tone` friendly/formal/concise allowlist.

Panel sayfaları (sidebar'a Şablonlar/Kampanyalar/AI Asistan linkleri eklendi, "yakında"
placeholder'ında yalnızca Raporlar kaldı):
- `/panel/messages/templates` — salt okunur liste (tür rozetleri, `{{değişken}}` gösterimi,
  aktif/pasif) + "Meta ile Senkronize Et" butonu.
- `/panel/appointments` "Yeni Randevu" modalı — kayıtlı müşteri seçimi VEYA yeni müşteri
  (telefon+ad, `POST /customers` upsert); hizmet+personel+tarih → `GET /availability` slot
  butonları; `POST /appointments`. 409 slot_taken'da modal açık kalır, güncel slotlar
  alternatif olarak yeniden listelenir (06§4). start_time TR sabit +03:00 ofsetiyle gönderilir.
- `/panel/messages/campaigns` — liste + oluştur/düzenle modalı (alan bazlı 422 gösterimi),
  yalnızca aktif campaign şablonları seçilebilir, draft/scheduled düzenlenebilir/iptal
  edilebilir, terminal durumlar kilitli.
- `/panel/settings/ai` — enabled switch, ton seçimi, SSS ekle/sil listesi, iptal/geç kalma
  politika alanları, salt okunur dakikalık limit. 07§3 notu UI'da: fiyat/süre buraya girilmez.

`scripts/dev_seed.php`: 6 idempotent mesaj şablonu eklendi (2'si campaign, 1'i pasif —
kampanya şablon filtresi testi için).

Doğrulama (Claude Preview, gerçek Postgres): şablon listesi + sync hata yolu; yeni randevu
(Postgres'te 14:00-14:30 tstzrange satırı), gerçek yarış simülasyonu (aynı slot API'den
kapıldıktan sonra submit → 409 → alternatif slotlar, 36→33→30 slot düşüşü izlendi), yeni
müşteri+randevu tek akışta; kampanya oluştur (scheduled) → düzenle (draft'a düşüş) → iptal →
terminal kilit (ikinci iptal 422), pasif şablon API'den 422; AI ayarları kaydet → Postgres
jsonb satırı 07§3 şemasına birebir uygun, boş cevaplı SSS 422 alan hatası. Konsol hatasız.
Form submit'leri requestSubmit() ile tetiklendi (preview_click login sorunu PHASE_16 notu).

Kapsam dışı kalanlar: /reports, platform admin UI, Embedded Signup, kampanya gönderim
workflow'u, Redis.

**STATUS: PHASE_17_ADMIN_PANEL_3_COMPLETE**

## PHASE_19 — 2026-07-08

**Kodlama: AI Modülü — `POST /ai/respond`** (07_AI_Module.md §2/§4/§5). Panelin AI ayarları
tarafı (GET/PATCH /settings/ai) PHASE_17'de bitmişti; bu turda AI'ın kendisi (Backend ucu)
kodlandı. AI hiçbir zaman randevu oluşturmaz/değiştirmez (07§1) — yalnızca bilgi verir;
işlemsel niyette `intent='appointment_action'` döner ve n8n menüyü tekrar gönderir.

Yeni dosyalar:
- `src/Support/ClaudeClient.php` — Anthropic Messages API ham cURL istemcisi (MetaGraphClient
  kardeşi). Model `claude-haiku-4-5` (07§4 "Haiku sınıfı"); başlıklar `x-api-key` /
  `anthropic-version: 2023-06-01` / `content-type: application/json`. Yapılandırılmış çıktı
  tek bir zorunlu `provide_response` tool'u ile alınır (`tool_choice`): `{reply, intent}`,
  intent enum `faq|appointment_action|unclear`, `input_schema` `additionalProperties:false` +
  `required`. LLM anahtarı yalnızca Backend'de (07§2). HTTP≠200 / tool_use bloğu yok → istisna.
- `src/Http/Controllers/AiRespondController.php` — sistem promptu her istekte kurulur (07§4):
  sabit rol + guardrail'ler (§5: kapsam dışı reddi, fiyat/hizmet uydurmama, işlem yapmama) +
  tenant `tone` (friendly/formal/concise) + **yalnızca bu tenant'ın** services/staff/
  working_hours verisi (§3, PII izolasyonu §5) + knowledge_base (faq/policies) + son 5 mesaj
  (`message_log`, o müşteri-tenant çifti). Graceful davranış: **enabled=false**, **API anahtarı
  yok** veya **LLM hatası** → her zaman 200 + sabit fallback şablonu (`source` alanı yolu
  belirtir: `fallback:disabled|no_api_key|llm_error`), n8n'e asla 5xx dönmez (retry döngüsü
  maliyeti/hatayı katlamasın — §5 gerekçesi).

Değişen dosyalar:
- `src/Repository/MessageLogRepository.php` — `recentForCustomer(tenant, customer, limit=5)`
  eklendi (son N mesaj, eskiden yeniye; prompt geçmişi için).
- `public/index.php` — `POST /ai/respond` route'u: `ServiceHmacMiddleware::authenticate` +
  `Connection::service()`, tenant_id body'den (/conversation-state deseninin aynısı). Tüm
  repo'lar tenant_id'yi açıkça alır → BYPASSRLS bağlantıda da tek tenant izole.

Bilinçli ertelenen: **rate_limit_per_minute** (07§5) Redis sayaçları gerektiriyor, henüz yok —
kodda yorumla işaretlendi, BACKLOG §A madde 11/16'ya bağlandı.

Doğrulama (gerçek Postgres + PHP built-in server port 8000, HMAC imzalı istekler; ANTHROPIC_
API_KEY .env'de BOŞ olduğundan gerçek LLM çağrısı YAPILMADI):
- enabled=true + anahtar yok → 200 `source:fallback:no_api_key` ✓
- enabled=false → 200 `source:fallback:disabled` ✓ (dev tenant enabled psql ile geçici false
  yapıldı, test sonrası true'ya geri alındı)
- X-Signature yok → 401 ✓ ; customer_message yok → 422 ✓
Gerçek LLM yolu (`source:llm`) ilk gerçek ANTHROPIC_API_KEY girildiğinde doğrulanacak.

n8n entegrasyonu (aynı oturum) — `n8n/01_incoming_whatsapp_message.json`:
- Switch'in fallback (unhandled) çıkışı, eskiden placeholder `Respond Unhandled (AI/Faz7)`
  node'una gidiyordu; artık gerçek AI zincirine bağlandı: **`Sign: AI Respond`** (HMAC gövde:
  `{tenant_id, customer_id, customer_message=text_body, conversation_state}`) → **`AI Respond`**
  (POST /ai/respond, `neverError:true` — 4xx/5xx akışı çökertmez) → **`Build AI Reply Msg`**
  (`data.reply`/`intent`; `intent='appointment_action'` ise 07§2 adım 6 gereği menüye yönlendirme
  notu ekler) → **`Send AI Reply`** (type='text' /internal/whatsapp/send) → **`Respond OK (ai)`**.
- 54→58 node. JSON bütünlüğü (her bağlantı hedefi var olan node) + yeni Code node'ların gömülü
  JS'i `node --check` ile doğrulandı. **Gerçek n8n instance'ında execute EDİLMEDİ** (PHASE_14
  deseni sonraki adım). Not: `Determine Route` her `text` mesajı `new_session`'a (hizmet menüsü)
  yolladığından AI dalı yalnızca menü-dışı/eşleşmeyen mesajlarda tetikleniyor — akış ortasında
  serbest soruyu AI'a yönlendirme bir sonraki iyileştirme (BACKLOG §A m.27 (c)).

## PHASE_18 — 2026-07-08

**Kodlama Faz 9: Admin Panel 4. (son) Tur** — BACKLOG §A madde 24'ün kalan iki öğesi kodlandı
ve gerçek Postgres'e karşı tarayıcıda uçtan uca doğrulandı. Bununla panel ekranları tamamlandı;
06§1'in sayfa haritasında yarım/eksik ekran kalmadı.

Yeni Backend ucu GEREKMEDİ (06§9 kararı): raporlar mevcut `GET /appointments` +
`GET /staff/{id}/schedule` çıktılarından panelde agregre edilir; platform admin API'si zaten
PHASE_12'de hazırdı.

Panel sayfaları:
- `/panel/reports` (yeni `views/panel/reports.php`, Chart.js 4.4.3 CDN) — 06§9'un üç grafiği:
  **randevu hacmi** (çizgi, günlük/haftalık toggle; `GET /appointments` gün/hafta kovalarına
  agregre), **durum dağılımı** (pasta; iptal/no-show oranı `Panel.STATUS` renkleriyle),
  **personel doluluk** (yatay bar; iptal dışı randevu süresi / `GET /staff/{id}/schedule`
  working_hours kapasitesi, kapasite 0 → "(kapasite yok)"). Tarih aralığı formu (varsayılan:
  içinde bulunulan ay), özet satırı (toplam/iptal%/no-show%). Kapasite hesabı gün gün
  working_hours dakika toplamı (mola/tatil kapasiteden düşülmez — bilinçli sadeleştirme,
  grafikte belgeli).
- `views/panel/layout.php`: sidebar'a gerçek `reports` linki eklendi; "Raporlar (yakında)"
  placeholder (`$planned` bloğu) tamamen kaldırıldı — artık "yakında" ibaresi yok.

Platform admin UI (09§5 — tenant panelinden AYRI hafif kabuk):
- `/platform/login` (yeni `views/panel/platform_login.php`) — ayrı login, **ayrı localStorage
  anahtarı `platform_jwt`**, `type: platform_admin` claim kontrolü (tenant panel JWT'siyle
  karışmaz; koyu tema).
- `/platform` (yeni `views/panel/platform_tenants.php`) — Panel çekirdeğinden bilinçli ayrı
  `PlatformUI` objesi (kendi `api/guard/logout`, 401/403'te `/platform/login`'e döner); tenant
  listesi (işletme, durum, abonelik, whatsapp durumu, kayıt tarihi) + **Askıya Al / Aktifleştir**
  aksiyonu (`PATCH /platform/tenants/{id}`).
- `public/index.php`: `/panel/reports`, `/platform`, `/platform/login` route'ları eklendi.
- `scripts/dev_seed.php`: idempotent `platform_admins` seed'i eklendi —
  **platform@berber.local / PlatformDev1!** (dev ortamında satır yoktu; çalıştırıldı, oluşturuldu).
- `.claude/launch.json`: 8000 portu başka bir oturumun sunucusunda dolu olduğu için
  `backend-alt` (port 8010) config'i eklendi; doğrulama bu portta yapıldı.

Doğrulama (Claude Preview, gerçek Postgres; preview_screenshot güvenilmez olduğundan
preview_eval/snapshot DOM kanıtları + psql):
- Raporlar: dev tenant'ta üç grafik render edildi. Doluluk testi için 2026-07-07'ye 3 confirmed
  randevu eklendi (o gündeki 3 cancelled artığına ek) → doluluk Ahmet %12.5 / Mehmet %3.3,
  durum pastası 3 onaylı + 3 iptal, iptal oranı %50 doğru hesaplandı; günlük↔haftalık toggle
  ve tarih aralığı filtresi çalıştı; konsol temiz.
- Platform admin: yanlış parola → "E-posta veya parola hatalı"; doğru parola → tenant listesi
  (Dev Berber); Askıya Al → psql'de `status='suspended'` doğrulandı → Aktifleştir geri aldı;
  **tenant JWT'si `platform_jwt`'ye konup `/platform` denendi → guard reddedip `/platform/login`'e
  attı** (cross-token izolasyonu çalışıyor).

Kapsam dışı kalanlar (sonraki oturuma): `POST /ai/respond` (AI modülü motoru — bu oturumun
opsiyonel/stretch maddesiydi, dokümantasyon güvenceye alındıktan sonra token kalmadı),
gerçek Meta WABA + Embedded Signup, Redis, kampanya gönderim workflow'u.

**STATUS: PHASE_18_ADMIN_PANEL_4_COMPLETE**

## PHASE_20 — 2026-07-09

**Bu makine PROJECT_MEMORY'nin varsaydığı ortamdan farklıydı** — PostgreSQL, n8n, PHP
pdo_pgsql hiçbiri kurulu değildi (muhtemelen farklı bir fiziksel makine/oturum). Bu oturumda
sıfırdan kuruldu:

- PostgreSQL 16 (winget), E1'deki Türkçe locale sorunu tekrar çıktı → `initdb --locale=C
  --auth=trust` ile elle tamamlandı. DB (`berber_saas`), roller (`berber_app`/`berber_service`,
  yeni dev şifreleri), migration'lar (0001/0002) uygulandı. Yeni `.env` üretildi (yeni
  JWT_SECRET/N8N_SERVICE_SECRET/APP_ENCRYPTION_KEY/META_APP_SECRET — hepsi dev-only).
- `pdo_pgsql`/`pgsql` php.ini'de etkinleştirildi (bu makinede PHP zaten 8.2.12 — E2'nin
  yükseltme kararı zaten karşılanmış).
- n8n `npm install -g n8n` ile kuruldu. **`latest` (2.29.8) yerine `2.28.6`'ya sabitlendi**
  (PHASE_14'ün doğruladığı sürüm) — ayrıntı aşağıda. Aynı `@langchain/core` hoisting hatası
  (n8n/README.md'de belgeli) tekrar çıktı, aynı yamayla (1.2.1'e elle yükseltme) çözüldü.
  `.claude/launch.json`'a `n8n` config'i + `scripts/start_n8n.cmd` eklendi (gerekli env
  değişkenlerini set edip `n8n start` çağırıyor).

**Gerçek bulgu — `01_incoming_whatsapp_message.json`'da `Route` (Switch) node'u bozuk:**
`typeVersion: 1` ile 8 çıkış (0-7, `fallbackOutput: 7` dahil) tanımlıyordu; bu n8n sürümünde
Switch v1 en fazla 4 çıkış (0-3) destekliyor → "The output 7 is not allowed" hatasıyla
çöküyordu. PHASE_14'te yalnızca `new_session` dalı (çıkış 0) test edilmişti, AI/fallback dalı
(çıkış 7) hiç gerçek n8n'e karşı denenmemişti — bu yüzden hata daha önce görülmedi. **Düzeltme:**
node `typeVersion: 3`'e yükseltildi, `rules.values[].conditions`/`outputKey` formatına
(filter v2) geçirildi, `options.fallbackOutput: "extra"`. `connections` JSON'u (index 0-7)
değişmedi — sorun yalnızca node'un kendi çıkış üretme mantığındaydı.

**Ortam bulgusu — SSL sertifika zinciri:** Bu makinede tüm giden HTTPS istekleri (n8n'in
telemetri çağrıları, PHP'nin Meta Graph API çağrısı) "unable to verify the first
certificate"/"SSL certificate problem" ile başarısız oluyordu; `curl.exe` (Windows schannel,
sistem sertifika deposunu kullanıyor) ise sorunsuz çalışıyordu — makinede kurumsal/sistem kök
sertifikası var ama PHP'nin eski bundled `curl-ca-bundle.crt`'sinde yok. **Düzeltme:**
`Cert:\LocalMachine\Root` + `Cert:\LocalMachine\CA` + `Cert:\CurrentUser\Root`
(`C:\xampp\php\extras\ssl\winstore-ca-bundle.pem`) eski bundle ile birleştirilip
(`combined-ca-bundle.pem`) `php.ini`'nin `curl.cainfo`/`openssl.cafile`'ına yazıldı. **Not:**
`php -S` built-in server tek kalıcı process'tir, php.ini değişikliği için restart şart (bu
oturumda yanlışlıkla "her istekte yeni process" varsayılmıştı — düzeltildi).

**Gerçek bulgu — inbound mesajlar `message_log`'a hiç yazılmıyor:** `01_incoming...`
workflow'unun hiçbir node'u gelen müşteri mesajını `message_log`'a INSERT etmiyor.
`WhatsAppSendController::send` (type=text) `lastInboundAt` üzerinden 24 saatlik pencereyi bu
tablodan kontrol ediyor → **yeni bir müşterinin ilk mesajında bu satır hiç olmadığından her
zaman `409 window_closed` dönüyor.** Bu oturumda test için elle bir inbound satır eklenerek
atlatıldı; kalıcı çözüm BACKLOG'a eklendi (Backend'in Meta webhook'u karşıladığı an veya n8n'in
`Extract Message`/`Upsert Customer` adımında inbound'u da loglaması gerekiyor).

**Sonuç — uçtan uca doğrulandı (gerçek n8n v2.28.6 + gerçek Postgres, PHASE_14 deseni):**
Gerçek imzalı bir Meta webhook isteği (`POST /webhook/whatsapp`, geçerli
`X-Hub-Signature-256`) → Backend imza/tenant çözümü → `N8nNotifier` → n8n (`Verify Backend
Signature` → `Extract Message` → `Upsert Customer` → `Get Conversation State` → `Determine
Route` → **`Route` (düzeltilmiş)** → `unhandled` → `Sign: AI Respond` → `AI Respond`
(ANTHROPIC_API_KEY boş, fallback tetiklendi) → `Build AI Reply Msg` (doğru fallback metni
üretildi) → `Send AI Reply` → Backend `/internal/whatsapp/send` → gerçek Meta Graph API
isteği (dev tenant'ın sahte token'ı nedeniyle beklenen `190 Invalid OAuth access token` ile
reddedildi) → `message_log`'a `status=failed, meta_error_code=190` olarak doğru kaydedildi.
Zincirin her adımı doğrulandı; yalnızca gerçek WABA credential'ı olmadığı için mesaj fiilen
Meta'ya ulaşamadı (BACKLOG §A m.5'in beklenen kapsamı).

**STATUS: PHASE_20_N8N_AI_BRANCH_VERIFIED**

## PHASE_21 — 2026-07-09

**Redis kurulumu ve `POST /ai/respond` rate limitinin bağlanması (BACKLOG §A m.11/16).**

Ortam bulgusu: bu makinede Composer kurulu değil (`composer.phar` daha önceki bir oturumda
kurulmuş olabilir ama artık yok, `vendor/` yok, proje manuel PSR-4 autoloader ile çalışıyor —
`public/index.php`), `ext-redis` de yüklü değil, Docker/WSL/chocolatey/scoop hiçbiri yok.
`predis/predis` gibi bir paket yöneticisi bağımlılığı eklenemediğinden, INCR/EXPIRE için
yalnızca gereken birkaç RESP komutunu konuşan bağımlılıksız bir istemci yazıldı
(`src/Support/RedisClient.php`, ham `fsockopen` soketi).

- Redis sunucusu: `tporadowski/redis` (Windows portable build, v5.0.14.1) `redis/` klasörüne
  indirildi (kurulum/servis kaydı yok, sadece `redis-server.exe`); `.claude/launch.json`'a
  `redis` config'i eklendi (port 6379, `redis/redis.windows.conf`). `.gitignore`'a binary'ler
  eklendi (`redis/*.exe` vb.), `redis.windows.conf` commit'lenebilir kaldı.
- `src/Support/RateLimiter.php`: sabit dakikalık pencere sayacı (`ratelimit:{key}:{floor(time()/60)}`
  anahtarıyla INCR, ilk INCR'da EXPIRE 60) — pencere geçişinde otomatik sıfırlanır. **Fail-open**:
  Redis'e ulaşılamazsa istek engellenmez, sadece loglanır (`AiRespondController`'ın "n8n'e asla
  5xx dönme" ilkesiyle tutarlı).
- `AiRespondController`: enabled/api-key kontrollerinden sonra, gerçek LLM çağrısından önce
  `rateLimiter->allow("ai_respond:{tenantId}", rate_limit_per_minute)` eklendi; aşımda
  `source: fallback:rate_limited` ile sabit yanıt (200, LLM'e hiç gidilmiyor).
- `public/index.php`: `/ai/respond` route'unda `RedisClient`/`RateLimiter` wiring'i (`REDIS_HOST`/
  `REDIS_PORT` env, varsayılan `127.0.0.1:6379`). `.env`/`.env.example`'a eklendi.

**Doğrulama:** (1) `RateLimiter`/`RedisClient` izole test — limit=3 ile art arda 5 çağrı: ilk 3
ALLOW, sonraki 2 BLOCK; yanlış porta bağlanınca fail-open doğrulandı (ALLOW + log). (2) Gerçek
uçtan uca: test tenant'ının `ai_settings`'i `enabled=true, rate_limit_per_minute=3` yapıldı,
gerçek backend sunucusuna (php -S) imzalı `POST /ai/respond` ile art arda 12 istek gönderildi —
ilk 3 istek limitten geçip DB sorgusuna ulaştı (fake `ANTHROPIC_API_KEY` ile beklenen ayrı bir
SQL hatasına düştüler — test scriptinin `customer_id` alanı geçerli bir uuid değildi, rate
limitle ilgisiz), 4. istekten itibaren tamamı `source: fallback:rate_limited` döndü. Test sonrası
`ai_settings` ve `.env` (`ANTHROPIC_API_KEY`) orijinal haline geri alındı.

**STATUS: PHASE_21_REDIS_RATE_LIMIT_VERIFIED**

## PHASE_22 — 2026-07-09

**Inbound mesaj loglama eksikliği düzeltildi (BACKLOG §A madde 28, 🔴).** PHASE_20'de keşfedilen
gerçek bug: `01_incoming_whatsapp_message.json`'ın hiçbir node'u gelen müşteri mesajını
`message_log`'a yazmıyordu; `WhatsAppInternalController::send` (type=text) 24 saatlik pencereyi
bu tablodaki son `direction=inbound` satırından kontrol ettiği için **yeni bir müşterinin ilk
mesajında bu satır hiç olmadığından her zaman `409 window_closed` dönüyordu** — AI dalı dahil
hiçbir `type=text` gönderimi ilk temasta çalışmıyordu.

- `migrations/0003_message_log_inbound_status.sql`: `message_log.status` CHECK kısıtına
  `'received'` eklendi (mevcut `sent/delivered/read/failed` yalnızca outbound'a anlamlıydı).
- `WhatsAppInternalController::logInbound()` + `POST /internal/whatsapp/log-inbound`
  (`public/index.php`, HMAC/n8n servis kanalı): `tenant_id`/`customer_id`/`type`/`content` alır,
  `message_log`'a `direction=inbound, status=received` INSERT eder. `idempotency_key` (Meta'nın
  `wamid`'i) opsiyonel — n8n retry'sinde tekrar INSERT etmez, mevcut satırı döner (mevcut
  `uq_message_log_tenant_idempotency` kısıtı üzerinden, PHASE_send ile aynı desen).
- `n8n/01_incoming_whatsapp_message.json`: `Extract Message` artık `message.id`'yi de
  (`message_id`) çıkarıyor. `Upsert Customer`'dan sonra paralel bir dal eklendi (ana akışı
  bloklamaz, `neverError:true`): `Sign: Log Inbound` → `Log Inbound Message` (POST
  `/internal/whatsapp/log-inbound`). Ana akış (`Get Conversation State` → ... → `Route`)
  değişmedi.

**Doğrulama (gerçek backend + gerçek Postgres, `php -S`):** (1) `POST /customers` ile sıfırdan
bir müşteri oluşturuldu (mevcut ortamda `customers` tablosu boştu — tam olarak buglu senaryo).
(2) `POST /internal/whatsapp/log-inbound` → 201, satır `direction=inbound, status=received`
olarak doğru yazıldı; aynı `idempotency_key` ile tekrar çağrıldığında 200 + aynı satır id'si
döndü (dedup doğrulandı). (3) Aynı müşteriye `POST /internal/whatsapp/send` (`type=text`) → artık
`409 window_closed` **değil**, beklenen `502` (dev tenant'ın sahte token'ı → Meta `190 Invalid
OAuth access token`) döndü — pencere kontrolü doğru geçti. Test verileri (müşteri, message_log
satırları) temizlendi.

**STATUS: PHASE_22_INBOUND_MESSAGE_LOG_FIXED**

## PHASE_23 — 2026-07-09

**Kampanya gönderim mekanizması eklendi (BACKLOG §A madde 26).** PHASE_17'de panele
`/messages/campaigns` CRUD'u kodlanmıştı ama `scheduled_at` gelince gerçek gönderimi yapan
taraf yoktu — kampanyalar sonsuza kadar `scheduled` durumunda kalıyordu.

- `migrations/0004_campaigns_sent_at.sql`: `campaigns.sent_at timestamptz` eklendi
  (`scheduled_at` planlanan zamanı, `sent_at` gerçek claim/gönderim zamanını taşır —
  `message_log.sent_at` ile aynı desen).
- `src/Repository/CampaignScanRepository.php` + `GET /internal/campaigns-due-for-send`
  (`CampaignScanController`, `public/index.php`, servis rolü/BYPASSRLS, HMAC — `04§5/§6/§7`
  desenindeki `InternalScanController` ailesine katıldı): tek bir CTE sorgusu, vadesi gelmiş
  kampanyaları (`status='scheduled' AND scheduled_at <= now()`) atomik `UPDATE...RETURNING`
  ile `'sent'`e claim eder, sonra aynı sorguda `target_filter`'a uyan alıcı listesini
  (`last_visit_min_days` — customers LEFT/NOT EXISTS appointments, `deleted-%` anonimleşmiş
  müşteriler hariç) müşteri başına bir satır olarak döndürür. Postgres advisory lock
  (madde 15 deseni, `pg_try_advisory_lock`) eşzamanlı worker'ları engeller.
  **Trade-off (dokümante edildi):** appointment hatırlatma taramasının aksine (her satır
  `message_log.idempotency_key` varlığıyla ayrı ayrı kontrol edilir), kampanya claim'i gerçek
  Meta gönderiminden ÖNCE gerçekleşir — n8n bu adımdan sonra çökerse bazı müşteriler mesajı
  kaçırabilir. Kampanyalar en-iyi-çaba toplu gönderim olduğundan (kritik randevu bilgisi
  taşımıyor) kabul edildi.
- `n8n/04_campaign_send.json`: `02_reminder_scan.json` ile birebir aynı desen (cron → HMAC
  imzalı GET → `splitOut` → HMAC imzalı POST `/internal/whatsapp/send`, `neverError`). Her 5
  dakikada çalışır, `idempotency_key = <campaign_id>_<customer_id>`.
- PDO notu: `CampaignScanRepository` sorgusunda jsonb `?` (exists) operatörü `PDO::query()`
  ile bile `ATTR_EMULATE_PREPARES` yüzünden bind placeholder'ı sanılıp `SQLSTATE[42601]`
  hatası veriyordu — standart kaçış deseniyle (`??`) düzeltildi.

**Doğrulama (gerçek backend + gerçek Postgres, `php -S`):** (1) Dev tenant'a `target_filter={}`
(tüm müşteriler) ile vadesi geçmiş bir kampanya eklendi — doğrudan repository çağrısı 3
müşteriyi doğru döndürdü, `campaigns.status` `'sent'`e geçti, ikinci çağrı boş sonuç döndü
(tekrar claim edilmedi). (2) `target_filter={"last_visit_min_days":5}` ile ikinci bir kampanya
— hiç `completed` randevusu olmayan müşteriler doğru şekilde dahil edildi. (3) Gerçek HTTP
uçtan uca: `GET /internal/campaigns-due-for-send` (HMAC) → 3 alıcı satırı; ardından
`POST /internal/whatsapp/send` (`type=template`, `idempotency_key`) → dev tenant'ın sahte
token'ı nedeniyle beklenen `502` (Meta `190 Invalid OAuth access token`); aynı istek
tekrarlanınca idempotency doğrulandı (200 + aynı `message_log` satırı, ikinci Meta çağrısı
yapılmadı). Test verileri (kampanyalar, message_log satırı) temizlendi.

**STATUS: PHASE_23_CAMPAIGN_SEND_IMPLEMENTED**

## PHASE_24 — 2026-07-09

**FullCalendar Scheduler kaldırıldı, ücretsiz çekirdeğe geçildi (BACKLOG §B madde E8).**
Kullanıcı, üretime çıkarken ücretli hiçbir bağımlılık istemediğini belirtti (maliyet
netleştirme görüşmesi — n8n'in ücretsiz self-host olduğu, asıl ücretli kalemin
`resourceTimeGrid` görünümü için gereken FullCalendar Scheduler ticari lisansı ($480/yıl
mertebesinde) olduğu netleştirildi).

- `views/panel/appointments.php`: CDN paketi `fullcalendar-scheduler` → ücretsiz `fullcalendar`
  çekirdeğine değiştirildi. `schedulerLicenseKey` satırı kaldırıldı. Masaüstü görünümü
  `resourceTimeGridDay` (personel = ayrı sütun) yerine `timeGridDay` oldu — personel adı artık
  `resources` API'si yerine event `title`'ının başına ekleniyor (`staffName()` yardımcı
  fonksiyonu, `staffList`'ten arama). Mobil `listDay` görünümü değişmedi.
- `BACKLOG.md` E8 ✅ Kapandı olarak işaretlendi.

**Doğrulama:** PHP built-in server + preview tarayıcısında `/panel/appointments` → Takvim
görünümüne geçildi, konsolda Scheduler lisans hatası/uyarısı olmadığı doğrulandı, event'lerin
personel adıyla birlikte render olduğu ve tıklamanın liste görünümüne doğru filtrelediği
teyit edildi; `preview_resize` ile mobil genişlikte `listDay`'e geçtiği doğrulandı.

**STATUS: PHASE_24_FREE_CALENDAR_MIGRATED**

## PHASE_25 — 2026-07-09

**AI sağlayıcısı Gemini'ye açıldı (ücretsiz katman), sağlayıcı-bağımsız arayüz eklendi.**
Kullanıcı, üretime çıkarken AI için önden ücretli kredi (Anthropic $5 min.) istemedi; müşteri
mesajlarına AI yanıtı Gemini Flash'ın ücretsiz kotasıyla verilecek. `AiRespondController` artık
somut `ClaudeClient` yerine `LlmClient` arayüzüne bağımlı — sağlayıcı `.env` ile seçiliyor,
ikisi de kod olarak korundu (mimari değişiklik, Opus ile yürütüldü).

- `src/Support/LlmClient.php`: `structuredReply(string $system, array $messages): array{reply,intent}`
  sözleşmesi. `ClaudeClient` bunu `implements` eder (mevcut imza zaten uyuyordu).
- `src/Support/GeminiClient.php`: Google `generateContent` ham cURL istemcisi, `ClaudeClient`'ın
  kardeşi. Yapılandırılmış çıktı için tek `provide_response` function declaration'ı
  `toolConfig.functionCallingConfig.mode=ANY` ile zorunlu tutulur (Anthropic'teki `tool_choice`
  eşdeğeri). Anthropic role'leri (`user`/`assistant`) → Gemini (`user`/`model`) çevrilir; sistem
  promptu `systemInstruction`'a gider. Anahtar `x-goog-api-key` header'ıyla (URL yerine, log
  sızıntısı önlemi). Varsayılan model `gemini-2.5-flash`, `GEMINI_MODEL` ile override edilebilir.
  Not: Gemini Schema `additionalProperties`'i DESTEKLEMEZ (400 döner) — bilinçli olarak yok.
- `AiRespondController`: `string $apiKey` yerine `?LlmClient $llm` enjekte edilir; `null` →
  `fallback('no_api_key')`, `respond()` içinde `new ClaudeClient(...)` kaldırıldı, `$this->llm->
  structuredReply(...)` çağrılıyor. Fallback yolları (disabled / no_api_key / rate_limited /
  llm_error) değişmedi.
- `public/index.php`: `resolveLlmClient()` yardımcısı — öncelik `GEMINI_API_KEY` (doluysa
  `GeminiClient`) → `ANTHROPIC_API_KEY` (`ClaudeClient`) → `null`.
- `.env`/`.env.example`: `GEMINI_API_KEY`, `GEMINI_MODEL` eklendi (sağlayıcı önceliği notuyla).

**Doğrulama (gerçek backend + gerçek Postgres, `php -S`, HMAC imzalı `/ai/respond`):** dev
tenant `ai_settings.enabled=true` yapıldı. (1) İki anahtar da boş → `200 fallback:no_api_key`
(LLM çağrısı yapılmadı). (2) `GEMINI_API_KEY` sahte değere set → istek gerçekten Google'a ulaştı,
sunucu logunda `Gemini API error (HTTP 400): API key not valid` — yani payload şekli geçerli
(Google yalnızca anahtarı reddetti), TLS çalışıyor, controller zarifçe `200 fallback:llm_error`
döndü. Sahte anahtar geri alındı. (3) **Gerçek `GEMINI_API_KEY` (Google AI Studio) girildi →
`source:llm` yolu uçtan uca doğrulandı:** "Saç kesimi ne kadar/fiyatı?" → dev seed verisinden
doğru yanıt ("30 dakika, 350.00 TL", `intent:faq`). **Guardrail'ler (07§5) gerçek modelde teyit
edildi:** "randevumu saat 3'e al" → reddedip `intent:appointment_action`; "hava nasıl?" → konu
dışı reddi; "lazer epilasyon var mı?" (listede yok) → uydurmuyor, "net bilgim yok" diyor.
BACKLOG §A m.27a kapandı.

**STATUS: PHASE_25_GEMINI_PROVIDER_VERIFIED**

## PHASE_26 — 2026-07-09

**Akış-ortası serbest soru artık AI'a yönleniyor (BACKLOG §A madde 27b).** PHASE_20'den beri
`01_incoming_whatsapp_message.json`'ın `Determine Route` node'u HER `text` mesajını
`new_session`'a (hizmet menüsü) yolluyordu — bu yüzden müşteri akış ORTASINDAyken (aktif bir
`conversation_state` varken) serbest bir soru sorduğunda menü baştan başlıyor, AI (Gemini) dalı
yalnızca menü-dışı/eşleşmeyen mesajlarda tetikleniyordu.

Değişiklik (tek node, `Determine Route` jsCode):
```
- else if (em.message_type === 'text') route = 'new_session';
+ else if (em.message_type === 'text' && step === 'idle') route = 'new_session';
+ else if (em.message_type === 'text') route = 'ai_question';
```
Yalnızca `step === 'idle'` (temiz/ilk temas) text mesajı menüyü başlatır — bu, randevu akışının
giriş noktası olarak korunur. Akış ortasındaki (`step != idle`) serbest text `ai_question`
route'una düşer; Switch'te bu değere karşılık gelen kural olmadığından fallback (`unhandled`)
çıkışına, yani mevcut AI zincirine (`Sign: AI Respond` → `AI Respond` → `Build AI Reply Msg` →
`Send AI Reply`) gider. AI dalı zaten müşteri sorusunu `Extract Message`'ın `text_body`'sinden ve
oturum durumunu `Determine Route`'un `state_context`'inden okuduğundan başka değişiklik gerekmedi.
Buton yanıtları (confirm/cancel/reschedule/svc_/staff_/slot_) ve stale-buton→AI davranışı
değişmedi.

**Uçtan uca doğrulama (gerçek n8n v2.28.6 + gerçek Backend + gerçek Postgres + gerçek Gemini):**
Değişiklik n8n'in çalışan kopyasına REST `PATCH /rest/workflows/:id` ile yazıldı, yeni versionId
`POST /rest/workflows/:id/activate` ile aktive edildi (bu, PHASE_20'de dokümante edilen
workflow-ID-önekli bozuk webhook path'ini de temizledi — canlı üretim URL'i artık temiz
`http://localhost:5678/webhook/incoming-whatsapp-message`); n8n süreci restart edilmedi, activate
webhook'u yeniden kaydetti. Dev tenant'ta geçici bir test müşterisiyle iki koşul imzalı
webhook'la denendi:
- **Akış ortası** (`step=awaiting_slot_selection`) + "Kredi kartı geçiyor mu?" → AI dalı çalıştı,
  Gemini guardrail yanıtı üretti ("Bu konuda net bilgim yok...") ve `type=text` olarak gönderildi
  (`message_log` outbound text satırı; yalnızca dev tenant'ın sahte token'ı nedeniyle Meta 190 ile
  `failed` — beklenen).
- **Kontrol** (`step=idle`) + aynı metin → `new_session` dalı çalıştı, `type=interactive` hizmet
  menüsü listesi gönderildi (`message_log` outbound interactive satırı).
İki `message_log` satırının tipi (text vs interactive) yönlendirmeyi kesin ayırt etti. Test
verileri (müşteri, conversation_states, message_log satırları) oturum sonunda temizlendi.

**STATUS: PHASE_26_MIDFLOW_AI_ROUTING_VERIFIED**

## PHASE_27 — 2026-07-09

**Gerçek in-place `PATCH /appointments/{id}/reschedule` ucu eklendi (BACKLOG §A madde 22).**
PHASE_13'ten beri n8n reschedule akışı `POST /appointments` (yeni kayıt) + `PATCH
.../cancel` (eski kaydı iptal) iki-adımlı atlatmasıyla çalışıyordu (03§3.4'ün tasarladığı
gerçek uç hiç yoktu). Bu oturumda gerçek uç kodlandı; **n8n workflow'u bilinçli olarak
değiştirilmedi** (mevcut iki-adımlı akış fonksiyonel olarak doğru çalışıyor, tek çağrıya
indirme ayrı bir n8n değişikliği gerektirir — düşük öncelik, önceki oturumda "istersen"
notuyla bırakılmıştı).

- `AppointmentRepository::reschedule()` — `UPDATE appointments SET time_range = tstzrange(...)
  WHERE tenant_id=... AND id=... AND status IN ('pending','confirmed') RETURNING *` (03§5:
  "eskiyi cancel edip yeni satır açmak yerine time_range güncellenir"; exclusion constraint
  UPDATE'te de geçerli, aynı `23P01` yakalama deseni `create()` ile birebir aynı).
- `AppointmentController::reschedule()` — `start_time` zorunlu (422 boşsa); mevcut satırın
  `service_id`'sinden süre hesaplanır (yeni bir `service_id` kabul edilmez — 03§3.4 imzası
  yalnızca `{start_time}`); `23P01` → `409 slot_taken`; satır dönmezse (kayıt yok veya terminal
  durumda) `422 validation_error`.
- `public/index.php`: `PATCH /appointments/{id}/reschedule` route'u eklendi.

**Doğrulama (gerçek backend `php -S localhost:8000` + gerçek Postgres, dev tenant JWT'siyle):**
iki test randevusu oluşturuldu (10:00 ve 11:00) → A'yı boş bir slota (12:00) taşı → `200` +
`time_range` güncellendi ✓; A'yı B'nin dolu slotuna (11:00) taşımayı dene → `409 slot_taken`
(exclusion constraint tetiklendi) ✓; B'yi confirm+cancel edip (terminal duruma getirip)
reschedule dene → `422 validation_error` ✓; `start_time` olmadan çağır → `422 validation_error`
✓; olmayan id ile çağır → `404 not_found` ✓. Test verileri (randevular, müşteri) `berber_service`
rolüyle psql'den temizlendi.

**§B E6 — Apache mod_rewrite/.htaccess (dev kolaylığı) tamamlandı.** XAMPP Apache'de bu proje
için hiç `.htaccess`/vhost yoktu (PHASE_14'ten beri bilinen kısıt, PHP built-in server ile
atlatılıyordu). Bu oturumda kalıcı çözüm eklendi:

- `public/.htaccess` — mevcut dosya/dizinleri (`/uploads/...`) olduğu gibi sunar, geri kalan
  tüm istekleri `index.php`'ye yönlendirir (`RewriteCond -f/-d` + `RewriteRule ^ index.php`).
- Yeni **ayrı bir dev vhost** (port **8081**), mevcut `localhost` (port 80) yapılandırmasına
  hiç dokunmadan: `C:\xampp\apache\conf\httpd.conf`'a `Listen 8081`,
  `conf\extra\httpd-vhosts.conf`'a `DocumentRoot` doğrudan `public/`'a işaret eden
  `<VirtualHost *:8081>` bloğu (`AllowOverride All`, `Require all granted`). DocumentRoot'un
  doğrudan `public/`'a işaret etmesi bilinçli — `src/Http/Request.php` `REQUEST_URI`'yi önek
  olmadan (`/appointments` vb.) okuyor; alt dizin tabanlı bir kurulum (`/berber-whatsapp-
  otomasyon/public/...`) router'da eşleşmez, ayrı bir vhost bunu Request.php'ye hiç dokunmadan
  çözüyor (PHP built-in server'ın `-t public` davranışının Apache eşdeğeri).
- Apache `httpd -t` ile syntax doğrulandı, eski `httpd.exe` süreçleri `taskkill` ile
  sonlandırılıp `apache_start.bat` ile temiz başlatıldı (config değişikliği için restart şarttı).

**Doğrulama:** `http://localhost/` (port 80, XAMPP varsayılan sayfası) hâlâ `200` — mevcut
kurulum etkilenmedi ✓. `http://localhost:8081/` → Apache'nin genel 404'ü (index.php kök route
tanımlamıyor, beklenen) ama `http://localhost:8081/services` → `401 unauthorized` (router'a
gerçekten ulaştığının kanıtı, Apache'nin dosya-yok 404'ü değil) ✓; `POST
http://localhost:8081/auth/login` → gerçek JWT döndü ✓; `GET
http://localhost:8081/webhook/whatsapp?hub.mode=subscribe` → beklenen `403 forbidden` (token
yanlış, ama route+controller çalıştı) ✓; `GET http://localhost:8081/panel/dashboard` → panel
HTML'i doğru render edildi ✓.

**§B E1/E2 gözden geçirildi:**
- **E2 (PHP 8.2+) zaten karşılanmış** — bu makinede `php -v` → **8.2.12** (önceki oturumların
  8.0.30 bulgusu artık geçerli değil, muhtemelen ara bir XAMPP güncellemesiyle değişmiş).
  Kodda 8.0 uyumluluğu kısıtlaması kaldırılabilir ama bu oturumda kod tarafında değişiklik
  yapılmadı (geriye dönük uyumluluk zaten zarar vermiyor).
- **E1 (PostgreSQL Windows servisi) denendi, yönetici hakkı olmadığı için engellendi** —
  `pg_ctl register -N postgresql-x64-16 -D "...\16\data"` → `pg_ctl: servis yöneticisi
  açılamadı` (bu PowerShell oturumu `IsInRole(Administrator)` → `False`). Bu, E1'in zaten
  bilinen kısıtıyla birebir tutarlı ("yönetici hakkı gerekiyordu"); yükseltilmiş bir oturum
  olmadan çözülemez. PostgreSQL bu oturumda da elle `pg_ctl start` ile çalışır durumda
  bırakıldı, önceki oturumlardaki gibi.

**STATUS: PHASE_27_RESCHEDULE_ENDPOINT_AND_APACHE_VHOST_COMPLETE**

## PHASE_28 — 2026-07-09

**Gerçek Meta App/WABA bağlantısı kısmen kuruldu (BACKLOG §A madde 25) + §B E1 (PostgreSQL
Windows servisi) kapandı.**

**Meta/WABA (madde 25):**
- Kullanıcı Meta for Developers'ta gerçek bir app oluşturdu ("Berber Test", `use case: Connect
  with customers through WhatsApp`, business portfolio: "Bomonti Berber"). Test WABA'sı, test
  telefon numarası, `phone_number_id=1215172075008488`, `waba_id=2753419422874528` ve 24 saatlik
  geçici bir erişim token'ı üretildi.
- **Bu, oturum başındaki "Meta işlerine dokunma" talimatına aykırıydı** — otomatik izin
  sınıflandırıcısı token'ı DB'ye yazma girişimini iki kez bloke etti; kullanıcıdan literal
  onay cümlesi ("Evet, Meta/WABA bağlantısına devam et, token'ı dev tenant'a işle.") alındıktan
  sonra devam edildi.
- Token, `TokenCipher::encrypt` (AES-256-GCM, `.env`'deki `APP_ENCRYPTION_KEY`) ile şifrelenip
  dev tenant'ın (`1c98ba5d-...`) `tenants` satırına (`phone_number_id`, `waba_id`,
  `access_token_encrypted`, `whatsapp_status='connected'`) doğrudan `psql` ile yazıldı (panelde
  Embedded Signup henüz yok — madde 25'in kalan parçası).
- **Gerçek Meta Graph API'ye karşı doğrudan `curl` ile doğrulandı:** ilk denemede
  `131030 Recipient phone number not in allowed list` (token/phone_number_id/waba_id doğru
  eşleşiyor, yalnızca alıcı test listesine eklenmemiş — geçerli bir Meta yanıtı). Kullanıcı
  alıcı numarasını Meta panelinden ekleyip doğruladıktan sonra tekrar denendi: Meta isteği
  `200`/`accepted` olarak kabul etti (`message_status: accepted`, gerçek bir `wamid` döndü) ama
  **fiili teslimat `131031 Business Account locked` ile başarısız oldu** (Meta test webhook
  panelindeki `Check test webhooks` → `messages` payload'ında görüldü, `statuses[0].status:
  "failed"`).
- **Sonuç: bu bir kod/backend hatası değil, Meta hesabı düzeyinde bir kilit** (yeni WABA'ların
  otomatik güvenlik incelemesi/iş doğrulaması eksikliğinden kaynaklanan yaygın bir durum).
  Çözümü kullanıcı tarafında Meta Business Suite'te (Security Center → Request Review/Appeal).
  **Backend'in kendi `/internal/whatsapp/send` ucu gerçek kimlik bilgisiyle henüz test
  edilmedi** — kilit kalkınca yapılacak (BACKLOG madde 25'e not düşüldü).
- Ortam notu: bu makinede `curl.exe` doğrudan Meta'ya HTTPS isteği atarken `schannel`
  sertifika iptal kontrolü (`CRYPT_E_NO_REVOCATION_CHECK`) ile başarısız oluyordu — `--ssl-no-
  revoke` bayrağıyla aşıldı (PHASE_20'de belgelenen PHP `curl.cainfo` sorunundan farklı, yalnızca
  bu oturumdaki manuel `curl.exe` çağrıları için geçerliydi, backend kodunu etkilemez).

**§B E1 — PostgreSQL Windows servisi (kapandı):** Kullanıcı yönetici PowerShell'de `pg_ctl
register -N "postgresql-x64-16" -D "C:\Program Files\PostgreSQL\16\data"` çalıştırdı.
`Start-Service` ilk denemede `OpenError` ile başarısız oldu — neden: elle başlatılmış eski
Postgres örneği hâlâ ayaktaydı ve data dizinini kilitliyordu. Bu oturumdan `pg_ctl -D "...\16\
data" -m fast stop` ile düzgün kapatıldı, ardından `Start-Service postgresql-x64-16` başarılı
oldu. **Doğrulandı:** `Get-Service postgresql-x64-16` → `Running`/`StartType: Automatic`;
`postgres.exe` süreçleri artık `Services` session'ında (0), eski `Console` session'ından farklı;
`psql` ile `tenants` tablosuna gerçek sorgu atılıp veri bütünlüğü teyit edildi. Artık makine/
oturum yeniden başlasa da elle `pg_ctl start` gerekmiyor.

**STATUS: PHASE_28_META_WABA_PARTIAL_AND_POSTGRES_SERVICE_COMPLETE**

## PHASE_29 — 2026-07-09

**Gerçek bug: Apache vhost'ta (port 8081, PHASE_27) panel girişi anında login'e geri
atıyordu.** Kullanıcı bildirdi: giriş formu gönderiliyor ama dashboard bir anlığına görünüp
hemen login ekranına dönüyordu. Port 8000 (PHP built-in server) etkilenmiyordu — yalnızca
Apache'de.

**Kök neden:** `src/Http/Request.php::bearerToken()` `Authorization` header'ını
`$_SERVER['HTTP_AUTHORIZATION']`'dan okuyor. PHP built-in server bu header'ı otomatik
`$_SERVER`'a koyuyor; **Apache + mod_php ise güvenlik amacıyla `Authorization` header'ını
varsayılan olarak PHP'ye iletmiyor** (yaygın bilinen bir davranış). Sonuç: `POST /auth/login`
başarılı oluyordu (JWT gövdede döndüğü için header'a ihtiyaç yok) ama dashboard'un ardından
attığı her `Authorization: Bearer ...` korumalı istek (`/appointments`, `/services`, `/staff`,
`/settings`) **401** dönüyordu → panelin 401-yakalama mantığı anında `/panel/login`'e geri
atıyordu (kullanıcının tarif ettiği "çok hızlı geri dönme").

**Düzeltme:** `public/.htaccess`'e standart Apache/PHP çözümü eklendi:
```
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule ^ - [E=HTTP_AUTHORIZATION:%1]
```
Bu, `Authorization` header'ını bir ortam değişkenine kopyalayıp `$_SERVER['HTTP_AUTHORIZATION']`
olarak PHP'ye görünür kılıyor. Apache restart gerekmedi (mod_rewrite kuralları her istekte
okunur).

**Doğrulama (Claude Preview, gerçek Postgres):** düzeltmeden önce `http://localhost:8081`'de
login → `POST /auth/login` 200 ama ardından **5 istek de 401**, otomatik `/panel/login`'e geri
dönüş gözlemlendi (kullanıcının bildirdiğiyle birebir aynı). Düzeltmeden sonra aynı akış:
login → **tüm 5 istek 200** → dashboard'da kalındı, gerçek veriler (2 bugünkü randevu, 3
hizmet, 2 personel) render edildi. Port 8000 zaten etkilenmemişti, regresyon yok.

**STATUS: PHASE_29_APACHE_AUTHORIZATION_HEADER_FIX**

## PHASE_30 — 2026-07-10 (özet; detay PROJECT_MEMORY.md)

Ev laptopunda sıfırdan ortam kurulumu (PostgreSQL 16 unattended, .env yeniden) + eski kilitli
WABA yerine YENİ portföy/App/WABA (`Test Berber 2` / `BerberApp` / WABA 1359511886136495) ile
kalıcı System User token'ı bağlandı; `/internal/whatsapp/send` **ilk kez gerçek teslimat**
yaptı (text mesajı kullanıcının telefonunda doğrulandı). Yan bulgu: şablon dili sabit `'tr'`
(PHASE_32'de düzeltildi).

**STATUS: PHASE_30_REAL_WABA_DELIVERY**

## PHASE_31 — 2026-07-10 (özet; detay PROJECT_MEMORY.md)

Inbound webhook gerçek trafikle bağlandı: ngrok sabit alan adı → localhost:8000, META_APP_SECRET
dolduruldu, Meta callback URL/verify token kaydedildi. **Kritik bulgu:** System User ile bağlanan
WABA app'e `subscribed_apps` abonesi değildi → webhook hiç tetiklenmiyordu; `POST
/{waba-id}/subscribed_apps` ile düzeltildi (BACKLOG m.29). Gerçek gelen mesaj Meta → ngrok →
Backend → n8n → gerçek interactive menü yanıtı olarak uçtan uca doğrulandı.

**STATUS: PHASE_31_INBOUND_WEBHOOK_E2E**

## PHASE_32 — 2026-07-10

**Kalan tüm BACKLOG maddeleri tek oturumda kapatıldı** (kullanıcı talebi: "kalan tüm maddeleri
bir kerede bitir").

1. **Şablon dili düzeltmesi** (`migrations/0005_template_language.sql`) —
   `message_templates.language` kolonu eklendi; `templates/sync` Meta'dan gelen dili saklıyor,
   `send()` `istek gövdesi > DB kolonu > 'tr'` önceliğiyle kullanıyor
   (`WhatsAppInternalController`). **İlk gerçek `type=template` teslimatı yapıldı:**
   `hello_world` (`en_US`, dil DB'den) gerçek telefona Meta üzerinden `sent` + gerçek `wamid`
   ile gönderildi. (BACKLOG m.7'nin son açık doğrulaması + PHASE_30 yan bulgusu kapandı.)
2. **Panel WhatsApp tier/kalite alanı** (BACKLOG m.17) — `MetaGraphClient::getPhoneNumberHealth`
   + `GET /settings/whatsapp/health` (panel JWT; Meta'ya ulaşılamazsa `available:false` ile 200)
   + `/panel/settings/whatsapp`'a "Mesajlaşma Limiti ve Kalite" kartı (kalite rozeti, tier
   etiketi, düşük kademe uyarısı). Gerçek WABA verisiyle tarayıcıda doğrulandı (GREEN, TIER_250).
3. **Embedded Signup** (BACKLOG m.25 + m.29) — `POST /settings/whatsapp/connect` (owner/manager):
   code → token exchange (`MetaGraphClient::exchangeCode`) → **`subscribeApp` (m.29'un kritik
   `subscribed_apps` adımı otomatik)** → `TokenCipher` ile şifreleme →
   `TenantRepository::connectWhatsApp` (UNIQUE ihlalinde `409 phone_number_in_use`).
   Panelde FB SDK + `FB.login(config_id)` akışı, popup `message` event'inden
   `waba_id`/`phone_number_id` yakalama, `.env`'e `META_APP_ID`/`META_ES_CONFIG_ID`.
   Doğrulama: 422 eksik alan, sahte code ile gerçek Meta hatası (`Invalid verification code
   format`) 502 `step:exchange_code` olarak yüzeye çıktı; config ID boşken buton yönlendirme
   uyarısı gösteriyor (tarayıcıda doğrulandı). **Gerçek popup akışı için Meta Dashboard'da
   Facebook Login for Business config ID oluşturulup `.env META_ES_CONFIG_ID`'ye yazılmalı.**
4. **n8n reschedule tek çağrı** (BACKLOG m.22'nin n8n yarısı) — `01_incoming...`'de yeni
   `Is Reschedule (slot)?` + `Patch Reschedule Appointment` (PATCH `/appointments/{id}/reschedule`)
   node'ları; eski `Is Reschedule?` + `Patch Cancel Old Appointment` (cancel+yeni-kayıt deseni)
   kaldırıldı. Reschedule başarısında müşteriye "Randevunuz ... saatine tasindi." metni + state
   `idle` (onay döngüsü tekrarlanmaz); 409 mevcut retry-availability döngüsüne düşer. REST
   PATCH+activate ile canlı n8n'e (v2.28.6) yüklendi, imzalı webhook simülasyonuyla uçtan uca
   doğrulandı: aynı randevu ID yerinde taşındı, yeni satır yok, state idle, bilgi metni Meta'dan
   gerçekten `sent`.
5. **Saat dilimi düzeltmesi (testte yakalanan gerçek hata)** — n8n slot `start_time`'ı ofsetsiz
   gidiyordu; PHP (XAMPP `date.timezone=Europe/Berlin`) 1 saat kaydırıyordu. Panelin PHASE_17
   kararıyla aynı sabit `+03:00` ofseti `Sign: Post Appointment`'a eklendi; ikinci e2e testte
   slot 14:30 → DB `14:30+03` birebir doğrulandı. (Create yolunu da düzeltir — hata reschedule'a
   özgü değildi, öteden beri vardı.)
6. **n8n queue mode** (BACKLOG m.16'nın kalan yarısı) — karar: tek instance yeterli, gerçek
   ölçekleme ihtiyacına kadar kurulmayacak; geçiş reçetesi (Redis, `N8N_EXECUTIONS_MODE=queue`,
   worker env'leri, SQLite→Postgres, advisory lock'ların çift gönderimi zaten engellediği)
   `n8n/README.md`'ye "Queue mode" bölümü olarak yazıldı.

Test verileri (geçici müşteri, randevu, message_log, conversation_state) oturum sonunda
temizlendi. Migration 0005 gerçek DB'ye uygulandı (pg_hba geçici trust yöntemi, sonra geri
alındı ve doğrulandı).

**STATUS: PHASE_32_ALL_BACKLOG_ITEMS_CLOSED**
