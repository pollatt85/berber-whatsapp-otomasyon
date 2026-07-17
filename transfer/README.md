# transfer/ — Sistemi başka makineye taşıma

Bu klasör, tüm sistemi (kod + `.env` + PostgreSQL veritabanı + n8n verisi +
credential'lar) tek pakette bir makineden diğerine taşımak için iki PowerShell
script'i içerir. `git pull` yalnızca **kodu** taşır; `.env`, veritabanı ve n8n
verisi git'e dahil değildir — bu script'ler o boşluğu kapatır.

## Dosyalar

| Dosya | Nerede çalışır | Ne yapar |
|-------|----------------|----------|
| `export_transfer.ps1` | **Kaynak** makine | Her şeyi masaüstünde tek klasöre paketler (proje + DB dump + roller + `.n8n` + talimat). |
| `import_transfer.ps1` | **Hedef** makine | Paketi sırayla geri yükler (proje → roller → DB restore → n8n). Her adımda ENTER ile onay ister. |

## Kullanım

### 1) Kaynak makinede — paketi oluştur
```powershell
powershell -ExecutionPolicy Bypass -File transfer\export_transfer.ps1
```
Çıktı: `Masaüstü\berber-transfer-<tarih>\`
- `project\` — kod + `.env` + `redis` + yüklemeler + `.git`
- `db\berber_saas.dump` + `db\roles.sql`
- `n8n\.n8n\` — workflow + credential + n8n şifreleme anahtarı
- `import_transfer.ps1` + `OKU-BENI.txt`

> Not: `pg_dump` sırasında postgres parolası sorulabilir; trust auth ise boş geçin.

### 2) Paketi hedef makineye taşı
Klasörü olduğu gibi kopyala (USB / harici disk).

### 3) Hedef makinede — geri yükle
Paket klasörünün **içinden**:
```powershell
powershell -ExecutionPolicy Bypass -File import_transfer.ps1
```

## Hedef makine ön koşulları
- PostgreSQL (tercihen aynı ana sürüm — **16**)
- XAMPP / PHP
- n8n

Kurulum bittikten sonra servisleri başlat: PostgreSQL, `redis\redis-server.exe`,
PHP/Apache, n8n, ve WhatsApp webhook için ngrok (`--log` ile başlat).

## ⚠️ Güvenlik
Oluşan paket **gizli** bilgi içerir (`.env`, DB token'ları, n8n credential'ları).
Şifreli ortamda taşı, buluta yükleme, aktarım bitince paketi **sil**.

## Notlar
- DB dump'ı migration 0007'yi de içerir → hedefte ayrıca migration çalıştırmaya gerek yok.
- `.env` ile aynı `APP_ENCRYPTION_KEY` taşındığı için WhatsApp token'ları sorunsuz çözülür.
- Script'ler UTF-8 **BOM** ile kaydedilmiştir (Windows PowerShell 5.1 Türkçe karakterleri doğru okusun diye) — düzenlerken bu kodlamayı koru.
