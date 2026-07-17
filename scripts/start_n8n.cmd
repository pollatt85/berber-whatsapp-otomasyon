@echo off
REM n8n'i manuel baslatir. Secret repo'ya duz metin yazilmaz; .env'den okunur
REM (autostart-berber.ps1 ile ayni kaynak). BACKEND_BASE_URL kalici Apache vhost'u (:8081).
powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=(Select-String -Path '%~dp0..\.env' -Pattern '^\s*N8N_SERVICE_SECRET\s*=\s*(.+?)\s*$').Matches.Groups[1].Value; if(-not $s){Write-Error 'N8N_SERVICE_SECRET .env icinde bulunamadi'; exit 1}; $env:N8N_SERVICE_SECRET=$s; $env:BACKEND_BASE_URL='http://localhost:8081'; $env:NODE_FUNCTION_ALLOW_BUILTIN='crypto'; $env:N8N_BLOCK_ENV_ACCESS_IN_NODE='false'; n8n start"
