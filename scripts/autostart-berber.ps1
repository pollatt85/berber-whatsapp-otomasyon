# Berber WhatsApp Otomasyon - boot otomatik baslatma
# Windows oturum acilinca calisir (Baslangic klasoru launcher: BerberOtomasyonAutostart.vbs).
# Apache + Redis + n8n + ngrok calismiyorsa baslatir. Postgres zaten Windows servisi (otomatik).
# Her biri idempotent: zaten calisiyorsa dokunmaz -> XAMPP Control Panel ile cakismaz.

$ErrorActionPreference = 'SilentlyContinue'
$proj  = 'C:\xampp\htdocs\berber-whatsapp-otomasyon'
$log   = Join-Path $proj 'scripts\autostart-berber.log'
function Log($m){ "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $m" | Out-File -FilePath $log -Append -Encoding utf8 }

Log '--- autostart basladi ---'

# 1) Apache (panel + backend :80 ve :8081)
if (-not (Get-Process httpd -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath 'C:\xampp\apache\bin\httpd.exe' -WorkingDirectory 'C:\xampp\apache\bin' -WindowStyle Hidden
    Log 'Apache baslatildi'
} else { Log 'Apache zaten calisiyor' }

# 2) Redis
$redisUp = Get-NetTCPConnection -LocalPort 6379 -State Listen -ErrorAction SilentlyContinue
if (-not $redisUp) {
    Start-Process -FilePath (Join-Path $proj 'redis\redis-server.exe') `
                  -ArgumentList 'redis.windows.conf' `
                  -WorkingDirectory (Join-Path $proj 'redis') -WindowStyle Hidden
    Log 'Redis baslatildi'
} else { Log 'Redis zaten calisiyor' }

# 3) n8n (:5678) - backend'i kalici Apache'ye (8081) baglar, sirri .env'den okur
$n8nUp = Get-NetTCPConnection -LocalPort 5678 -State Listen -ErrorAction SilentlyContinue
if (-not $n8nUp) {
    $secret = ''
    foreach ($line in Get-Content (Join-Path $proj '.env')) {
        if ($line -match '^\s*N8N_SERVICE_SECRET\s*=\s*(.+?)\s*$') { $secret = $Matches[1] }
    }
    $env:N8N_SERVICE_SECRET            = $secret
    $env:BACKEND_BASE_URL             = 'http://localhost:8081'
    $env:NODE_FUNCTION_ALLOW_BUILTIN  = 'crypto'
    $env:N8N_BLOCK_ENV_ACCESS_IN_NODE = 'false'
    Start-Process -FilePath 'C:\nvm4w\nodejs\n8n.cmd' -ArgumentList 'start' -WindowStyle Hidden
    Log "n8n baslatildi (BACKEND_BASE_URL=8081, secret len=$($secret.Length))"
} else { Log 'n8n zaten calisiyor' }

# 4) ngrok (sabit alan adi, Meta webhook'u buna kayitli) - backend'e (:8081) tunel acar
$ngrokUp = Get-NetTCPConnection -LocalPort 4040 -State Listen -ErrorAction SilentlyContinue
if (-not $ngrokUp) {
    # --config MUTLAK yol sart. Config exe klasorunde (AppData\Local DEGIL): zamanlanmis gorev/boot
    # baglaminda %LocalAppData% tanimsiz + AppData\Local yolu erisilemez olabiliyor -> authtoken
    # okunamaz -> ERR_NGROK_4018 -> aninda oler. Exe klasoru (C:\Users\User\ngrok) her baglamda erisilir.
    Start-Process -FilePath 'C:\Users\User\ngrok\ngrok.exe' `
                  -ArgumentList 'http', '--url=provider-dislodge-bounce.ngrok-free.dev', '8081', `
                                '--config=C:\Users\User\ngrok\ngrok.yml', `
                                '--log=C:\xampp\htdocs\berber-whatsapp-otomasyon\scripts\ngrok.log', `
                                '--log-format=logfmt' `
                  -WindowStyle Hidden
    Log 'ngrok baslatildi (provider-dislodge-bounce.ngrok-free.dev -> :8081, --config+--log ile headless)'
} else { Log 'ngrok zaten calisiyor' }

Log '--- autostart bitti ---'
