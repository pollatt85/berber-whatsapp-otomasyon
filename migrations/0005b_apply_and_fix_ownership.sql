-- 0005b — 0005'i uygula + tablo sahipligini berber_service'e devret (KALICI COZUM)
--
-- NEDEN: Tum public tablolar 'postgres' superuser sahipliginde; berber_service ALTER TABLE
-- yapamiyor -> migration'lar elle postgres ile uygulanmak zorunda kaliyor, postgres sifresi
-- ise hicbir yerde saklanmiyor (PROJECT_MEMORY). Bu betik bir KEREYE MAHSUS postgres ile
-- calisir; sonrasinda migration'lar berber_service (zaten BYPASSRLS olan servis hesabi) ile
-- uygulanabilir.
--
-- GUVENLIK NOTU: Sahiplik devri berber_app'in RLS izolasyonunu ETKILEMEZ (RLS policy'ler ve
-- berber_app GRANT'lari korunur; berber_service zaten BYPASSRLS). Yalnizca berber_service'e
-- DDL yetkisi kazandirir.
--
-- CALISTIRMA (yonetici olarak, sifreyi psql sorar):
--   "C:\Program Files\PostgreSQL\16\bin\psql.exe" -U postgres -h 127.0.0.1 -d berber_saas -v ON_ERROR_STOP=1 -f migrations/0005b_apply_and_fix_ownership.sql

BEGIN;

-- 1) Migration 0005: message_templates.language (idempotent)
ALTER TABLE message_templates ADD COLUMN IF NOT EXISTS language text NOT NULL DEFAULT 'tr';

-- 2) Tum public tablolarin sahipligini berber_service'e devret
DO $$
DECLARE r record;
BEGIN
    FOR r IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
        EXECUTE format('ALTER TABLE public.%I OWNER TO berber_service', r.tablename);
    END LOOP;
END $$;

COMMIT;

-- Dogrulama
\echo '=== language kolonu ==='
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_name = 'message_templates' AND column_name = 'language';

\echo '=== tablo sahipleri (hepsi berber_service olmali) ==='
SELECT tableowner, count(*) FROM pg_tables WHERE schemaname = 'public' GROUP BY tableowner;
