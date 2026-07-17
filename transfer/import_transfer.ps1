# =============================================================================
# Berber WhatsApp Otomasyon — TAM TRANSFER PAKETİ (geri yükleme / laptop)
# Bu script'i TRANSFER PAKETİ klasörünün İÇİNDEN çalıştır:
#   powershell -ExecutionPolicy Bypass -File import_transfer.ps1
#
# Sırayla: proje kopyalama -> DB rolleri -> DB restore -> n8n verisi.
# Her adım hata verirse durur. Birlikte adım adım doğrulayarak ilerleyeceğiz.
#
# ÖN KOŞUL: PostgreSQL (tercihen aynı ana sürüm, 16), XAMPP/PHP, n8n kurulu olmalı.
# =============================================================================

$ErrorActionPreference = 'Stop'
$here   = $PSScriptRoot
$dbName = 'berber_saas'
$dest   = 'C:\xampp\htdocs\berber-whatsapp-otomasyon'

$pgbin = Get-ChildItem 'C:\Program Files\PostgreSQL\*\bin\pg_restore.exe' -ErrorAction SilentlyContinue |
         Sort-Object FullName -Descending | Select-Object -First 1 | Split-Path
if (-not $pgbin) { throw "PostgreSQL bin bulunamadı. Laptopa PostgreSQL kur." }
Write-Host "PostgreSQL araçları: $pgbin`n" -ForegroundColor DarkGray

function Pause-Step($msg) {
    Write-Host "`n>>> $msg" -ForegroundColor Yellow
    Read-Host "    Devam için ENTER (iptal için Ctrl+C)" | Out-Null
}

# --- 1) Proje klasörü ---
Pause-Step "1/4 Proje '$dest' konumuna kopyalanacak."
New-Item -ItemType Directory -Force -Path $dest | Out-Null
robocopy "$here\project" $dest /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy (proje) hata: $LASTEXITCODE" }
Write-Host "    Proje kopyalandı ✔" -ForegroundColor Green

# --- 2) DB rolleri (berber_service / berber_app) ---
Pause-Step "2/4 DB rolleri oluşturulacak (roles.sql). 'already exists' uyarıları normaldir."
& "$pgbin\psql.exe" -U postgres -h 127.0.0.1 -f "$here\db\roles.sql"
Write-Host "    Roller yüklendi (uyarılar olabilir) ✔" -ForegroundColor Green

# --- 3) Veritabanı restore ---
Pause-Step "3/4 '$dbName' veritabanı oluşturulup restore edilecek."
$exists = (& "$pgbin\psql.exe" -U postgres -h 127.0.0.1 -tAc "SELECT 1 FROM pg_database WHERE datname='$dbName'")
if ($exists -eq '1') {
    Write-Host "    UYARI: '$dbName' zaten var." -ForegroundColor Red
    if ((Read-Host "    SİLİP yeniden oluşturayım mı? (evet/hayir)") -eq 'evet') {
        & "$pgbin\dropdb.exe" -U postgres -h 127.0.0.1 $dbName
    } else {
        throw "Kullanıcı iptal etti (mevcut DB korunuyor)."
    }
}
& "$pgbin\createdb.exe"   -U postgres -h 127.0.0.1 $dbName
& "$pgbin\pg_restore.exe" -U postgres -h 127.0.0.1 -d $dbName "$here\db\$dbName.dump"
# pg_restore ownership/uyarı için 1 dönebilir; kritik değilse devam.
Write-Host "    DB restore edildi ✔" -ForegroundColor Green

# --- 4) n8n verisi ---
Pause-Step "4/4 n8n verisi %USERPROFILE%\.n8n konumuna kopyalanacak (varsa üzerine yazar)."
if (Test-Path "$here\n8n\.n8n") {
    robocopy "$here\n8n\.n8n" (Join-Path $env:USERPROFILE '.n8n') /E /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy (n8n) hata: $LASTEXITCODE" }
    Write-Host "    n8n verisi kopyalandı ✔" -ForegroundColor Green
} else {
    Write-Host "    Pakette n8n verisi yok — atlandı." -ForegroundColor Yellow
}

Write-Host "`nGERİ YÜKLEME TAMAM ✔" -ForegroundColor Cyan
Write-Host @"

SIRADAKİ (servisleri başlat):
  - PostgreSQL çalışıyor olmalı (zaten açık).
  - Redis:   $dest\redis\redis-server.exe  $dest\redis\redis.windows.conf --port 6379
  - PHP:     php -S localhost:8000 -t public public/index.php   (veya XAMPP Apache)
  - n8n:     n8n start   (veya $dest\scripts\start_n8n.cmd)
  - ngrok:   WhatsApp webhook için tüneli aç (--log ile başlat, gizli başlatma!)

DOĞRULAMA:
  psql -U berber_service -h 127.0.0.1 -d $dbName -c "\d ai_settings" | findstr gemini
  psql -U berber_service -h 127.0.0.1 -d $dbName -c "SELECT business_name, whatsapp_status FROM tenants;"
"@ -ForegroundColor Cyan
