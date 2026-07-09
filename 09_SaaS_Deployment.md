# 09 — SaaS Deployment

Bu doküman, 01-08'de tasarlanan sistemin pilot sonrası genel kullanıma nasıl açılacağını
tanımlar. Mimari/API/akış detayları tekrar edilmez; burada yalnızca yayın topolojisi,
ölçekleme, lisanslama (plan) modeli ve abonelik/faturalama süreci var.

## 1. Yayın Topolojisi

- Ortamlar: `staging` (Meta test numarası + sandbox billing) ve `production`, birbirinden
  tamamen ayrı PostgreSQL ve n8n instance'ı
- Konteynerler: `nginx + php-fpm (Backend API)`, `postgresql`, `n8n` (queue mode, bkz. §2),
  `redis` (n8n queue + rate limit sayaçları için, 03/07'de planlanan rate limit'in
  gerçek deposu)
- Sırlar (Meta App Secret, tenant `access_token_encrypted` şifreleme anahtarı, Claude API
  anahtarı, billing sağlayıcı anahtarı) konteyner env değişkeni olarak değil, secret
  manager'dan enjekte edilir; kod/deploy dosyasında asla düz metin bulunmaz
- Migration'lar (01-08 boyunca biriken tüm "tespit edilen ama eklenmemiş" madde listesi,
  bkz. §6) tek bir uygulama başlangıcı migration turunda toplu işlenir, uygulanmadan önce
  staging'de doğrulanır
- Yedekleme: PostgreSQL WAL tabanlı point-in-time recovery, günlük tam yedek + sürekli
  WAL arşivi (randevu/ödeme verisi kritik, en fazla birkaç dakikalık veri kaybı toleransı)

## 2. Ölçekleme

- **Backend API**: durumsuz (03/04 kararınca konuşma durumu zaten Backend'in DB'sinde,
  bellekte değil) → yatayda çoğaltılabilir, önünde load balancer
- **n8n**: tek instance sınırını aştığında queue mode (çoklu worker); 04'teki hatırlatma
  ve TTL-iptal cron'ları tüm tenant'ları tek sorguda taradığından, birden fazla worker
  aynı cron'u eşzamanlı çalıştırmasın diye Postgres advisory lock ile mutex eklenir
  (yeni gereksinim, §6)
- **Veritabanı**: yazma tarafı tek birincil düğüm kalır (exclusion constraint ve RLS
  tek düğümde tutarlı); 06/08'de işaretlenen rapor agregasyon yükü artarsa salt okunur
  replika rapor sorgularına yönlendirilir — ilk sürümde gerekli değil, eşik: tenant
  başına >10k randevu/ay
- **WhatsApp mesaj hacmi**: Meta'nın numara başına mesajlaşma katmanı (tier) sistemi
  tenant'ın kalite puanına bağlı; panelde `whatsapp_status` yanına tier bilgisi eklenir,
  tier sınırına yaklaşan tenant'a panel uyarısı verilir (yeni gereksinim, §6)
- **AI çağrıları**: 07'de tenant bazlı rate limit eşiği planlanmıştı; eşik artık plana
  göre değişir (bkz. §3), Redis sayaç ile uygulanır

## 3. Lisanslama (Plan Modeli)

- Yeni `plans` tablosu: `id, name, max_staff, max_appointments_per_month, ai_enabled,
  campaigns_enabled, price_monthly`
- `tenants.plan_id` eklenir; Backend middleware her ilgili endpoint'te plan limitini
  kontrol eder, aşımda `403 plan_limit_exceeded` (03'teki ortak hata sözleşmesiyle uyumlu)
- Örnek üç kademe: **Starter** (1 personel, AI kapalı, kampanya kapalı), **Pro**
  (5 personel, AI açık, kampanya kapalı), **Business** (sınırsız personel, AI + kampanya
  açık)
- Plan değişikliği anında `ai_settings.enabled`/kampanya erişimi gibi mevcut alanlara
  yansır; panelden plan yükseltme/düşürme sayfası eklenir (06'nın ayarlar bölümüne yeni
  sekme, mevcut sayfa yapısı değişmez)

## 4. Abonelik ve Faturalama

- Ödeme sağlayıcısı: Stripe (uluslararası) veya iyzico (TR pazarı) — tenant başına tek
  sağlayıcı, seçim dağıtım aşamasında netleşir
- `tenants` tablosuna `subscription_status` (`trialing/active/past_due/cancelled`),
  `billing_customer_id`, `trial_ends_at` eklenir (yeni gereksinim, §6)
- Sağlayıcının webhook'u (`invoice.paid`, `invoice.payment_failed` vb.) Backend'de ayrı bir
  uçtan karşılanır; Meta webhook imza doğrulamasından bağımsız, sağlayıcının kendi imza
  şeması ile doğrulanır
- Deneme süresi: varsayılan 14 gün, `trial_ends_at` dolunca `subscription_status='past_due'`
  olur
- Ödeme gecikmesinde (`past_due`) davranış: panel salt okunur moda geçer, giden WhatsApp
  mesajlaşması (hatırlatma/kampanya) durur, yalnızca mevcut randevuların görüntülenmesine
  izin verilir; müşteriye giden otomatik mesaj kesilmez ama işletme sahibine panelde ve
  e-posta ile uyarı gider
- 30 gün ödenmezse `cancelled`, tenant verisi silinmez ama tüm dış entegrasyon (webhook,
  cron) o tenant için devre dışı kalır

## 5. Platform Yönetimi (Yeni Rol)

- 06'daki admin panel yalnızca tenant-scoped kullanıcılar (owner/staff) içindi; yayın için
  tenant'lar üstü bir **platform admin** rolü gerekir: tenant listesi, plan atama, abonelik
  durumu, `webhook_events` genel hata oranı izleme
- Bu rol ayrı bir panel/route grubu olarak eklenir, tenant panelinden route seviyesinde
  izole (yanlışlıkla tenant verisine RLS bypass ile erişimi önlemek için ayrı DB rolü
  kullanır — 02'deki servis rolü modeliyle aynı desen)

## 6. Bu Fazda Tespit Edilen Yeni Gereksinimler (toplu migration listesi)

- `plans` tablosu + `tenants.plan_id`
- `tenants.subscription_status / billing_customer_id / trial_ends_at`
- Platform admin rolü ve ayrı route grubu
- n8n cron mutex'i için Postgres advisory lock kullanımı
- Panelde WhatsApp mesajlaşma tier uyarı alanı
- Redis: rate limit sayaçları ve n8n queue mode deposu
- KVKK/GDPR: müşteri veri silme/anonimleştirme uç noktası (06 §Not'ta ertelenmişti, genel
  yayın öncesi zorunlu hale gelir)
- `BACKLOG.md`, `PROJECT_MEMORY.md`, `README.md` (Development Pack'te planlı, henüz hiç
  oluşturulmadı — kodlama fazına geçmeden önce eklenmeli)

## 7. Genel Kullanıma Açılış Kriterleri

- §6 listesindeki migration'lar staging'de uygulanmış ve 08'deki 21 test senaryosu
  staging'de tekrar (regresyon) çalıştırılmış olmalı
- En az bir tam abonelik döngüsü (trial → active → fatura ödemesi) sahte/test kartla
  uçtan uca doğrulanmış olmalı
- Platform admin panelinden en az bir tenant'ın plan/abonelik durumu değiştirilip tenant
  tarafında etkisi gözlemlenmiş olmalı

---

**Doküman fazı tamamlandı (9/9).** Sonraki adım dokümantasyon değil: 00-09'da tasarlanan
mimarinin gerçek kodunun yazılmasıdır (Development Pack önerisi: kodlama için Claude Sonnet).
