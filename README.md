# Berber WhatsApp Otomasyon

Çoklu işletme (multi-tenant SaaS) randevu asistanı. Her berber kendi WhatsApp Business
numarasını sisteme bağlar; müşteri kendi WhatsApp'ından randevu alır. Tüm işletme verisi
(hizmet, fiyat, personel, çalışma saatleri, mesaj şablonları) admin panelden yönetilir —
kodda sabit işletme verisi yoktur.

```
WhatsApp → Meta Cloud API → n8n → Backend API → PostgreSQL → Admin Panel
```

## Dokümantasyon

Proje, tasarımdan koda kadar 9 fazlık bir "Development Pack" ile yürütülür
(`WhatsApp_Business_Assistant_Development_Pack.md`). Tasarım fazı tamamlandı; ayrıntılar:

| Doküman | Kapsam |
|---|---|
| [00_Master_Roadmap.md](00_Master_Roadmap.md) | Faz listesi, ilerleme, netleşmiş kararlar |
| [01_System_Architecture.md](01_System_Architecture.md) | Mimari, teknoloji seçimleri, multi-tenant model |
| [02_Database_Design.md](02_Database_Design.md) | ER diyagramı, tablolar, RLS, tenant izolasyonu |
| [03_Backend_API.md](03_Backend_API.md) | API tasarımı, auth, takvim algoritması |
| [04_n8n_Workflows.md](04_n8n_Workflows.md) | Otomasyon akışları |
| [05_WhatsApp_Integration.md](05_WhatsApp_Integration.md) | Meta Cloud API protokolü |
| [06_Admin_Panel.md](06_Admin_Panel.md) | Panel sayfa haritası |
| [07_AI_Module.md](07_AI_Module.md) | Doğal dil asistanı |
| [08_Test_Pilot.md](08_Test_Pilot.md) | Pilot test senaryoları |
| [09_SaaS_Deployment.md](09_SaaS_Deployment.md) | Yayın, ölçekleme, lisanslama |

- [PROJECT_MEMORY.md](PROJECT_MEMORY.md) — kodlama fazının anlık durumu (her oturum güncellenir)
- [BACKLOG.md](BACKLOG.md) — birikmiş migration/endpoint maddeleri + pilot hata kayıtları
- [CHANGELOG.md](CHANGELOG.md) — faz/sürüm geçmişi

## Teknoloji Yığını

- **Backend API:** PHP 8.2+ (bkz. PROJECT_MEMORY.md — geliştirme makinesinde şu an 8.0.30 kurulu, yükseltme bekliyor), PDO
- **Veritabanı:** PostgreSQL 16 (JSONB, Row-Level Security, exclusion constraint)
- **Otomasyon:** n8n (self-hosted)
- **WhatsApp:** Meta Cloud API (resmi)
- **Admin Panel:** PHP + Bootstrap 5

## Dizin Yapısı

```
berber-whatsapp-otomasyon/
├── 00-09_*.md              ← tasarım dokümanları (Development Pack)
├── migrations/             ← PostgreSQL DDL migration'ları (sırayla uygulanır)
│   ├── 0001_initial_schema.sql
│   └── 0002_accumulated_requirements.sql
├── src/                    ← Backend API kaynak kodu (PSR-4, App\ namespace)
│   ├── Config/
│   ├── Database/
│   ├── Http/                (Router, Middleware, Controllers)
│   └── Repository/
├── public/
│   └── index.php           ← front controller (Apache vhost/DocumentRoot buraya işaret eder)
├── composer.json
└── .env.example
```

## Geliştirme Ortamı Kurulumu

1. **PostgreSQL 16** kurulumu (XAMPP'in MySQL'i kullanılmaz — bkz. 01_System_Architecture.md §2).
   Windows installer ile bağımsız servis olarak, port 5432.
2. `php.ini` içinde `extension=pdo_pgsql` ve `extension=pgsql` satırlarını etkinleştir
   (DLL'ler XAMPP PHP kurulumunda mevcuttur, yalnızca etkinleştirme gerekir).
3. `composer install` — bağımlılıkları indirir (JWT, vb.).
4. `.env.example` dosyasını `.env` olarak kopyala, DB bağlantı bilgilerini ve
   `N8N_SERVICE_SECRET` / `JWT_SECRET` değerlerini doldur.
5. Migration'ları sırayla uygula:
   ```
   psql -U postgres -d berber_saas -f migrations/0001_initial_schema.sql
   psql -U postgres -d berber_saas -f migrations/0002_accumulated_requirements.sql
   ```
6. Apache vhost `DocumentRoot`'unu `public/` klasörüne işaret ettir (proje kökü değil —
   yalnızca `public/index.php` dışarıya açık olmalı).

Ayrıntılı ortam bulguları ve bilinen boşluklar için [PROJECT_MEMORY.md](PROJECT_MEMORY.md)
"Kritik Ortam Bulguları" bölümüne bakın.

## Yönetim Paneli Kuralı

Hiçbir işletme bilgisi kodda sabit değildir: hizmetler, süre, fiyat, personel, çalışma
saatleri, mola, tatil, otomatik mesajlar, işletme bilgileri, logo, adres, konum,
kampanyalar ve AI ayarları — tümü panelden yönetilir.
