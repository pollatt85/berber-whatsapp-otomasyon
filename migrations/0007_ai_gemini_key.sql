-- 0007 — Per-berber (BYOK) Gemini API anahtarı. Nullable, şifreli (bytea, AES-256-GCM).
-- Anahtar yoksa sistem global .env anahtarına düşer (geriye uyumlu).
--
-- Uygulama (ownership fix 0005b sonrası berber_service ile yapılabilir):
--   psql -U berber_service -h 127.0.0.1 -d berber_saas -f migrations/0007_ai_gemini_key.sql

BEGIN;
ALTER TABLE ai_settings ADD COLUMN IF NOT EXISTS gemini_api_key_encrypted bytea;
COMMIT;
