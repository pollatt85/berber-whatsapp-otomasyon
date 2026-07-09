-- Migration 0002: Accumulated Requirements
-- 03-09 fazlarında "bu fazda tespit edildi, migration olarak işlenecek" notu düşülen tüm
-- maddelerin tek turda uygulanması (09_SaaS_Deployment.md §1: "Migration'lar ... tek bir
-- uygulama başlangıcı migration turunda toplu işlenir"). Kaynak madde listesi: BACKLOG.md §A.
-- Bu migration 0001'in üzerine uygulanır; tasarım kararı almaz, yalnızca önceden kararlaştırılmış
-- ama DDL'e yansıtılmamış maddeleri işler.
--
-- Uygulama: psql -U <user> -d <db> -f migrations/0002_accumulated_requirements.sql

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Idempotency-key (03_Backend_API.md §7, 04_n8n_Workflows.md §8)
--    n8n retry'larının çift randevu/mesaj oluşturmaması için.
-- ---------------------------------------------------------------------------
ALTER TABLE appointments ADD COLUMN idempotency_key text;
ALTER TABLE appointments ADD CONSTRAINT uq_appointments_tenant_idempotency
    UNIQUE (tenant_id, idempotency_key);

ALTER TABLE message_log ADD COLUMN idempotency_key text;
ALTER TABLE message_log ADD CONSTRAINT uq_message_log_tenant_idempotency
    UNIQUE (tenant_id, idempotency_key);

-- ---------------------------------------------------------------------------
-- 2. Hatırlatma / pending-TTL ayarları (04_n8n_Workflows.md §5, §6, §7)
--    Panelden ayarlanır; cron'lar bu değerlere göre tarama yapar.
-- ---------------------------------------------------------------------------
ALTER TABLE tenants ADD COLUMN reminder_hours_before integer NOT NULL DEFAULT 24
    CHECK (reminder_hours_before > 0);
ALTER TABLE tenants ADD COLUMN pending_ttl_minutes integer NOT NULL DEFAULT 15
    CHECK (pending_ttl_minutes > 0);

-- ---------------------------------------------------------------------------
-- 3. WhatsApp bağlantı durumu (05_WhatsApp_Integration.md §1, §7)
--    waba_id / access_token_encrypted zaten 0001'de mevcut; yalnızca durum kolonu eksikti.
-- ---------------------------------------------------------------------------
ALTER TABLE tenants ADD COLUMN whatsapp_status text NOT NULL DEFAULT 'pending'
    CHECK (whatsapp_status IN ('pending','connected','disconnected'));

-- ---------------------------------------------------------------------------
-- 4. Meta hata kodu ayrı kolon (05_WhatsApp_Integration.md §7)
--    Raporlama için ham jsonb içine gömülü kalmasın diye ayrı kolon.
-- ---------------------------------------------------------------------------
ALTER TABLE message_log ADD COLUMN meta_error_code text;

-- ---------------------------------------------------------------------------
-- 5. AI rate limit eşiği (07_AI_Module.md §5, §7)
--    Tenant bazlı; gerçek sayaç Redis'te tutulacak (09§2), bu yalnızca eşik değeri.
-- ---------------------------------------------------------------------------
ALTER TABLE ai_settings ADD COLUMN rate_limit_per_minute integer NOT NULL DEFAULT 10
    CHECK (rate_limit_per_minute > 0);

-- ---------------------------------------------------------------------------
-- 6. conversation_states (04_n8n_Workflows.md §2, §7)
--    n8n'in "müşteri hangi adımda" sorgusu için — Backend'de tutulur (n8n static data
--    paylaşılmadığı için, bkz. 04§2 gerekçesi). Bir müşterinin tenant başına tek aktif
--    state'i olur; state ilerledikçe UPDATE edilir (yeni satır açılmaz).
-- ---------------------------------------------------------------------------
CREATE TABLE conversation_states (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id    uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_id  uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    step         text NOT NULL DEFAULT 'idle',
    context      jsonb NOT NULL DEFAULT '{}',
    updated_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, customer_id)
);

ALTER TABLE conversation_states ENABLE ROW LEVEL SECURITY;
ALTER TABLE conversation_states FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON conversation_states
    USING (tenant_id = current_setting('app.current_tenant', true)::uuid)
    WITH CHECK (tenant_id = current_setting('app.current_tenant', true)::uuid);

-- ---------------------------------------------------------------------------
-- 7. plans (09_SaaS_Deployment.md §3, §6) — kök tablo, tenants gibi RLS uygulanmaz
--    (tüm tenant'lar aynı plan kataloğunu görür, tenant-scoped veri değildir).
-- ---------------------------------------------------------------------------
CREATE TABLE plans (
    id                          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name                        text NOT NULL UNIQUE,
    max_staff                   integer,               -- NULL = sınırsız (Business planı)
    max_appointments_per_month  integer,               -- NULL = sınırsız
    ai_enabled                  boolean NOT NULL DEFAULT false,
    campaigns_enabled           boolean NOT NULL DEFAULT false,
    price_monthly               numeric(10,2) NOT NULL CHECK (price_monthly >= 0),
    created_at                  timestamptz NOT NULL DEFAULT now()
);

-- 09§3 örnek üç kademe: Starter / Pro / Business
INSERT INTO plans (name, max_staff, max_appointments_per_month, ai_enabled, campaigns_enabled, price_monthly) VALUES
    ('starter',  1,    NULL, false, false,   0.00),
    ('pro',      5,    NULL, true,  false,  499.00),
    ('business', NULL, NULL, true,  true,   999.00);

-- ---------------------------------------------------------------------------
-- 8. tenants: plan + abonelik/faturalama alanları (09_SaaS_Deployment.md §3, §4, §6)
-- ---------------------------------------------------------------------------
ALTER TABLE tenants ADD COLUMN plan_id uuid REFERENCES plans(id);
UPDATE tenants SET plan_id = (SELECT id FROM plans WHERE name = 'starter') WHERE plan_id IS NULL;
ALTER TABLE tenants ALTER COLUMN plan_id SET NOT NULL;

ALTER TABLE tenants ADD COLUMN subscription_status text NOT NULL DEFAULT 'trialing'
    CHECK (subscription_status IN ('trialing','active','past_due','cancelled'));
ALTER TABLE tenants ADD COLUMN billing_customer_id text;
ALTER TABLE tenants ADD COLUMN trial_ends_at timestamptz NOT NULL DEFAULT (now() + interval '14 days');

-- ---------------------------------------------------------------------------
-- 9. platform_admins (09_SaaS_Deployment.md §5, §6)
--    Tenant'lar üstü rol; tenants gibi kök tablo, RLS/tenant_id yok. Ayrı DB rolü/route
--    grubu ile erişilir (uygulama katmanında; bkz. 09§5 "ayrı DB rolü kullanır").
-- ---------------------------------------------------------------------------
CREATE TABLE platform_admins (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email         citext NOT NULL UNIQUE,
    password_hash text NOT NULL,
    active        boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now()
);

COMMIT;
