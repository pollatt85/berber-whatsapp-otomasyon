# 01 — System Architecture

## 1. Mimari Diyagram

```
┌──────────────┐   mesaj    ┌──────────────────┐  webhook (POST)  ┌─────────┐
│   Müşteri    │──────────▶│  Meta Cloud API   │────────────────▶│   n8n   │
│ (kişisel WA) │◀──────────│ (WhatsApp Business│◀────────────────│otomasyon│
└──────────────┘   yanıt    │  Platform)        │  send message   └────┬────┘
                            └──────────────────┘                       │ REST
                                                                        ▼
                            ┌──────────────┐   SQL    ┌────────────────────┐
                            │  PostgreSQL  │◀────────▶│    Backend API     │
                            │ (tenant_id   │          │ (iş kuralları,     │
                            │  izolasyonu) │          │  takvim algoritması)│
                            └──────┬───────┘          └────────┬───────────┘
                                   │                           │ REST + Auth
                                   └────────────┐              ▼
                                                │   ┌────────────────────┐
                                                └──▶│    Admin Panel     │
                                                    │ (berber yönetimi)  │
                                                    └────────────────────┘
```

**Sorumluluk ayrımı:** n8n = otomasyon/orkestrasyon · Backend = iş kuralları · PostgreSQL = veri · Panel = yönetim. n8n asla doğrudan veritabanına yazmaz; her şey Backend API üzerinden geçer (tek doğruluk kaynağı, tenant izolasyonu tek noktada uygulanır).

## 2. Teknoloji Seçimleri ve Gerekçeleri

| Katman | Seçim | Gerekçe |
|--------|-------|---------|
| Veritabanı | **PostgreSQL 16** | Aşağıda ayrıntılı karar |
| Backend API | **PHP 8.2+ (XAMPP Apache üzerinde)** | Geliştirme ortamı XAMPP/Windows; ek runtime kurulumu gerektirmez, mevcut Apache vhost ile çalışır. Modern PHP (typed, PDO) yeterli |
| Otomasyon | **n8n (self-hosted, Node.js/npm ile Windows'ta)** | Pack'in mimari ilkesi; webhook alma, akış görselleştirme, hatırlatma cron'ları kodsuz yönetilir |
| WhatsApp | **Meta Cloud API (resmi)** | Resmi, ücretsiz barındırılan (on-premise API emekli oldu); ban riski yok, şablon mesaj + interaktif menü desteği |
| Admin Panel | **PHP + Bootstrap 5 (responsive)** | Backend ile aynı stack, XAMPP'te doğrudan servis edilir |
| Geliştirmede webhook tüneli | **ngrok / cloudflared** | Meta webhook'u HTTPS + genel erişilebilir URL ister; localhost XAMPP bunu sağlayamaz |

### PostgreSQL vs MySQL kararı — **PostgreSQL** ✔

XAMPP MySQL/MariaDB ile gelir; buna rağmen PostgreSQL seçiyoruz:

1. **Pack'in mimari ilkesi PostgreSQL'i sabitliyor** (`WhatsApp → ... → PostgreSQL → Panel`); üretim hedefi de PostgreSQL olacak. Geliştirmede MySQL kullanıp üretimde PostgreSQL'e geçmek tip/DDL uyumsuzluğu riski taşır.
2. **JSONB**: Meta webhook payload'ları ve tenant bazlı esnek ayarlar (mesaj şablonları, çalışma saatleri) JSONB kolonlarında indekslenebilir şekilde saklanır; MySQL JSON desteği daha zayıftır.
3. **Row-Level Security (RLS)**: tenant izolasyonunu veritabanı katmanında zorlamak için doğal mekanizma; MySQL'de yoktur.
4. **Takvim algoritması**: `tstzrange` + exclusion constraint ile çakışan randevuları veritabanı seviyesinde engelleme imkânı.

**Windows'ta kurulum:** PostgreSQL Windows installer'ı XAMPP'ten bağımsız servis olarak çalışır (port 5432). PHP tarafında `php.ini` içinde `pdo_pgsql` ve `pgsql` eklentileri açılır. XAMPP'in MySQL'i kullanılmaz.

## 3. Multi-Tenant Tasarım

**Model:** Tek veritabanı, paylaşımlı şema, satır bazlı izolasyon (`tenant_id` kolonu). SaaS başlangıcı için en düşük operasyon maliyeti; tenant başına şema/DB gerekmez.

### Tenant eşleştirme: `phone_number_id` → tenant

- Her berber onboarding'de kendi WhatsApp Business numarasını bağlar; Meta bu numaraya bir `phone_number_id` atar.
- Gelen her webhook payload'ında `entry[].changes[].value.metadata.phone_number_id` bulunur.
- n8n webhook'u alır → Backend API'ye iletir → Backend `tenants` tablosunda `phone_number_id` ile tenant'ı çözer. Eşleşme yoksa istek loglanır ve reddedilir.
- Çözülen `tenant_id`, isteğin tüm yaşam döngüsü boyunca taşınır; sonraki her sorgu bu tenant'a filtrelenir.

### Veri izolasyonu

- Tenant'a ait **her** tabloda zorunlu `tenant_id` (FK → `tenants.id`).
- Backend'de her sorgu `WHERE tenant_id = ?` içerir; repository katmanı bunu merkezi olarak enjekte eder (geliştirici unutamaz).
- Ek savunma hattı: PostgreSQL RLS politikaları — bağlantı başına `SET app.current_tenant` ile satır erişimi DB seviyesinde kısıtlanır.
- Tenant başına Meta erişim bilgileri (access token, `phone_number_id`, WABA ID, webhook verify token) `tenants` tablosunda **şifrelenmiş** saklanır.

## 4. Meta Cloud API Esasları

- **Webhook doğrulama:** (a) İlk kurulumda Meta `GET` ile `hub.challenge` gönderir; verify token eşleşirse challenge aynen döndürülür. (b) Her `POST` webhook'unda `X-Hub-Signature-256` başlığı, app secret ile HMAC-SHA256 olarak doğrulanır; geçersiz imza reddedilir.
- **24 saat müşteri hizmet penceresi:** Müşterinin son mesajından itibaren 24 saat içinde serbest biçimli mesaj gönderilebilir. Pencere kapandıktan sonra **yalnızca onaylı şablon mesaj** gönderilebilir.
- **Şablon mesaj gereksinimi:** İşletme başlatmalı tüm mesajlar (randevu hatırlatma, onay bildirimi, kampanya) Meta onaylı şablon gerektirir. Şablonlar tenant'ın WABA'sında tanımlanır; panel şablon adlarını ve değişkenlerini yönetir. Hatırlatmalar tipik olarak pencere dışına düştüğü için **hatırlatma akışları şablon tabanlı tasarlanır**.
- **Tenant başına access token:** Her tenant kendi Meta uygulaması/WABA'sı için kalıcı (System User) token sağlar. Token'lar şifreli saklanır; tüm gönderimler ilgili tenant'ın token'ı ve `phone_number_id`'si ile yapılır. Tek paylaşımlı token yoktur.
- **Rate limit / hata yönetimi:** Gönderimler kuyruklanır, Meta hata kodları (ör. 131047 — pencere kapalı) loglanıp şablon fallback'ine yönlendirilir.

## 5. Randevu Akışı — Üst Düzey Senaryo

1. Müşteri, berberin Business numarasına "randevu" yazar.
2. Cloud API → n8n webhook; imza doğrulanır, `phone_number_id` ile tenant çözülür.
3. n8n → Backend: tenant'ın hizmet listesi çekilir → müşteriye interaktif liste mesajı (hizmetler + fiyatlar) gönderilir.
4. Müşteri hizmet seçer → personel seçimi (tenant'ın aktif personelleri) → Backend takvim algoritması uygun saatleri hesaplar (çalışma saatleri − molalar − tatiller − mevcut randevular) → uygun slotlar buton/liste olarak sunulur.
5. Müşteri slot seçer → Backend randevuyu `pending` olarak yazar (çakışma DB kısıtıyla engellenir) → müşteriye özet + onay butonu.
6. Müşteri onaylar → randevu `confirmed`; berbere panel bildirimi düşer.
7. Randevudan X saat önce (panelden ayarlanır) n8n cron → şablon mesajla hatırlatma.
8. Müşteri "iptal/değiştir" yazarsa aynı akış üzerinden iptal veya yeniden planlama yapılır; her adım `tenant_id` bağlamında loglanır.

---
**STATUS: PHASE_1_COMPLETE**
