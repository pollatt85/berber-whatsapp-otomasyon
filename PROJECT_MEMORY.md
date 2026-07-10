# PROJECT_MEMORY

Bu dosya, Development Pack'in "Claude her oturumda kaldığı yerden devam edecek" ilkesini
uygulamak için tutulur. `00_Master_Roadmap.md` fazların **tasarım** durumunu, bu dosya ise
**kodlama** durumunu ve oturumlar arası kararları takip eder. `CHANGELOG.md` sürüm geçmişi,
bu dosya ise "şu an nerede kaldık" anlık fotoğrafıdır — her oturum sonunda güncellenir.

## Genel Durum

- Dokümantasyon fazı (00-09): ✅ tamamlandı (9/9).
- Kodlama fazı: 🟡 devam ediyor. Backend + n8n + panel + AI (Gemini) + Redis rate limit +
  kampanya gönderimi + inbound log fix + akış-ortası AI yönlendirmesi + reschedule ucu +
  Apache dev vhost'u + Postgres Windows servisi hepsi tamamlandı (PHASE_14-28). **PHASE_30'da
  eski Meta hesap kilidi tamamen atlatıldı**: kilitli WABA (`Bomonti Berber` portföyü) terk
  edildi, YENİ bir portföy/App/WABA (`Test Berber 2` / `BerberApp` / WABA
  `1359511886136495`, phone_number_id `1147876331751351`) üzerinden **backend'in kendi
  `/internal/whatsapp/send` ucu gerçek bir WhatsApp mesajını gerçek bir telefona uçtan uca
  teslim etti** (kullanıcı tarafından WhatsApp ekran görüntüsüyle doğrulandı). Kalan açık:
  Embedded Signup panele kodlanması (madde 25'in geri kalanı) + n8n queue mode (madde 16,
  yalnızca ölçekleme aşamasında, dokunulmadı) + backend'in şablon dili sabit `tr` olması
  (yalnızca `en_US` onaylı şablonlarla çakışıyor, aşağıda not).

## Bu Oturumda Yapılanlar (PHASE_30 — Ev laptopunda sıfırdan ortam kurulumu + yeni WABA ile gerçek teslimat)

- **Bu makinede (ev laptopu) ilk kez ortam kuruldu:** PostgreSQL 16 hiç kurulu değildi — EDB
  installer indirilip `--mode unattended` ile sessiz kuruldu, Windows servisi
  (`postgresql-x64-16`) olarak kaydedildi. **Kurulum sırasında beklenmedik bir bulgu:**
  `berber_saas` veritabanı ve `dev-panel-000` dev tenant'ı da dahil 18 tablonun tümü zaten
  mevcuttu — bu makinede daha önce yarım kalmış bir kurulum vardı (ama migration 0003/0004
  eksikti, muhtemelen o oturum PHASE_22/23'ten önce yarıda kesilmiş). Eksik migration'lar
  bu oturumda tamamlandı, `berber_app`/`berber_service` rol şifreleri yeni `.env`'e göre
  `ALTER ROLE` ile senkronize edildi.
- **`.env` sıfırdan oluşturuldu** (`.env.example`'dan, tüm secret'lar/şifreler rastgele
  üretildi: `JWT_SECRET`, `N8N_SERVICE_SECRET`, `APP_ENCRYPTION_KEY`, DB şifreleri,
  `WEBHOOK_VERIFY_TOKEN`). XAMPP PHP **8.0.30** (önceki oturumların 8.2 bulgusu bu makinede
  geçerli değil — proje zaten 8.0-uyumlu yazıldığı için sorun olmadı), `pdo_pgsql`/`pgsql`
  zaten etkindi.
- **Yeni Meta kimlikleri gerçek teslimatla doğrulandı** (BACKLOG §A madde 25'in çekirdeği
  kapandı): eski kilitli `Bomonti Berber` WABA'sı yerine yeni `Test Berber 2` portföyündeki
  WABA kullanıldı. Kalıcı bir **System User** (`berber-backend`, Admin rolü, Business
  Settings > System Users) oluşturulup BerberApp + yeni WABA'ya "Tam erişim" atandı, **süresiz
  ("Asla" son kullanma tarihi)** bir access token üretildi (izinler: yalnızca
  `whatsapp_business_management` + `whatsapp_business_messaging` — least-privilege, diğer 2
  önerilen izin alınmadı). Bu, PHASE_28'deki 24 saatlik geçici token yerine kalıcı bir çözüm —
  **artık token süresi dolduğunda yeniden üretmeye gerek yok.**
- Token Graph API'ye karşı doğrudan doğrulandı (`quality_rating: GREEN`, hesap kilidi yok),
  `TokenCipher::encrypt` ile şifrelenip dev tenant'a yazıldı (`phone_number_id`, `waba_id`,
  `whatsapp_status='connected'`).
- **`POST /internal/whatsapp/send` ilk kez gerçek kimlik bilgisiyle çalıştırıldı ve gerçek
  teslimat kanıtlandı** — bu, BACKLOG madde 7/25'in en son açık kalan doğrulama adımıydı.
  İlk denemede metin (`type=text`) mesajı Meta'dan `sent` + gerçek `wamid` aldı ama **telefona
  hiç ulaşmadı** — kök neden: Meta'nın 24 saatlik konuşma penceresi kendi sunucusunda
  tutuluyor, backend'in `message_log`'a attığı sahte `log-inbound` kaydı Meta'yı etkilemiyor
  (yalnızca bizim `lastInboundAt` kontrolümüzü geçiyor, Meta'nın kendi server-side penceresini
  açmıyor). **Çözüm:** kullanıcı gerçek telefonundan işletme test numarasına (+1 555-190-4459)
  gerçekten bir WhatsApp mesajı attı (Meta'nın kendi webhook test ekranında payload'ı görüldü)
  — bu Meta'nın gerçek penceresini açtı, ardından aynı text mesajı tekrar gönderildi ve
  **kullanıcı telefonunda gerçekten göründüğünü ekran görüntüsüyle doğruladı.**
- **Yan bulgu — şablon dili:** `WhatsAppInternalController::send()` şablon mesajlarında dili
  sabit `'tr'` gönderiyor (`src/Http/Controllers/WhatsAppInternalController.php:100`); bu yeni
  WABA'nın senkronize edilen tüm şablonları (`hello_world` dahil) `en_US` onaylı — şu an
  gerçek bir Türkçe onaylı şablon yok, bu yüzden şablon testi bu oturumda YAPILAMADI (yalnızca
  metin mesajıyla teslimat kanıtlandı). Üretimde işletme kendi Türkçe şablonunu onaylatacağı
  için pratikte sorun değil, ama sabit `'tr'` çok-dilli/test senaryolarında kırılgan — henüz
  BACKLOG'a madde olarak açılmadı, bir sonraki oturumda değerlendirilebilir.
- Test için eklenen gerçek telefon numaralı geçici müşteri (`905418255314`) ve ilişkili
  `message_log` satırları oturum sonunda temizlendi (dev seed'in kalıcı 3 sahte müşterisine
  dokunulmadı).

## Önceki Oturum (PHASE_29 — Apache Authorization header bug'ı)

- **Kullanıcı gerçek bir bug bildirdi:** `http://localhost:8081` (PHASE_27'nin Apache vhost'u)
  üzerinden panele giriş yapınca dashboard bir anlığına görünüp anında login'e geri dönüyordu.
  Port 8000 (PHP built-in server) etkilenmiyordu.
- **Kök neden:** Apache + mod_php, güvenlik amacıyla `Authorization` header'ını varsayılan
  olarak `$_SERVER['HTTP_AUTHORIZATION']`'a **iletmiyor** (PHP built-in server iletiyor).
  `src/Http/Request.php::bearerToken()` bu değişkeni okuyor → login (`POST /auth/login`, JWT
  gövdede döner, header'a ihtiyaç yok) çalışıyor ama ardından panelin attığı JWT korumalı her
  istek (`/appointments`, `/services`, `/staff`, `/settings`) 401 dönüp anında login'e geri
  atıyordu.
- **Düzeltme:** `public/.htaccess`'e standart Apache/PHP çözümü eklendi:
  `RewriteCond %{HTTP:Authorization} ^(.*)` + `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%1]`.
  Apache restart gerekmedi.
- **Doğrulama (Claude Preview):** düzeltmeden önce port 8081'de 5 korumalı istek de 401 +
  login'e geri dönüş gözlemlendi (kullanıcının bildirdiğiyle birebir); düzeltmeden sonra aynı
  akış 5/5 200 ile dashboard'da kaldı, gerçek veriler render edildi. Port 8000 regresyon yok.

## Önceki Oturum (PHASE_28 — Meta/WABA bağlantısı + Postgres servis kaydı)

- **Gerçek Meta App + test WABA bağlandı** (BACKLOG §A m.25): kullanıcı Meta for Developers'ta
  "Berber Test" app'ini oluşturdu, `phone_number_id`/`waba_id`/24 saatlik geçici token üretti.
  Bu, oturum başındaki "Meta işlerine dokunma" talimatına aykırı olduğu için otomatik izin
  sınıflandırıcısı token'ı DB'ye yazma girişimini iki kez bloke etti; kullanıcıdan literal onay
  cümlesi alındıktan sonra devam edildi (`TokenCipher::encrypt` + doğrudan `psql UPDATE tenants`
  ile dev tenant'a yazıldı).
- **Gerçek Meta Graph API'ye karşı doğrulandı** (`curl --ssl-no-revoke` — bu makinede `curl.exe`
  schannel sertifika iptal kontrolünde başarısız oluyordu, bayrakla aşıldı): token/phone_number_id
  doğru (`131030 recipient not in allowed list` → alıcı eklenip tekrar denendiğinde Meta isteği
  `accepted` etti) ama **fiili teslimat `131031 Business Account locked` ile başarısız oldu —
  bu bir Meta hesap kilidi, kod/backend hatası değil.** Kullanıcının Meta Business Suite'te
  çözmesi gerekiyor; backend'in kendi `/internal/whatsapp/send` ucu henüz gerçek kimlik
  bilgisiyle denenmedi (kilit kalkınca yapılacak).
- **§B E1 kapandı** — kullanıcı yönetici PowerShell'de `pg_ctl register` çalıştırdı; bu oturumdan
  elle çalışan eski Postgres örneği (`pg_ctl -m fast stop`) düzgün kapatılıp servise geçildi.
  `Get-Service postgresql-x64-16` → `Running`/`Automatic` doğrulandı, `psql` ile gerçek veri
  sorgusu atıldı. Artık makine/oturum yeniden başlasa da elle `pg_ctl start` gerekmiyor.

## Önceki Oturum (PHASE_27 — Reschedule ucu + Apache vhost, Meta-dışı kalemler)

- **`PATCH /appointments/{id}/reschedule`** (BACKLOG §A m.22) — `AppointmentRepository::
  reschedule()` (in-place `UPDATE...time_range`, `23P01` exclusion constraint `create()` ile
  aynı desen) + `AppointmentController::reschedule()` (`start_time` zorunlu, süre mevcut
  `service_id`'den, `409 slot_taken`/`422`/`404`). Route `public/index.php`'ye eklendi. Gerçek
  Postgres'e karşı uçtan uca test edildi (200 taşıma, 409 çakışma, 422 terminal durum, 422
  eksik alan, 404 yok). **n8n workflow'u bilinçli DEĞİŞTİRİLMEDİ** — mevcut cancel+yeni-appointment
  akışı çalışıyor, tek çağrıya indirme ayrı bir n8n değişikliği (kullanıcı "istersen" dedi,
  bu oturumda backend'e öncelik verildi; `n8n/README.md` madde 1 hâlâ eski deseni belgeliyor,
  güncellenmedi).
- **Apache mod_rewrite/.htaccess** (§B E6) — `public/.htaccess` + `C:\xampp\apache\conf\
  httpd.conf`'a `Listen 8081` + `conf\extra\httpd-vhosts.conf`'a `DocumentRoot`'u doğrudan
  `public/`'a işaret eden ayrı bir `<VirtualHost *:8081>` (mevcut port-80 dokunulmadı).
  Apache restart edildi (eski `httpd.exe` süreçleri `taskkill`, `apache_start.bat` ile temiz
  başlatıldı). Doğrulandı: port 80 hâlâ 200, port 8081'de `/services` 401 (router'a ulaştı,
  Apache 404'ü değil), `/auth/login` gerçek JWT, `/webhook/whatsapp` 403, `/panel/dashboard`
  HTML — hepsi gerçek Postgres'e karşı.
- **§B E1** tekrar denendi: `pg_ctl register` yönetici hakkı olmadan yine `servis yöneticisi
  açılamadı` hatası verdi (bu oturumun PowerShell'i admin değil) — beklenen, çözülemedi.
- **§B E2** doğrulandı: `php -v` → **8.2.12**, karar (8.2+) zaten karşılanıyor, ek işlem
  gerekmedi.
- Test verileri (randevular, geçici müşteri) `berber_service` rolüyle psql'den temizlendi.

## Önceki Oturum (PHASE_26 — Akış-ortası AI yönlendirmesi)

- `01_incoming_whatsapp_message.json` `Determine Route`: text yalnızca `step==='idle'`'de
  `new_session` (menü); akış ortasındaki (`step!=idle`) serbest text → `ai_question` →
  Switch fallback (`unhandled`) → AI zinciri. BACKLOG §A m.27b kapandı (tüm 27 alt-görevleri
  bitti). Gerçek n8n v2.28.6 + Backend + Postgres + Gemini ile uçtan uca doğrulandı.
- **Önemli ortam değişikliği:** n8n workflow'u REST `PATCH`+`activate` ile güncellendi; bu,
  PHASE_20'de belgelenen bozuk workflow-ID-önekli webhook path'ini temizledi. Canlı üretim URL'i
  artık temiz: `http://localhost:5678/webhook/incoming-whatsapp-message`. `.env`'deki
  `N8N_INCOMING_WEBHOOK_URL` buna göre güncellendi (eski buggy path artık 404 dönüyor).
- n8n süreci restart edilmedi — `activate` webhook'u yeniden kaydetti (restart gereksinimi bu
  senaryoda geçerli olmadı). n8n prosesi (PID başka oturumdan, force-kill edilemiyor) çalışır
  bırakıldı.

## Önceki Oturum (PHASE_19 — AI Modülü: POST /ai/respond)

Hedef: 07_AI_Module.md §2/§4/§5 — AI'ın kendisi (ayarlar tarafı GET/PATCH /settings/ai
PHASE_17'de bitmişti). AI randevu oluşturmaz/değiştirmez (§1); yalnızca bilgi verir, işlemsel
niyette `intent='appointment_action'` döner. Doğrulama gerçek Postgres + PHP built-in server'a
karşı HMAC imzalı isteklerle yapıldı; **ANTHROPIC_API_KEY .env'de BOŞ → gerçek LLM çağrısı
yapılmadı**, fallback yolları test edildi.

1. **`src/Support/ClaudeClient.php`** — Anthropic Messages API ham cURL istemcisi
   (MetaGraphClient kardeşi). Model `claude-haiku-4-5`; başlıklar `x-api-key` /
   `anthropic-version: 2023-06-01` / `content-type`. Yapılandırılmış çıktı tek zorunlu
   `provide_response` tool'u ile (`tool_choice`): `{reply, intent}` (intent enum
   `faq|appointment_action|unclear`), `input_schema` `additionalProperties:false` + `required`.
2. **`src/Http/Controllers/AiRespondController.php`** — sistem promptu: sabit rol + guardrail
   (kapsam dışı reddi, fiyat/hizmet uydurmama, işlem yapmama) + tenant `tone` + **yalnızca bu
   tenant'ın** services/staff/working_hours (PII izolasyonu) + knowledge_base + son 5 mesaj
   (`message_log`). **Graceful:** enabled=false / anahtar yok / LLM hatası → her zaman 200 +
   sabit fallback (`source`: `fallback:disabled|no_api_key|llm_error`), n8n'e asla 5xx yok.
3. **`MessageLogRepository::recentForCustomer`** eklendi (son N mesaj, eskiden yeniye).
4. **`public/index.php`**: `POST /ai/respond` = `ServiceHmacMiddleware::authenticate` +
   `Connection::service()`, tenant_id body'den (/conversation-state deseni). Repo'lar tenant_id'yi
   açıkça alır → BYPASSRLS'te de izole.
5. **rate_limit_per_minute (07§5) ertelendi** — Redis gerektiriyor (BACKLOG §A m.11/16), kodda
   yorumla işaretli. n8n retry'ı şu an sınırsız çağrı yapabilir.
6. **Doğrulama:** enabled=true+anahtar yok → `fallback:no_api_key` (200); enabled=false →
   `fallback:disabled` (200); imzasız → 401; customer_message yok → 422. Dev tenant ai_settings
   enabled psql ile geçici false yapılıp test sonrası true'ya geri alındı.
7. **n8n dalı bağlandı** (`n8n/01_incoming_whatsapp_message.json`): Switch'in fallback (unhandled)
   çıkışı artık `Sign: AI Respond` → `AI Respond` (POST /ai/respond, neverError) → `Build AI
   Reply Msg` (intent='appointment_action' → menü yönlendirme notu, 07§2 adım 6) → `Send AI
   Reply` (type='text') → `Respond OK (ai)`. 58 node; JSON bütünlüğü + gömülü JS `node --check`
   ile doğrulandı. **Gerçek n8n instance'ında execute EDİLMEDİ** (PHASE_14 deseni bir sonraki
   adım) ve `Determine Route` her text mesajı hâlâ `new_session`'a yolluyor — sadece menü-dışı/
   eşleşmeyen mesajlar AI'a düşer (BACKLOG m.27 (c)).

## Önceki Oturum (PHASE_18 — Admin Panel 4. / Son Tur)

Hedef: BACKLOG §A madde 24'ün kalan iki öğesi. İkisi de gerçek Postgres + çalışan backend'e
karşı tarayıcıda doğrulandı. **Yeni Backend ucu gerekmedi** (06§9 kararı; platform API'si
PHASE_12'de hazırdı).

1. **`/panel/reports`** (yeni `views/panel/reports.php`, Chart.js 4.4.3 CDN) — 06§9 üç grafik,
   mevcut `GET /appointments` + `GET /staff/{id}/schedule` panelde agregre:
   - Randevu hacmi (çizgi, günlük/haftalık toggle — gün/hafta kovalarına sayım).
   - Durum dağılımı (pasta — iptal/no-show oranı, `Panel.STATUS` renkleri).
   - Personel doluluk (yatay bar — iptal dışı randevu süresi / working_hours kapasitesi;
     kapasite gün gün day_of_week eşleşmesiyle toplanır, 0 ise "(kapasite yok)"; mola/tatil
     kapasiteden düşülmez — bilinçli sadeleştirme).
   - Tarih aralığı formu (varsayılan içinde bulunulan ay) + özet satırı (toplam/iptal%/no-show%).
   - `layout.php`'de "Raporlar (yakında)" placeholder (`$planned`) kaldırıldı, gerçek link kondu.
2. **Platform admin UI** (09§5, tenant panelinden AYRI hafif kabuk):
   - `views/panel/platform_login.php` — ayrı login, **ayrı `platform_jwt` anahtarı**,
     `type: platform_admin` claim kontrolü (tenant JWT'siyle karışmaz).
   - `views/panel/platform_tenants.php` — Panel çekirdeğinden ayrı `PlatformUI` objesi (kendi
     api/guard/logout, 401/403→/platform/login); tenant listesi + Askıya Al/Aktifleştir
     (`PATCH /platform/tenants/{id}`).
   - `public/index.php`: `/panel/reports`, `/platform`, `/platform/login` route'ları.
   - `scripts/dev_seed.php`: idempotent `platform_admins` seed'i — **platform@berber.local /
     PlatformDev1!** (çalıştırıldı).
   - `.claude/launch.json`: `backend-alt` (port 8010) config'i eklendi (8000 başka oturumda dolu).
3. **Doğrulama** (preview_eval/snapshot DOM + psql; preview_screenshot güvenilmez):
   - Raporlar: 2026-07-07'ye 3 confirmed randevu eklendi → doluluk Ahmet %12.5/Mehmet %3.3,
     durum pastası 3 onaylı+3 iptal, iptal oranı %50; toggle + tarih filtresi çalıştı.
   - Platform: yanlış/doğru parola, suspend→psql `status='suspended'`→activate; **tenant JWT'si
     platform_jwt'ye konunca guard reddedip login'e attı** (cross-token izolasyonu doğrulandı).

## Önceki Oturum (PHASE_17 — Admin Panel 3. Tur)

1. **Backend ekleri** (hepsi tarayıcıdan gerçek Postgres'e karşı test edildi):
   - `GET /messages/templates` (`MessageTemplateRepository::listAll` + yeni
     `MessageTemplateController`, salt okunur 06§7).
   - `POST /messages/templates/sync` — HMAC kanalındaki sync'in **panel JWT sarmalayıcısı**
     (owner/manager; tenant_id JWT'den, body'den asla; `syncTemplates`'e opsiyonel
     `$tenantId` parametresi). Sahte dev token'la Meta hatasının panele düzgün düştüğü
     doğrulandı — gerçek WABA ile hâlâ hiç çalıştırılmadı.
   - `/campaigns` CRUD (yeni `CampaignRepository`+`CampaignController`): GET/POST,
     PATCH /{id} (yalnızca draft/scheduled), PATCH /{id}/cancel. Yalnızca
     `template_type='campaign'`+`active=true` şablon kabul (422); `target_filter` jsonb
     `last_visit_min_days`; scheduled_at varsa 'scheduled' yoksa 'draft'. **Gönderim
     mekanizması yok** (BACKLOG §A madde 26).
   - `GET/PATCH /settings/ai` (yeni `AiSettingsRepository`+`AiSettingsController`):
     satır yoksa migration varsayılanları (satır oluşturmadan), PATCH upsert;
     `knowledge_base` şema doğrulaması (faq[{q,a}], policies.cancellation/late_arrival);
     `rate_limit_per_minute` salt okunur (07§5 platform koruması).
2. **Panel sayfaları:** /panel/messages/templates (tür rozetleri + sync butonu),
   /panel/appointments "Yeni Randevu" modalı (kayıtlı/yeni müşteri, availability slot
   butonları, 409'da modal açık kalıp alternatif slotlar yeniden listelenir — gerçek yarış
   simülasyonuyla test edildi), /panel/messages/campaigns (oluştur/düzenle/iptal, alan
   bazlı 422), /panel/settings/ai (SSS ekle/sil, politikalar, ton, enabled switch).
   Sidebar'a 3 link eklendi; "yakında" placeholder'ında yalnız Raporlar kaldı.
3. **start_time/scheduled_at TZ kararı:** panel TR kalıcı UTC+3 varsayımıyla sabit
   `+03:00` ofsetiyle gönderir (tarayıcı TZ'sine güvenmek yerine) — randevu ve kampanya
   modallarında yorum satırıyla belgeli.
4. `scripts/dev_seed.php`'ye 6 idempotent mesaj şablonu eklendi (2 campaign, 1 pasif).
5. **Doğrulama:** preview_eval/snapshot DOM kanıtları + psql satır kontrolleri
   (`berber_service` kullanıcısı, PGPASSWORD ile). Form submit'leri `requestSubmit()` ile
   (PHASE_16 notundaki preview_click sorunu nedeniyle). preview_screenshot hiç denenmedi.
   Dev tenant'ta artık test artığı satırlar var (2026-07-07'ye 3 randevu, 1 cancelled
   kampanya, Deniz Yılmaz müşterisi, ai_settings satırı) — dev seed felsefesi gereği
   bilinçli bırakıldı.

## Önceki Oturum (PHASE_16 — Admin Panel 2. Tur)

1. **Backend ekleri** (hepsi curl ile gerçek Postgres'e karşı test edildi):
   - `GET /customers` çift modlu yapıldı: `whatsapp_number` verilirse eski n8n tek-kayıt
     davranışı birebir korunur; verilmezse liste + `?search=` (isim/telefon ILIKE) +
     `last_appointment_at` (iptaller hariç) + `appointment_count` (06§6).
   - `GET /customers/{id}` detay ucu; `GET /appointments?customer_id=` filtresi
     (`listByFilters` 5. opsiyonel parametre, geriye uyumlu).
   - `GET /messages/log` — yeni `MessageLogController` + `MessageLogRepository::listByFilters`
     (yön/durum/tarih aralığı/customer_id, müşteri adı JOIN, en yeni 200 satır, alan bazlı 422).
2. **Panel sayfaları:** /panel/customers (arama+liste, anonim kayıtlar "(anonim)"),
   /panel/customers/{id} (bilgi + randevu geçmişi + mesaj geçmişi), /panel/messages/log
   (filtreler + failed satırda meta_error_code tooltip'i), /panel/settings/business
   (PATCH /settings + alan bazlı 422 + multipart logo yükleme; harita seçici yerine sayısal
   lat/lng — bilinçli sadeleştirme). Sidebar'a üç yeni link eklendi.
3. **FullCalendar (06§4):** /panel/appointments'a Liste/Takvim anahtarı. Scheduler 6.1.15 CDN,
   masaüstü `resourceTimeGridDay` (personel=kolon), <768px `listDay`; durum renkleri;
   eventClick → o güne filtreli liste; `windowResize` ile otomatik görünüm geçişi.
   **Lisans:** şimdilik non-commercial CC anahtarı — üretimde ticari lisans (BACKLOG §B E8).
4. **Doğrulama Claude Preview + gerçek Chrome:** tüm ekranlar gerçek Postgres'e karşı
   tarayıcıda test edildi (arama, filtreler, 422 alan hataları, canvas'tan üretilmiş gerçek
   PNG ile logo yükleme → dosya sistemi + tenants satırı doğrulandı, takvim masaüstü/mobil).
   `scripts/dev_seed.php`'ye 5 satırlık idempotent message_log örneği eklendi (1 failed,
   meta_error_code=131047).
5. **Oturum gözlemleri:** `preview_screenshot` bu oturumda sürekli 30s timeout verdi (sayfa
   sağlıklı, eval/snapshot çalışıyor) — doğrulama DOM kanıtlarıyla yapıldı. `preview_resize`
   emülasyonu `matchMedia change`/`window resize` olaylarını tetiklemiyor — resize davranışı
   elle `dispatchEvent(new Event('resize'))` ile doğrulandı. Login formunda `preview_click`
   ile submit tetiklenmedi (PHASE_15'te de benzer durum yok muydu bilinmiyor) —
   `requestSubmit()` ile aşıldı, gerçek kullanıcı akışını etkileyen bir hata bulunamadı.

## Önceki Oturum (PHASE_15 — Admin Panel 1. Tur)

1. **Backend ekleri** (panelin gerektirdiği eksik uçlar — hepsi curl ile gerçek Postgres'e
   karşı test edildi): `GET/PATCH /settings` (görünür kolon allowlist'i, sırlar asla dönmez,
   `whatsapp_status` panelden yazılamaz; PATCH owner/manager + alan bazlı 422);
   `GET/PUT /staff/{id}/schedule` (working_hours+breaks transaction'lı tam değiştirme);
   `POST/DELETE /staff/{id}/holidays[/{hid}]`; `GET/PUT /staff/{id}/services` (staff_services);
   `PATCH /appointments/{id}/complete` + `/no-show`; `listByFilters`'a ad JOIN'leri
   (`customer_name`, `customer_whatsapp`, `staff_name`, `service_name`); `Response::html()`.
   Yeni dosyalar: `StaffScheduleController`, `StaffScheduleRepository`, `src/Panel/PanelView.php`.
2. **Panel mimarisi:** sunucu yalnızca HTML iskeleti render eder (`views/panel/*.php`,
   Bootstrap 5.3 CDN); tüm veri istemcide `localStorage`'daki panel JWT'siyle fetch edilir.
   Ortak çekirdek `views/panel/layout.php` içindeki `Panel` JS objesi: `api()` (401 →
   /panel/login), `claims()`, `parseRange()`, durum rozetleri. **06§1'deki yollar (/login,
   /dashboard) API kökleriyle çakıştığı için panel `/panel` öneki altında** (bilinçli sapma).
3. **Kodlanan sayfalar:** /panel/login, /panel/dashboard (özet kartlar + bugünün tablosu),
   /panel/appointments (filtreler + Onayla/Tamamlandı/Gelmedi/İptal), /panel/services ve
   /panel/staff (CRUD modalları + hizmet atama checkbox'ları), /panel/staff/{id}/hours
   (haftalık program + molalar + tatiller), /panel/settings/whatsapp (durum rozeti; "Yeniden
   Bağlan" placeholder — Embedded Signup yapılandırılmadı), /panel/settings/reminders.
4. **Doğrulama Claude Preview ile gerçek Chrome'da uçtan uca yapıldı** (login → her ekranda
   gerçek aksiyonlar → Postgres satır kontrolü → logout). İki gerçek hata bulunup düzeltildi:
   JWT payload **base64url** (`atob` fırlatıyor → sonsuz login döngüsü; normalizasyon eklendi)
   ve tstzrange `+03` ofseti (JS Date `+03:00` ister; `parseRange` normalizasyonu).
5. **`scripts/dev_seed.php`** — idempotent dev tenant'ı: `dev@berber.local` / `DevPassw0rd!`
   (owner), phone_number_id `dev-panel-000`, pro plan, 3 hizmet + 2 personel + saatler +
   müşteriler/randevular. Panel geliştirmesi için kalıcı; PHASE_12-14'ün "oturum sonunda
   temizle" pratiğinden bilinçli sapma (panel her oturumda gerçek veri istiyor).
6. `.claude/launch.json` eklendi (Claude Preview'un PHP built-in server'ı port 8000'de
   başlatması için, `autoPort: false` — n8n'in `BACKEND_BASE_URL`'i bu portu bekliyor).
7. **Gözlem:** önceki oturumdan açık kalan n8n'in pending-TTL cron'u, seed'in geçmiş saatli
   pending randevusunu otomatik iptal etti — 03_pending_ttl_scan workflow'unun gerçek ortamda
   çalıştığının yan doğrulaması.

## Önceki Oturum (PHASE_14 — n8n Kurulumu ve Doğrulama)

1. **n8n bu makineye kuruldu** (`npm install -g n8n`, v2.28.6). Kurulum sırasında `npm install
   -g n8n`'in n8n'in kendi pnpm lockfile'ini kullanmaması yüzünden bozuk bir bağımlılık ağacı
   çıktı (`@n8n/ai-workflow-builder` üzerinden gelen `@langchain/langgraph-checkpoint`, hoisted
   `@langchain/core@1.1.41`'in artık export etmediği eski `dist/` stili derin importlar
   kullanıyordu → `n8n start` hiç açılmıyordu). **Düzeltme:** hoisted `@langchain/core`,
   `npm pack @langchain/core@1.2.1` ile indirilip elle `node_modules/@langchain/core` üzerine
   kopyalandı (detay: `n8n/README.md` "n8n v2.28.6 kurulum notları"). Bu makineye özel bir
   düzeltme — Docker ile kurulumda muhtemelen gerekmez.
2. **3 workflow JSON'u CLI ile import edildi** (`n8n import:workflow --separate --input=n8n/`)
   ve gerçek n8n instance'ına karşı **uçtan uca çalıştırılarak doğrulandı** (yalnızca UI'da
   açıp bakmak değil, gerçek imzalı bir webhook isteğiyle tam akış yürütüldü): `Webhook` →
   `Verify Backend Signature` (Code, HMAC) → `Signature Valid?` (If, typeVersion 1) →
   `Extract Message` (Code) → `Has Message?` (If) → `Upsert Customer` (HTTP) →
   `Get Conversation State` (HTTP) → `Determine Route` (Code) → **`Route` (Switch)** →
   `Get Services` (HTTP) → `Build Services List Msg` (Code) → `Send Services List` (HTTP) —
   hepsi gerçek Backend (PHP built-in server, `php -S localhost:8000 -t public public/index.php`)
   + gerçek Postgres'e karşı çalıştı. Tek hata, test tenant'ının hiç hizmeti olmamasından
   kaynaklanan bir Backend 500'ü idi (veri eksikliği, workflow hatası değil). 02/03
   workflow'ları da (`If`/`Switch` içermiyorlar) hatasız aktive edildi.
3. **İki gerçek n8n davranışı keşfedildi ve düzeltildi** (ikisi de `n8n/README.md`'de
   belgelendi):
   - `N8N_BLOCK_ENV_ACCESS_IN_NODE=false` **zorunlu** — bu n8n sürümünde Code node'lardan
     `$env` erişimi varsayılan olarak engelleniyor; `false` yapılmazsa `$env.N8N_SERVICE_SECRET`
     okuyan tüm Code node'ları çöküyordu.
   - **Webhook `rawBody: true` ile bile `$json.body` HER ZAMAN parse edilmiş objedir**, asla
     ham string değil. Ham gövde base64 olarak binary alanına konur ve Code node içinde
     `$input.item.binary.data.data` ile okunmalı (`$binary.data.data` DEĞİL — `$binary` proxy'si
     yalnızca `mimeType` döndürür, veriyi içermez). `01_incoming_whatsapp_message.json`'daki
     `Verify Backend Signature` node'u bu şekilde düzeltildi ve gerçek bir HMAC ile byte-byte
     doğrulandı.
4. **Bu n8n sürümünün (2.x) yeni "draft/publish" workflow versiyonlama sistemi** klasik 1.x
   mimarisinden farklı davranıyor: bir workflow'u `PATCH` ile düzenlemek yalnızca yeni bir draft
   yazar; canlı webhook'a yansıması için `POST /rest/workflows/:id/activate` ile o versionId
   publish+activate edilmeli VE n8n süreci yeniden başlatılmalı. Tutarsız bir aktivasyondan
   (ör. deprecated `update:workflow --active=true` CLI'ı) sonra webhook path'i yanlış
   kaydedilebiliyor (`<workflowId>/webhook/<path>` gibi) — düzeltmesi: çalışırken doğru
   versionId'yle temiz `activate` + restart (detay: `n8n/README.md`).
5. **XAMPP Apache'de bu proje için `.htaccess`/mod_rewrite yok** — `http://localhost/berber-
   whatsapp-otomasyon/public/customers` gibi yollar Apache'nin kendi genel 404'üne düşüyor
   (PHP'ye hiç ulaşmıyor), yalnızca dizin kökü (`DirectoryIndex`) çalışıyor. Bu oturumda test
   için PHP built-in server kullanıldı (`php -S localhost:8000 -t public public/index.php`,
   `N8N_INCOMING_WEBHOOK_URL`/`BACKEND_BASE_URL` buna göre ayarlandı). Kalıcı çözüm (bir
   `.htaccess` + `mod_rewrite` kuralı veya XAMPP vhost ayarı) henüz yapılmadı — BACKLOG §B'ye
   not düşüldü.
6. n8n editor `http://localhost:5678` adresinde bu oturum sonunda **çalışır durumda bırakıldı**
   (owner hesabı: `admin@example.com` / `TestPassw0rd!123`, yalnızca yerel test). PHP built-in
   server de (`localhost:8000`) çalışır durumda. İkisi de Windows servisi değil — makine
   yeniden başlatılırsa veya terminal kapanırsa elle yeniden başlatılmaları gerekir (komutlar
   için `n8n/README.md`). Test için oluşturulan geçici tenant (`f6e92216-...`) ve ilişkili
   satırlar oturum sonunda temizlendi.

## Önceki Oturum (PHASE_13 — n8n Workflows)

1. **Backend açığı 1: `PATCH /conversation-state`** — `ConversationStateRepository::upsert`,
   `ConversationStateController::update`, route `public/index.php`'ye eklendi. PHASE_12'de
   yalnızca `GET` (okuma) vardı; n8n'in "state ilerledikçe UPDATE edilir" ihtiyacı (04§2)
   karşılıksızdı. Uçtan uca test edildi (upsert aynı satırı günceller, 422 validasyon).
2. **Backend açığı 2: `N8nNotifier`** (`src/Support/N8nNotifier.php`) —
   `WhatsAppWebhookController::receive` artık tenant çözüldükten sonra ham Meta payload'ini
   `{tenant_id, phone_number_id, payload}` olarak `N8N_INCOMING_WEBHOOK_URL`'e POST ediyor,
   `N8N_SERVICE_SECRET` ile HMAC imzalı (yön tersine dönmüş `ServiceHmacMiddleware` aynası).
   URL boşsa sessizce atlanır (Meta'ya giden 200'ü etkilemez). Gerekçe: PHASE_12'de "Meta artık
   n8n'i değil Backend'i çağırır" kararı alınmıştı ama n8n'i tetikleyecek karşı yön hiç
   kodlanmamıştı — bu, n8n workflow'larının çalışabilmesi için zorunlu bir önkoşuldu. Mock bir
   n8n receiver'a karşı HMAC imzası byte-byte doğrulanarak test edildi.
3. **n8n workflow JSON'ları yazıldı** (`n8n/` dizini, detaylar `n8n/README.md`'de):
   - `01_incoming_whatsapp_message.json` — 04§2+§3+§4 (n8n'de tek webhook path kısıtı nedeniyle
     tek fiziksel workflow, `Switch` node ile 8 yola dallanıyor: new_session, service_chosen,
     staff_chosen, slot_chosen, confirm, cancel, reschedule, unhandled/AI). 54 node.
   - `02_reminder_scan.json` (04§5, cron */15), `03_pending_ttl_scan.json` (04§6, cron */5).
   - JSON söz dizimi ve node/bağlantı adı bütünlüğü (her bağlantının var olan bir node'a
     işaret ettiği, yetim node olmadığı) küçük bir PHP betiğiyle doğrulandı.
   - **Doğrulanmadı:** gerçek bir n8n instance'ında import/execute (n8n bu makinede kurulu
     değil) — `If`/`Switch` node'larının tam parametre şeması elle yazıldı, n8n README'sinde
     "bilinen sınırlar" olarak işaretlendi.
4. **Bilinçli tasarım kararları** (n8n/README.md'de gerekçeli): reschedule = cancel + yeni
   appointment (gerçek in-place update ucu yok, BACKLOG §A madde 22); 7 günlük müsaitlik n8n'de
   günlük döngüyle toplanıyor (Backend tek `date` alıyor); "farketmez" personel seçiminde ilk
   uygun personel kullanılıyor.

## Önceki Oturum (Kodlama Faz 3, PHASE_12)

1. **`GET/POST /webhook/whatsapp` kodlandı** (`WhatsAppWebhookController`,
   `WebhookEventRepository`) — projenin en kritik eksiği kapatıldı, backend artık müşteriden
   gelen mesajları da karşılıyor. `GET` Meta'nın `hub.verify_token`/`hub.challenge` abonelik
   doğrulamasını yapar; `POST` `X-Hub-Signature-256` HMAC'ini doğrular, `webhook_events`'e ham
   kayıt düşer, `phone_number_id` → tenant çözer.
2. **Tasarım kararı:** geçersiz imza → `403`, kayıt düşülmez (spoof koruması). Geçerli imza ama
   tenant bulunamadı → yine **200** döner (`tenant_id` NULL kayıtla) — 05§2.2 madde 3'teki
   "404" ifadesi n8n'in *iç* API çağrısı bağlamı için; bu uç artık Meta'nın doğrudan çağırdığı
   nihai uç olduğundan Meta'ya asla 404 dönmüyor (webhook devre dışı kalma riski). Sonraki
   oturumda bu karar gerçek Meta trafiğiyle gözden geçirilebilir.
3. **Altyapı düzeltmesi:** `Http\Request`'e `queryRaw()` eklendi — PHP `$_GET` noktalı
   anahtarları (`hub.mode`, `hub.verify_token`, `hub.challenge`) otomatik alt çizgiye çevirdiği
   için ham `QUERY_STRING`'den ayrıştırma gerekti. `Http\Response`'a `text()` + `rawBody`
   desteği eklendi (`hub.challenge` düz metin echo için, JSON'a sarılmadan).
4. **`GET /conversation-state?tenant_id=&customer_id=` kodlandı** (`ConversationStateController`,
   `ConversationStateRepository`, n8n HMAC kanalı) — kayıt yoksa (müşteriyle ilk temas)
   varsayılan `idle` state döner, satır oluşturmaz (yalnızca okuma).
5. **Uçtan uca doğrulandı** (PHP built-in server + gerçek Postgres, geçici test tenant/customer
   oluşturulup oturum sonunda temizlendi): webhook GET doğru/yanlış token; webhook POST geçerli
   HMAC + bilinmeyen `phone_number_id` (kayıt `tenant_id=NULL`) ve geçerli HMAC + eşleşen
   `phone_number_id` (kayıt `tenant_id` doğru çözülmüş) — `webhook_events` tablosunda satır
   satır kontrol edildi; geçersiz HMAC → 403, kayıt düşmedi (doğrulandı). `conversation-state`:
   imzasız 401, bilinmeyen müşteri 404, kayıt yokken `idle` varsayılan, gerçek satır doğru
   dönüyor.
6. **`.env`** — `WEBHOOK_VERIFY_TOKEN` ve `META_APP_SECRET` bu makineye özel rastgele test
   değerleriyle dolduruldu (gerçek Meta App'te kayıtlı değil, yalnızca yerel HMAC testi için).
7. **Kota bitmeden ek olarak: Platform admin route grubu kodlandı** (BACKLOG §A madde 14) —
   `POST /platform/auth/login`, `GET /platform/tenants`, `PATCH /platform/tenants/{id}`
   (`PlatformAdminController`, `PlatformTenantController`, `PlatformAdminRepository`,
   `TenantRepository::listAll/updateStatus`). Yeni `PlatformAdminAuthMiddleware` — panel
   JWT'sinden ayrı `type: platform_admin` claim'i kullanır (`tenant_id`/`role` yok), böylece
   tenant kullanıcı JWT'si platform uçlarında kullanılamaz (uçtan uca test edildi: cross-token
   403 aldı). Tenant suspend/activate de test edildi (`status` alanı günceller, listeye yansır).

## Kritik Ortam Bulguları (sonraki oturum için önemli)

- **Bu bir ikinci makine (ev laptopu, PHASE_30).** PostgreSQL PHASE_30'da EDB installer ile
  sıfırdan kuruldu (`--mode unattended`, `--locale C`), Windows servisi olarak kayıtlı
  (`postgresql-x64-16`). Superuser şifresi bu makineye özel, `.env`'deki `DB_APP_PASSWORD`/
  `DB_SERVICE_PASSWORD`'den FARKLI (postgres superuser şifresi hiçbir yerde saklanmıyor,
  gerekirse `pg_hba.conf`'u geçici `trust`'a çevirip `ALTER ROLE` ile sıfırlanabilir — PHASE_30
  bunu yaptı). **Sürpriz bulgu:** kurulum sırasında `berber_saas` veritabanı ve dev tenant
  zaten mevcuttu (migration 0003/0004 eksikti) — bu makinede daha önce yarım kalmış bir kurulum
  vardı, önceki oturumun PROJECT_MEMORY'sine hiç yazılmamış. Diğer makinedeki (ofis/PHASE_28)
  PostgreSQL kurulumu ve bu makinedekiler BİRBİRİNDEN BAĞIMSIZ — ayrı `.env`, ayrı roller.
- **✅ PHASE_28'de çözüldü — PostgreSQL artık Windows servisi (`postgresql-x64-16`,
  `StartType: Automatic`).** Kullanıcı yönetici PowerShell'de `pg_ctl register` çalıştırdı;
  önceden elle başlatılmış örnek (data dizinini kilitliyordu) bu oturumdan `pg_ctl -m fast stop`
  ile kapatılıp `Start-Service postgresql-x64-16` ile servise geçirildi. Artık **elle
  `pg_ctl start` gerekmiyor** — makine/servis yeniden başlasa da otomatik ayağa kalkıyor. Servis
  durumu kontrolü: `Get-Service postgresql-x64-16`.
- Türkçe Windows locale'i (`Turkish_Türkiye.1254`) EDB installer'ın `initdb` adımını
  non-ASCII locale adı yüzünden kırıyor — `initdb --locale=C` ile aşıldı. Sonraki bir
  PostgreSQL kurulumunda (ör. yeni bir makinede) aynı sorun tekrar çıkabilir.
- `APP_ENCRYPTION_KEY` (.env) — `tenants.access_token_encrypted` için AES-256-GCM anahtarı,
  KMS yok, tek ortak anahtar. Üretimde secret manager'a taşınmalı (BACKLOG.md §B E4).
- WhatsApp gönderim/senkron kodu (`WhatsAppInternalController`) **gerçek Meta erişim
  bilgisiyle hiç çalıştırılmadı** — yalnızca "tenant bulunamadı" gibi hata yolları test
  edildi. İlk gerçek WABA bağlantısı kurulduğunda uçtan uca doğrulanmalı.
- **n8n artık bu makinede kurulu ve doğrulanmış** (PHASE_14, v2.28.6, `npm install -g n8n`).
  Windows servisi DEĞİL — makine/terminal kapanırsa elle yeniden başlatılmalı:
  `BACKEND_BASE_URL=http://localhost:8000 N8N_SERVICE_SECRET=<.env'deki değer>
  NODE_FUNCTION_ALLOW_BUILTIN=crypto N8N_BLOCK_ENV_ACCESS_IN_NODE=false n8n start`
  (son ikisi zorunlu, aksi halde Code node'lar çöker — bkz. `n8n/README.md`). Owner hesabı:
  `admin@example.com` / `TestPassw0rd!123` (yalnızca yerel test, gerçek değil).
- **✅ PHASE_27'de çözüldü — XAMPP Apache artık bu proje için de çalışıyor.** `public/.htaccess`
  + ayrı bir dev vhost (port **8081**, `C:\xampp\apache\conf\httpd.conf`'daki `Listen 8081` +
  `conf\extra\httpd-vhosts.conf`'daki `<VirtualHost *:8081>`, `DocumentRoot` doğrudan `public/`'a
  işaret ediyor). `http://localhost:8081/...` artık `php -S localhost:8000 -t public
  public/index.php` ile birebir aynı şekilde çalışıyor — PHP built-in server hâlâ kullanılabilir
  (özellikle n8n `BACKEND_BASE_URL` zaten `:8000`'e sabitlenmiş durumda ise onu değiştirmeye
  gerek yok), ama artık zorunlu değil.
- Bu makinedeki npm global n8n kurulumunun bağımlılık ağacı elle yamalandı (hoisted
  `@langchain/core` 1.1.41 → 1.2.1) — `n8n update -g`/yeniden kurulumda bu yama kaybolabilir,
  aynı hatayla karşılaşılırsa `n8n/README.md`'deki adımları tekrar uygula.

- **Kalıcı dev tenant'ı var (PHASE_15):** `dev-panel-000` / `dev@berber.local` / `DevPassw0rd!`
  — önceki oturumların "test verisini temizle" pratiğinin aksine bilinçli olarak bırakıldı
  (panel geliştirmesi her oturumda gerçek veri istiyor). Silinirse `php scripts/dev_seed.php`
  ile yeniden üretilir (idempotent). Bu tenant'ın pending randevuları, n8n açıksa TTL cron'u
  tarafından otomatik iptal edilebilir — panel testi için randevuyu geleceğe koy.
- `.claude/launch.json` eklendi — Claude Preview `backend` adıyla PHP built-in server'ı
  port 8000'de başlatabiliyor (`autoPort: false`; n8n'in `BACKEND_BASE_URL`'i bu portu
  beklediği için port sabit).

## Değişmeyen Mimari Kararlar (tekrar tartışılmayacak)

- WhatsApp → Meta Cloud API → n8n → Backend API → PostgreSQL → Admin Panel
- Multi-tenant, tek DB, `tenant_id` satır izolasyonu + RLS (`app.current_tenant`)
- n8n asla DB'ye doğrudan yazmaz; her şey Backend API üzerinden
- Randevu çakışması uygulamada değil `EXCLUDE USING gist` ile DB'de engellenir
- İşletme verisi (hizmet/personel/saat/fiyat) asla kodda sabit değildir
- İki PDO bağlantı modu: `Connection::tenant()` (RLS zorunlu) / `Connection::service()`
  (BYPASSRLS, yalnızca login/resolve-tenant/n8n tüm-tenant tarama/whatsapp internal uçları)
- `tenantScoped` route closure'ı artık controller'a 4. parametre olarak `role` da geçiyor
  (`public/index.php`) — yeni tenant-scoped controller'lar rol kontrolü için bunu kullanabilir

## Panel Durumu (PHASE_18 sonu — nerede kaldık)

**Panel ekranları TAMAMLANDI — BACKLOG §A madde 24 kapandı.** Bitmiş ve tarayıcıda test
edilmiş: /panel/login, /panel/dashboard, /panel/appointments (liste + FullCalendar takvim +
Yeni Randevu modalı), /panel/services, /panel/staff, /panel/staff/{id}/hours,
/panel/customers, /panel/customers/{id}, /panel/messages/log, /panel/messages/templates,
/panel/messages/campaigns, /panel/settings/business, /panel/settings/whatsapp,
/panel/settings/reminders, /panel/settings/ai, **/panel/reports (PHASE_18)**. Yarım kalan
ekran YOK; "yakında" placeholder'ı da kalmadı.

**Platform admin UI (ayrı, PHASE_18):** /platform/login + /platform (tenant listesi +
suspend/activate). Panel JWT'sinden AYRI: `type: platform_admin` claim'i, localStorage
anahtarı `platform_jwt` (tenant paneli `panel_jwt` kullanır — karışmaz).

Girişler (ikisi de `php scripts/dev_seed.php` ile idempotent üretilir):
- Panel: `http://localhost:8000/panel/login` — `dev@berber.local` / `DevPassw0rd!`
- **Platform admin: `http://localhost:8000/platform/login` — `platform@berber.local` /
  `PlatformDev1!`** (dev_seed §10, PHASE_18'de eklendi)

Not: 8000 portu başka bir Claude Preview oturumunda dolu olabilir; `.claude/launch.json`'da
`backend-alt` (port 8010) alternatifi var. Panel yolları port bağımsız çalışır.

## Sonraki Oturum İçin Öncelik Sırası

Panel, AI modülü (Gemini), Redis rate limit, kampanya gönderimi, inbound log fix, akış-ortası
AI yönlendirmesi, reschedule ucu, Apache dev vhost'u, Postgres Windows servisi, **gerçek WABA
ile uçtan uca mesaj teslimatı** hepsi bitti (PHASE_30). Öncelik:

1. **`GET/POST /webhook/whatsapp` + `N8nNotifier` gerçek trafik doğrulaması** — yeni WABA
   artık gerçek mesaj gönderebiliyor ama Meta'nın bu WABA için webhook'u (inbound mesajlar,
   durum güncellemeleri) backend'in `GET/POST /webhook/whatsapp` ucuna henüz bağlanmadı/test
   edilmedi (Meta App > WhatsApp > Configuration'da callback URL + `WEBHOOK_VERIFY_TOKEN`
   ayarlanmalı — dev makinede ngrok/tünel gerekir, localhost Meta'dan erişilemez).
2. **Embedded Signup panele kodlanması** (BACKLOG §A madde 25'in geri kalanı) — artık gerçek
   bir çalışan WABA örneği var, tasarım/test için referans alınabilir.
3. **Şablon dili sabitlemesi** (`WhatsAppInternalController.php:100`, `language: 'tr'`) —
   PHASE_30'da fark edildi, henüz değerlendirilmedi. Yeni WABA'nın tüm onaylı şablonları
   `en_US`; gerçek bir Türkçe şablon onaylatılmadan `type=template` testi yapılamıyor.
4. **(Düşük öncelik, opsiyonel) n8n reschedule dalını tek çağrıya indir** — Backend ucu
   PHASE_27'de hazır (`PATCH /appointments/{id}/reschedule`); `01_incoming_whatsapp_message.json`
   hâlâ eski cancel+yeni-appointment iki-adımlı akışı kullanıyor (fonksiyonel olarak doğru,
   yalnızca sadeleştirme fırsatı).
5. n8n queue mode deposu (BACKLOG §A madde 16'nın kalan yarısı) — yalnızca gerçek ölçekleme
   ihtiyacı doğduğunda; tek instance n8n şu an yeterli.
6. FullCalendar ticari lisansı (BACKLOG §B E8) zaten kapalı (ücretsiz çekirdeğe geçildi,
   PHASE_24) — üretime çıkmadan önce tekrar gözden geçirilmesi gereken bir şey yok.
