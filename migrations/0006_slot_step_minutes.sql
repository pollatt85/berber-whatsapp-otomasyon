-- 0006 — Faz D: tenant başına slot adımı (dakika). Varsayılan 30 (eski sabit 60).
-- AvailabilityService bunu okur (slot_step_minutes yoksa DEFAULT_STEP_MINUTES=30 fallback).
--
-- Uygulama (ownership fix 0005b sonrası berber_service ile yapılabilir):
--   psql -U berber_service -h 127.0.0.1 -d berber_saas -f migrations/0006_slot_step_minutes.sql

BEGIN;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS slot_step_minutes integer NOT NULL DEFAULT 30
    CHECK (slot_step_minutes IN (15, 20, 30, 45, 60));
COMMIT;
