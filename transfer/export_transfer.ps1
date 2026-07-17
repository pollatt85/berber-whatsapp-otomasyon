# =============================================================================
# Berber WhatsApp Otomasyon — TAM TRANSFER PAKETİ (dışa aktarma)
# Bu makinede çalıştır. Her şeyi masaüstünde tek klasöre toplar:
#   - Proje klasörü (kod + .env + redis + yüklemeler + .git)
#   - PostgreSQL veritabanı dump'ı (berber_saas) + roller
#   - n8n verisi (%USERPROFILE%\.n8n — workflow + credential + şifreleme anahtarı)
#   - Laptopta çalıştırılacak import_transfer.ps1 + OKU-BENI.txt
#
# Çalıştırma:
#   powershell -ExecutionPolicy Bypass -File scripts\export_transfer.ps1
#
# UYARI: Paket .env, DB token'ları ve n8n credential'ları içerir — GİZLİDİR.
#        Güvenli taşı (şifreli USB), aktarım bitince paketi sil. Buluta yükleme.
# =============================================================================

$ErrorActionPreference = 'Stop'
$proj   = 'C:\xampp\htdocs\berber-whatsapp-otomasyon'
$dbName = 'berber_saas'

# --- PostgreSQL bin klasörünü otomatik bul (en yüksek sürüm) ---
$pgbin = Get-ChildItem 'C:\Program Files\PostgreSQL\*\bin\pg_dump.exe' -ErrorAction SilentlyContinue |
         Sort-Object FullName -Descending | Select-Object -First 1 | Split-Path
if (-not $pgbin) { throw "PostgreSQL bin bulunamadı (C:\Program Files\PostgreSQL\*\bin). PG kurulu mu?" }
Write-Host "PostgreSQL araçları: $pgbin" -ForegroundColor DarkGray

# --- Hedef paket klasörü (masaüstü + zaman damgası) ---
$stamp  = Get-Date -Format 'yyyyMMdd-HHmm'
$desktop = [Environment]::GetFolderPath('Desktop')
$bundle = Join-Path $desktop "berber-transfer-$stamp"
New-Item -ItemType Directory -Force -Path $bundle,"$bundle\project","$bundle\db","$bundle\n8n" | Out-Null
Write-Host "Paket klasörü: $bundle`n" -ForegroundColor Cyan

# --- 1/4 Proje klasörü (log/scratchpad/node_modules hariç; .git dahil) ---
Write-Host "[1/4] Proje kopyalanıyor..." -ForegroundColor Green
robocopy $proj "$bundle\project" /E /NFL /NDL /NJH /NJS /NP `
    /XD "$proj\scratchpad" "$proj\node_modules" `
    /XF *.log *.tmp | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy (proje) hata: $LASTEXITCODE" }

# --- 2/4 Veritabanı + roller ---
Write-Host "[2/4] Veritabanı dump alınıyor ($dbName)..." -ForegroundColor Green
Write-Host "      (postgres parolası sorulabilir; trust auth'ta boş geç)" -ForegroundColor DarkGray
& "$pgbin\pg_dump.exe"    -U postgres -h 127.0.0.1 -d $dbName -Fc -f "$bundle\db\$dbName.dump"
if ($LASTEXITCODE -ne 0) { throw "pg_dump hata: $LASTEXITCODE" }
& "$pgbin\pg_dumpall.exe" -U postgres -h 127.0.0.1 --roles-only -f "$bundle\db\roles.sql"
if ($LASTEXITCODE -ne 0) { throw "pg_dumpall (roller) hata: $LASTEXITCODE" }

# --- 3/4 n8n verisi ---
Write-Host "[3/4] n8n verisi kopyalanıyor..." -ForegroundColor Green
$n8nSrc = Join-Path $env:USERPROFILE '.n8n'
if (Test-Path $n8nSrc) {
    robocopy $n8nSrc "$bundle\n8n\.n8n" /E /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy (n8n) hata: $LASTEXITCODE" }
} else {
    Write-Host "      .n8n bulunamadı ($n8nSrc) — atlandı (n8n bu kullanıcıda kurulu değil?)" -ForegroundColor Yellow
}

# --- 4/4 Import script + talimat ---
Write-Host "[4/4] Import script + OKU-BENI yazılıyor..." -ForegroundColor Green
if (Test-Path "$proj\transfer\import_transfer.ps1") {
    Copy-Item "$proj\transfer\import_transfer.ps1" "$bundle\import_transfer.ps1" -Force
}
@"
BERBER SİSTEM TRANSFER PAKETİ — $stamp
=======================================

İÇİNDEKİLER
  project\              Tam proje (kod + .env + redis + yüklemeler + .git)
  db\$dbName.dump       PostgreSQL veritabanı (tenant, WhatsApp token, hizmetler...)
  db\roles.sql          DB rolleri (berber_service / berber_app — .env şifreleriyle)
  n8n\.n8n\             n8n verisi (workflow + credential + şifreleme anahtarı)
  import_transfer.ps1   Laptopta geri yükleme script'i

LAPTOPTA YAPILACAKLAR (ön koşullar önce):
  1) XAMPP/PHP, PostgreSQL (aynı ana sürüm — tercihen 16), n8n kurulu olmalı.
  2) Bu paketi laptopa kopyala.
  3) Import script'ini paket klasörünün İÇİNDEN çalıştır:
        powershell -ExecutionPolicy Bypass -File import_transfer.ps1
     (Onu birlikte, adım adım çalıştıracağız — her adımı doğrulayarak.)
  4) Servisleri başlat: PostgreSQL, redis (project\redis\redis-server.exe),
     PHP/Apache, n8n, ve WhatsApp webhook için ngrok.

GÜVENLİK: Bu klasör gizli bilgi içerir. Aktarım bitince SİL.
"@ | Set-Content -Path "$bundle\OKU-BENI.txt" -Encoding UTF8

# --- Özet ---
$sizeGB = [math]::Round((Get-ChildItem $bundle -Recurse -File | Measure-Object Length -Sum).Sum / 1GB, 2)
Write-Host "`nTAMAMLANDI ✔  ($sizeGB GB)" -ForegroundColor Cyan
Write-Host "Paket: $bundle" -ForegroundColor Cyan
Write-Host "Bu klasörü laptopa taşı, içindeki import_transfer.ps1'i orada çalıştır." -ForegroundColor Cyan
