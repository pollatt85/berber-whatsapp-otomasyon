# PROJECT_MEMORY

Bu dosya, Development Pack'in "Claude her oturumda kaldığı yerden devam edecek" ilkesini
uygulamak için tutulur. `00_Master_Roadmap.md` fazların **tasarım** durumunu, bu dosya ise
**kodlama** durumunu ve oturumlar arası kararları takip eder. `CHANGELOG.md` sürüm geçmişi,
bu dosya ise "şu an nerede kaldık" anlık fotoğrafıdır — her oturum sonunda güncellenir.

## Genel Durum

- Dokümantasyon fazı (00-09): ✅ tamamlandı (9/9).
- **Kodlama fazı: ✅ PHASE_32 itibarıyla BACKLOG'daki TÜM açık maddeler kapandı** (şablon dili,
  tier uyarı alanı, Embedded Signup, n8n tek-çağrı reschedule, queue mode kararı). **PHASE_33'te
  Meta ES config ID oluşturuldu + FB.login `extras` düzeltmesi yapıldı** (detay: "Sonraki Oturum
  İçin Öncelik Sırası" madde 1). Kalan tek adım: pilot fazı (08_Test_Pilot.md) — gerçek
  işletmeyle saha çalışması, kod tarafında bekleyen yok.
- **PHASE_34-35'te WhatsApp Flows (randevu formu) kodlandı** (`FlowCrypto`,
  `WhatsAppFlowController`, `WhatsAppNotifier`, `whatsapp-flow/booking_flow.json`; Meta'da
  Flow "Randevu Al (Berber)" `META_FLOW_ID=1602331731897517`, DRAFT). **PHASE_36'da Flow
  publish engelinin kök nedeni çözüldü:** ödeme yöntemi engeli (`141006`) kullanıcının kartı
  WABA'ya bağlamasıyla KALKTI (WABA artık `AVAILABLE`); Flow publish ise Meta'nın integrity
  kapısına takılı (verification VEYA ~1000 kaliteli konuşma/30g — test numarası 5 alıcı
  sınırı nedeniyle dev hesabında aşılamaz). **ÜRÜN ENGELİ DEĞİL** — Flow, pilot işletmenin
  kendi doğrulanmış portföyünde yayınlanacak; üretim yolu liste akışıdır. Kod GitHub'da:
  `https://github.com/pollatt85/berber-whatsapp-otomasyon`.
- Önceki durum özeti: 🟡 devam ediyordu. Backend + n8n + panel + AI (Gemini) + Redis rate limit +
  kampanya gönderimi + inbound log fix + akış-ortası AI yönlendirmesi + reschedule ucu +
  Apache dev vhost'u + Postgres Windows servisi hepsi tamamlandı (PHASE_14-28). **PHASE_30'da
  eski Meta hesap kilidi tamamen atlatıldı**: kilitli WABA (`Bomonti Berber` portföyü) terk
  edildi, YENİ bir portföy/App/WABA (`Test Berber 2` / `BerberApp` / WABA
  `1359511886136495`, phone_number_id `1147876331751351`) üzerinden **backend'in kendi
  `/internal/whatsapp/send` ucu gerçek bir WhatsApp mesajını gerçek bir telefona uçtan uca
  teslim etti** (kullanıcı tarafından WhatsApp ekran görüntüsüyle doğrulandı). **PHASE_31'de
  webhook bağlantısı tamamlandı:** ngrok tüneli + Meta App'te callback URL/verify token +
  WABA'nın app'e abone edilmesi (`subscribed_apps` — Embedded Signup dışı bağlantıların atladığı
  kritik bir adım) + n8n restart edilerek **gerçek bir gelen WhatsApp mesajı uçtan uca menüye
  kadar doğrulandı** (kullanıcı telefonunda gerçek yanıtı gördü). Kalan açık: Embedded Signup
  panele kodlanması (madde 25'in geri kalanı, artık `subscribed_apps` adımını da içermeli) +
  n8n queue mode (madde 16, yalnızca ölçekleme aşamasında, dokunulmadı) + backend'in şablon
  dili sabit `tr` olması (yalnızca `en_US` onaylı şablonlarla çakışıyor, aşağıda not).

## Bu Oturumda Yapılanlar (PHASE_36 — Flow publish engeli kök neden analizi + ödeme düzeltmesi + GitHub push)

Hedef: WhatsApp Flows'un Meta tarafında gönderilememesi (`#139000 Blocked by Integrity`)
sorununu derinlemesine araştırıp çözmek. Sonuç: **iki somut engel bulundu, biri tamamen
çözüldü (ödeme), biri dev hesabında yapısal olarak aşılamaz ama ÜRÜN ENGELİ DEĞİL** (detay
madde 4). Ayrıca PHASE_32-35 işleri commit'lenip GitHub'a push edildi.

1. **GitHub push tamamlandı** — repo: `https://github.com/pollatt85/berber-whatsapp-otomasyon`.
   PHASE_32-35 değişiklikleri `49fd823` olarak commit'lendi. Uzak repoda İLGİSİZ bir geçmiş
   vardı (`2d7fca8` "Initial commit PHASE_1-29" — muhtemelen diğer makineden atılmış, bu
   makinenin `0f56e57` ilk commit'iyle ortak atası yok). `--allow-unrelated-histories` merge
   (`57bd502`, tüm çakışmalar `--ours` ile bu makinenin daha güncel sürümü lehine çözüldü)
   sonrası normal push başarılı — force gerekmedi. `gh` CLI'da oturum açık DEĞİL; remote
   HTTPS ile çalışıyor.
2. **Meta ödeme engeli (141006) ÇÖZÜLDÜ** — Flow'dan bağımsız olarak WABA
   `can_send_message: BLOCKED` durumundaydı: "There is an error with the payment method".
   Kullanıcı MasterCard ****2744'ü Business Suite > Faturalar ve ödemeler > WhatsApp Business
   hesapları sekmesinden **WABA'ya bağladı** (portföye ekli olması yetmiyor, WhatsApp hesabına
   ayrıca bağlanmalı). Para birimi **USD** (TL, WhatsApp faturalandırmasında desteklenmiyor;
   seçim kalıcı/değiştirilemez), vergi bilgileri şahıs (Cemal POLAT), KDV numarası boş
   (mükellef değil — Meta KDV'yi faturaya kendisi ekler), ticari amaç "Evet".
   **Doğrulandı:** health_status'ta WABA artık `AVAILABLE` — işletme başlatmalı mesajların
   (şablon/hatırlatma/kampanya) önü açıldı.
3. **İşletme profili + Business Verification denemesi** — Business Info şahıs bilgileriyle
   dolduruldu (işletme adı = Cemal POLAT, ev adresi, kişisel telefon). Verification sihirbazı
   girildi ama Meta otomatik kayıt eşleşmesi bulamayınca 4 belge türünden birini istedi
   (işletme banka özeti / sicil-ruhsat / vergi levhası / kuruluş belgesi) — kullanıcıda resmi
   işletme kaydı olmadığı için İPTAL edildi. Bu yol ancak esnaf/şirket kaydı açılırsa mümkün.
4. **Flow publish engelinin kök nedeni netleşti (`139000` / subcode `4233020`):** Meta'nın
   integrity ön koşul kapısı — Flow yayınlamak için **business verification VEYA "yüksek mesaj
   kalitesi"** (~1000 kaliteli konuşma/30 gün, WhatsApp Manager Flow ekranındaki kart) şart.
   Meta topluluk forumundaki resmi yanıta göre teknik atlatması yok. **Kritik tespit: test
   numarası (+1 555-190-4459) en fazla 5 kayıtlı alıcıya mesaj atabildiği için kalite yolu bu
   dev hesapta matematiksel olarak imkansız.** `mode: "draft"` parametresiyle gönderim de aynı
   engele takılıyor (denendi). **ÜRÜN ENGELİ DEĞİL:** SaaS modelinde her tenant Embedded
   Signup ile kendi WABA'sını kendi portföyünde bağlıyor; Flow, pilot/gerçek işletmenin
   (vergi levhası olan) doğrulanmış portföyünde yayınlanacak. Dev hesabında Flow'u zorlamak
   anlamsız — karar: liste akışı üretim yolu olarak kalır, Flow kodu hazır bekler.
5. **Flow endpoint doğrulandı (131000'in nedeni backend'in kapalı olmasıydı):** backend
   (port 8000) kapalıydı, ngrok 502 dönüyordu → Meta health check'i endpoint'e hiç
   ulaşamıyordu. Backend başlatıldı; **Meta'nın şifreli ping protokolünün birebir simülasyonu**
   (public key `secrets/whatsapp_flow_public.pem` ile RSA-OAEP-SHA256 + AES-128-GCM, yanıt
   bit-tersine-çevrilmiş IV ile çözülür) hem lokal hem ngrok üzerinden `{"data":{"status":
   "active"}}` döndü — kod tarafı kusursuz. `endpoint_uri` Meta'da doğru kayıtlı.
6. **Altın teşhis yöntemi (gelecek oturumlar için):** `GET /{waba_id|flow_id|phone_number_id}
   ?fields=health_status` — `#139000` şemsiye hatasının altındaki gerçek engelleri entity
   bazında (`BLOCKED`/`LIMITED`/`AVAILABLE` + `error_code`) açıkça listeler. Bu oturumda
   `141006` (ödeme) + `131000` (endpoint) + `141010` (verification, yalnızca LIMITED — engel
   değil!) bu yolla bulundu. Business verification'ın Flow publish DIŞINDA hiçbir şeyi
   engellemediği (sadece 250/gün limiti) bu çıktıyla kanıtlandı.
7. **Flow'u publish'siz test etme yolu:** WhatsApp Manager Flow Builder'daki **"Çalıştır"**
   önizlemesi endpoint'i gerçekten çağırır (INIT/data_exchange) — gerçek hizmet/personel/slot
   verisiyle uçtan uca test mümkün, publish kapısına takılmaz. (Bu oturumda kullanıcıya
   önerildi, henüz birlikte çalıştırılmadı.)
8. **Ortam:** backend (PHP built-in, 8000) bu oturumda arka planda başlatıldı ve AÇIK
   bırakıldı; ngrok tüneli ayakta. İkisi de servis değil — makine yeniden başlarsa elle
   başlatılmalı (Meta Flow health check'i endpoint'e her an gelebilir, Flow işleri yapılacaksa
   backend açık olmalı).

## Önceki Oturum (PHASE_32 — Kalan tüm BACKLOG maddeleri)

Hedef: kullanıcı talebi "kalan tüm maddeleri bir kerede bitir". Tümü gerçek Postgres + gerçek
Meta WABA + canlı n8n'e karşı doğrulandı; detaylar CHANGELOG PHASE_32'de. Özet:

1. **Şablon dili** — `migrations/0005` (`message_templates.language`), sync Meta'dan dili
   saklar, `send()` istek>DB>'tr' önceliği. **İlk gerçek `type=template` teslimatı:**
   `hello_world` (`en_US`) gerçek telefona `sent` + gerçek `wamid`. Migration, superuser
   şifresi bilinmediği için pg_hba geçici `trust` yöntemiyle (PHASE_30 deseni) uygulandı,
   hemen geri alınıp doğrulandı.
2. **Tier/kalite alanı (m.17)** — `GET /settings/whatsapp/health` + panel kartı; gerçek veriyle
   tarayıcıda doğrulandı (GREEN / TIER_250 / düşük kademe uyarısı).
3. **Embedded Signup (m.25+m.29)** — `POST /settings/whatsapp/connect`: code exchange →
   **`subscribed_apps` otomatik** → şifreli token kaydı (`connectWhatsApp`), 409
   `phone_number_in_use`; panelde FB SDK + `FB.login(config_id)` + popup message event.
   `.env`/`.env.example`'a `META_APP_ID` (4440759482837118) + `META_ES_CONFIG_ID` (boş —
   kullanıcı Meta Dashboard'dan oluşturacak). Sahte code ile gerçek Meta hata yolu test edildi.
4. **n8n tek-çağrı reschedule (m.22)** — cancel+yeni-kayıt yerine `PATCH .../reschedule`;
   REST PATCH+activate ile canlı n8n'e yüklendi (yeni versionId publish edildi), imzalı webhook
   simülasyonuyla iki kez uçtan uca doğrulandı. Müşteriye "tasindi" metni + state idle.
5. **Saat dilimi hatası bulundu ve düzeltildi** — slot `start_time` ofsetsizdi; XAMPP PHP
   `date.timezone=Europe/Berlin` olduğu için randevular 1 saat kayıyordu (create yolu dahil,
   öteden beri). Panel PHASE_17 kararıyla aynı sabit `+03:00` n8n'e eklendi; 14:30 → `14:30+03`
   birebir doğrulandı.
6. **Queue mode kararı (m.16)** — kurulmayacak (tek instance yeterli); geçiş reçetesi
   `n8n/README.md` "Queue mode" bölümüne yazıldı.
7. Test verileri temizlendi (geçici müşteri/randevu/log/state). Ortam: backend 8000 + ngrok +
   n8n bu oturum başında arka planda başlatıldı, açık bırakıldı.

## Önceki Oturum (PHASE_31 — Webhook bağlantısı + n8n ile uçtan uca ilk gerçek gelen mesaj)

Hedef: BACKLOG §A madde 25'in kalan parçası — Meta'nın yeni WABA (`1359511886136495`) için
inbound webhook'unu backend'e bağlamak ve n8n akışını gerçek trafikle doğrulamak (PHASE_30
yalnızca giden mesajı doğrulamıştı).

1. **ngrok tüneli kuruldu** — winget'in kurduğu `Ngrok.Ngrok` paketi (3.3.1) hesabın minimum
   sürüm şartını (3.20.0+) karşılamadığı için `ERR_NGROK_121` ile reddedildi; güncel binary
   (`v3.39.9`) doğrudan `bin.equinox.io`'dan indirilip `C:\Users\Lenovo-Thinkpad-E560\ngrok\`
   altına kuruldu. Kullanıcı kendi ngrok hesabını (Google SSO) açtı, authtoken'ı Claude in
   Chrome ile okundu (hesap oluşturma/şifre girme kurallar gereği kullanıcı tarafından
   yapıldı). Sabit ücretsiz alan adı `https://provider-dislodge-bounce.ngrok-free.dev` →
   `localhost:8000` yönlendiriyor.
2. **`META_APP_SECRET` dolduruldu** — önceki oturumlardan beri `.env`'de boştu (`webhook`
   POST imza doğrulaması `Env::required('META_APP_SECRET')` ile çöküyordu). BerberApp'in
   Meta Dashboard'daki Settings > Basic sayfasından (kullanıcı kendi Facebook şifresini
   tekrar onayladı) gerçek App Secret alınıp `.env`'e yazıldı.
3. **Webhook GET/POST doğrulama tünel üzerinden test edildi** — `hub.challenge` echo edildi
   (200), doğru HMAC imzalı POST 200 (yanlış imza 403). PHP built-in server .env'i her
   istekte yeniden okuduğu için restart gerekmedi.
4. **Meta App Dashboard'da webhook yapılandırıldı** (Step 2. Production setup > Configure
   Webhooks): Callback URL = ngrok URL + `/webhook/whatsapp`, Verify token = `.env`'deki
   `WEBHOOK_VERIFY_TOKEN`. "Verify and save" başarılı (yeşil tik), `messages` alanı zaten
   otomatik abone edilmiş durumdaydı.
5. **Kritik bulgu — WABA app'e abone değildi:** callback URL/verify token App düzeyinde
   doğru ayarlanmasına rağmen kullanıcının attığı gerçek test mesajı hiç ulaşmadı (ngrok
   inspector'da yalnızca kendi curl testlerim görünüyordu, Meta'dan gerçek istek yok). Kök
   neden: `GET /{waba-id}/subscribed_apps` (System User token ile) WABA'nın yalnızca Meta'nın
   kendi varsayılan `WA DevX Webhook Events 1P App`'ine (id `2202427980234937`) abone
   olduğunu, **BerberApp'e (`4440759482837118`) hiç abone olmadığını** gösterdi — bu, WABA'nın
   Embedded Signup akışı yerine doğrudan Business Settings > System User ile bağlanmasının
   (PHASE_30) bir yan etkisiydi. **Düzeltme:** `POST /{waba-id}/subscribed_apps` (aynı System
   User token'ıyla) çağrılıp BerberApp WABA'ya abone edildi (`{"success":true}`); tek seferlik
   PHP scriptleriyle yapıldı (`TokenCipher::decrypt` + Graph API), scriptler iş bitince
   silindi. Bu adım BACKLOG'a **madde 29** olarak eklendi (aşağıya not düşüldü) çünkü Embedded
   Signup panele kodlanırken bu abonelik adımı da otomatik yapılmalı.
6. **n8n bu makinede yeniden başlatıldı** (`n8n start`, `BACKEND_BASE_URL`/`N8N_SERVICE_SECRET`/
   `NODE_FUNCTION_ALLOW_BUILTIN=crypto`/`N8N_BLOCK_ENV_ACCESS_IN_NODE=false` ile, PHASE_14
   deseni) — workflow'lar SQLite'ta kalıcı olduğu için `01_incoming...` hâlâ aktifti
   (webhook path 200 döndü, 404 değil).
7. **Uçtan uca gerçek doğrulama:** kullanıcı gerçek telefonundan işletme test numarasına
   (+1 555-190-4459) art arda iki mesaj gönderdi. İlk mesaj (n8n henüz kapalıyken) yalnızca
   `webhook_events`'e düştü (n8n'e iletilemedi, `N8nNotifier` best-effort sessiz geçti).
   n8n açıldıktan sonraki ikinci mesaj **tam zinciri tetikledi**: Meta → ngrok → `POST
   /webhook/whatsapp` (gerçek `X-Hub-Signature-256`, `facebookexternalua` User-Agent) →
   tenant çözümü → `N8nNotifier` → n8n `Determine Route` (idle → new_session) → `Get
   Services` → gerçek bir WhatsApp **interactive list** mesajı (4 gerçek hizmet, dev tenant
   seed verisi) → `/internal/whatsapp/send` → Meta → **kullanıcının telefonunda gerçekten
   göründü** (kullanıcı doğruladı: "mesaj bana da geldi"). `message_log`'a gerçek `wamid`'li
   `outbound`/`sent` kaydı düştü.
8. **Test verisi temizlendi** (oturum sonu pratiği): gerçek telefon numaralı geçici müşteri
   (`905418255314`), ilişkili `message_log`/`conversation_states` satırları ve bu oturumun
   `webhook_events` kayıtları (`phone_number_id=1147876331751351`) silindi.
9. **Makine durumu (oturum sonunda AÇIK bırakıldı, sonraki oturum için önemli):** ngrok tüneli
   (`provider-dislodge-bounce.ngrok-free.dev`, authtoken makineye kayıtlı — `ngrok config
   add-authtoken` bir daha gerekmez) ve n8n (`localhost:5678`) her ikisi de Windows servisi
   DEĞİL, terminal/işlem kapanırsa elle yeniden başlatılmaları gerekir. **ngrok'un ücretsiz
   sabit alan adı hesaba bağlı olduğu için URL sabit kalır** — makine yeniden başlasa da aynı
   komutla (`ngrok http --domain=provider-dislodge-bounce.ngrok-free.dev 8000`) aynı URL
   geri gelir, Meta tarafında callback URL'i tekrar girmeye gerek yoktur (yalnızca tünel
   düşerse Meta'nın istekleri backend'e ulaşmaz, webhook "reachable değil" görünür).

## Önceki Oturum (PHASE_30 — Ev laptopunda sıfırdan ortam kurulumu + yeni WABA ile gerçek teslimat)

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

**PHASE_32 itibarıyla BACKLOG'daki tüm kod maddeleri kapandı.** Kalanlar kod dışı:

1. **✅ Meta Dashboard'da Embedded Signup config ID oluşturuldu (PHASE_33)** —
   `META_ES_CONFIG_ID=934468236330247` (config adı `berber-whatsapp`, App: BerberApp
   `4440759482837118`), `.env`'e yazıldı. Config: Login variation `General`, Access token
   `User access token`, Permissions `whatsapp_business_management` + `whatsapp_business_messaging`.
   App Domains'e `provider-dislodge-bounce.ngrok-free.dev` + Website platform (aynı Site URL)
   eklendi; Facebook Login for Business > Ayarlar'da `Login with the JavaScript SDK` = Yes +
   Allowed Domains for the JavaScript SDK'ya aynı ngrok domain eklendi (bunlar olmadan
   `FB.login` "JSSDK Seçeneği Açılmadı" / "URL Yüklenemedi" hatası veriyordu).
   **Kod düzeltmesi:** `views/panel/settings_whatsapp.php` `startEmbeddedSignup()` içindeki
   `FB.login` `extras` nesnesine Meta'nın resmi örneğindeki `setup: {}` ve `featureType: ''`
   alanları eklendi (öncesinde yalnızca `sessionInfoVersion: '3'` vardı — eksik alanlar
   WhatsApp'a özel çok adımlı sihirbazı değil, genel Business Login kısayolunu tetikliyordu).
   **Doğrulanan:** popup gerçek Facebook OAuth'a gidiyor, config/domain hataları çözüldü,
   sunucu tarafı `code`'u işlemeye hazır. **Doğrulanamayan (ortam kısıtı):** `WA_EMBEDDED_SIGNUP`
   `FINISH` postMessage'ı — çünkü test eden hesap (Cemal Polat) zaten ilgili WABA'ların
   bulunduğu Business Manager'ın admini, bu yüzden Meta tam WABA-seçim sihirbazını atlayıp
   `status: 'connected'` ile kısa bir yeniden-onay ekranı gösteriyor (gizli modda ve
   entegrasyonlar sıfırlanmış haliyle bile aynı davranış — tarayıcı önbelleği değil, hesap/
   Business Manager ilişkisi kaynaklı). **Tam uçtan uca doğrulama, admin olmayan gerçek bir
   pilot işletme hesabıyla yapılmalı** (madde 2, aşağıda).
2. **Pilot fazı (08_Test_Pilot.md)** — gerçek işletmeyle saha testi; BACKLOG §C pilot hata
   kayıtları bölümü bu fazda dolacak. Öncesinde işletmenin kendi Türkçe şablonlarını Meta'da
   onaylatması gerekir (dil artık DB'den geliyor, kod hazır). Bu faz aynı zamanda Embedded
   Signup'ın WABA-seçim sihirbazının (madde 1) gerçek bir dış kullanıcıyla ilk uçtan uca testi
   olacak. **Flow (randevu formu) da bu fazda açılır:** pilot işletmenin kendi doğrulanmış
   portföyünde Flow yayınlanabilir (PHASE_36 kök neden analizi — dev hesabında publish kapısı
   aşılamaz, bkz. "Bu Oturumda Yapılanlar" madde 4). O güne kadar üretim yolu liste akışıdır;
   Flow'un ekran/endpoint testi Flow Builder "Çalıştır" önizlemesiyle publish'siz yapılabilir
   (backend + ngrok açıkken).
2b. **(İsteğe bağlı, Flow'u dev'de denemek için)** Meta'nın `#139000` şemsiye hatasını teşhis
   etmek gerekirse: `GET /{waba_id|flow_id}?fields=health_status` (System User token'ıyla) —
   entity bazlı gerçek engel listesi. PHASE_36 durumu: WABA `AVAILABLE` (ödeme çözüldü),
   BUSINESS `LIMITED` (verification yok, yalnızca 250/gün limiti — engel değil), FLOW
   `131000` (Meta health check'i publish denemesinde koşuyor; endpoint doğrulandı, backend
   açıkken geçer).
3. **(Ortam notu)** ngrok tüneli + n8n makine/terminal kapanırsa elle yeniden başlatılmalı —
   ngrok authtoken makineye kayıtlı, sabit alan adı (`provider-dislodge-bounce.ngrok-free.dev`)
   aynı komutla geri gelir; n8n env değişkenleri için `n8n/README.md`. Üretim öncesi ayrıca:
   `APP_ENCRYPTION_KEY` KMS'e taşınmalı (§B E4), XAMPP `php.ini date.timezone` bu makinede
   `Europe/Berlin` (n8n/panel sabit +03:00 gönderdiği için işlevsel sorun yok ama
   `Europe/Istanbul` yapılması temiz olur).
