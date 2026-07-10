-- Migration 0001: Initial Schema
-- Kaynak: 02_Database_Design.md (tasarım burada değiştirilmedi, olduğu gibi uygulanabilir
-- migration haline getirildi). Sıra: extensions -> tenants (kök) -> bağımlı tablolar ->
-- appointments (exclusion constraint) -> mesajlaşma/AI/webhook tabloları -> RLS politikaları.
-- Uygulama: psql -U <user> -d <db> -f migrations/0001_initial_schema.sql

BEGIN;

-- ---------------------------------------------------------------------------
-- 0. Extensions (02_Database_Design.md §2)
-- ---------------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS pgcrypto;   -- gen_random_uuid()
CREATE EXTENSION IF NOT EXISTS btree_gist; -- exclusion constraint için
CREATE EXTENSION IF NOT EXISTS citext;     -- users.email

-- ---------------------------------------------------------------------------
-- 1. tenants (02 §3.1) — izolasyonun kök tablosu, RLS uygulanmaz
-- ---------------------------------------------------------------------------
CREATE TABLE tenants (
    id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    business_name           text NOT NULL,
    phone_number_id         text NOT NULL UNIQUE,
    waba_id                 text NOT NULL,
    access_token_encrypted  bytea NOT NULL,
    webhook_verify_token    text NOT NULL,
    timezone                text NOT NULL DEFAULT 'Europe/Istanbul',
    subscription_plan       text NOT NULL DEFAULT 'trial'
                             CHECK (subscription_plan IN ('trial','basic','pro','enterprise')),
    status                  text NOT NULL DEFAULT 'active'
                             CHECK (status IN ('active','suspended','cancelled')),
    logo_url                text,
    address                 text,
    location_lat            double precision,
    location_lng            double precision,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- 2. users (02 §3.2)
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id     uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    email         citext NOT NULL,
    password_hash text NOT NULL,
    role          text NOT NULL DEFAULT 'owner'
                  CHECK (role IN ('owner','manager','staff')),
    active        boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, email)
);

-- ---------------------------------------------------------------------------
-- 3. staff (02 §3.3)
-- ---------------------------------------------------------------------------
CREATE TABLE staff (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id  uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name       text NOT NULL,
    phone      text,
    photo_url  text,
    active     boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- 4. services (02 §3.4)
-- ---------------------------------------------------------------------------
CREATE TABLE services (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id        uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name             text NOT NULL,
    duration_minutes integer NOT NULL CHECK (duration_minutes > 0),
    price            numeric(10,2) NOT NULL CHECK (price >= 0),
    active           boolean NOT NULL DEFAULT true,
    created_at       timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- 5. staff_services (02 §3.5, N:N)
-- ---------------------------------------------------------------------------
CREATE TABLE staff_services (
    tenant_id  uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    staff_id   uuid NOT NULL REFERENCES staff(id) ON DELETE CASCADE,
    service_id uuid NOT NULL REFERENCES services(id) ON DELETE CASCADE,
    PRIMARY KEY (staff_id, service_id)
);

-- ---------------------------------------------------------------------------
-- 6. working_hours (02 §3.6)
-- ---------------------------------------------------------------------------
CREATE TABLE working_hours (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id  uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    staff_id   uuid NOT NULL REFERENCES staff(id) ON DELETE CASCADE,
    day_of_week smallint NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),
    start_time  time NOT NULL,
    end_time    time NOT NULL CHECK (end_time > start_time),
    UNIQUE (staff_id, day_of_week, start_time)
);

-- ---------------------------------------------------------------------------
-- 7. breaks (02 §3.7)
-- ---------------------------------------------------------------------------
CREATE TABLE breaks (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id   uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    staff_id    uuid NOT NULL REFERENCES staff(id) ON DELETE CASCADE,
    day_of_week smallint NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),
    start_time  time NOT NULL,
    end_time    time NOT NULL CHECK (end_time > start_time)
);

-- ---------------------------------------------------------------------------
-- 8. holidays (02 §3.8)
-- ---------------------------------------------------------------------------
CREATE TABLE holidays (
    id        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    staff_id  uuid REFERENCES staff(id) ON DELETE CASCADE,
    date_range daterange NOT NULL,
    reason    text,
    UNIQUE (tenant_id, staff_id, date_range)
);

-- ---------------------------------------------------------------------------
-- 9. customers (02 §3.9)
-- ---------------------------------------------------------------------------
CREATE TABLE customers (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id    uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    whatsapp_number text NOT NULL,
    name         text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, whatsapp_number)
);

-- ---------------------------------------------------------------------------
-- 10. appointments (02 §3.10) — exclusion constraint çakışmayı DB seviyesinde engeller
-- ---------------------------------------------------------------------------
CREATE TABLE appointments (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id   uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
    staff_id    uuid NOT NULL REFERENCES staff(id) ON DELETE RESTRICT,
    service_id  uuid NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
    time_range  tstzrange NOT NULL,
    status      text NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending','confirmed','cancelled','completed','no_show')),
    notes       text,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),

    EXCLUDE USING gist (
        tenant_id WITH =,
        staff_id  WITH =,
        time_range WITH &&
    ) WHERE (status IN ('pending','confirmed'))
);

CREATE INDEX idx_appointments_tenant_time ON appointments (tenant_id, time_range);
CREATE INDEX idx_appointments_customer ON appointments (customer_id);

-- ---------------------------------------------------------------------------
-- 11. message_templates (02 §3.11)
-- ---------------------------------------------------------------------------
CREATE TABLE message_templates (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id         uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    internal_name     text NOT NULL,
    meta_template_name text NOT NULL,
    template_type     text NOT NULL
                      CHECK (template_type IN ('reminder','confirmation','cancellation','campaign','other')),
    variables         jsonb NOT NULL DEFAULT '[]',
    active            boolean NOT NULL DEFAULT true,
    UNIQUE (tenant_id, internal_name)
);

-- ---------------------------------------------------------------------------
-- 12. message_log (02 §3.12)
-- ---------------------------------------------------------------------------
CREATE TABLE message_log (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id      uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_id    uuid REFERENCES customers(id) ON DELETE SET NULL,
    appointment_id uuid REFERENCES appointments(id) ON DELETE SET NULL,
    direction      text NOT NULL CHECK (direction IN ('inbound','outbound')),
    template_id    uuid REFERENCES message_templates(id),
    content        jsonb NOT NULL,
    status         text NOT NULL DEFAULT 'sent'
                   CHECK (status IN ('sent','delivered','read','failed')),
    sent_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_message_log_tenant_time ON message_log (tenant_id, sent_at DESC);

-- ---------------------------------------------------------------------------
-- 13. campaigns (02 §3.13)
-- ---------------------------------------------------------------------------
CREATE TABLE campaigns (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id     uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name          text NOT NULL,
    template_id   uuid NOT NULL REFERENCES message_templates(id),
    target_filter jsonb NOT NULL DEFAULT '{}',
    scheduled_at  timestamptz,
    status        text NOT NULL DEFAULT 'draft'
                  CHECK (status IN ('draft','scheduled','sent','cancelled')),
    created_at    timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- 14. ai_settings (02 §3.14)
-- ---------------------------------------------------------------------------
CREATE TABLE ai_settings (
    tenant_id     uuid PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
    enabled       boolean NOT NULL DEFAULT false,
    tone          text NOT NULL DEFAULT 'friendly',
    knowledge_base jsonb NOT NULL DEFAULT '{}',
    updated_at    timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- 15. webhook_events (02 §3.15) — tenant_id NULL olabilir, RLS uygulanmaz
-- ---------------------------------------------------------------------------
CREATE TABLE webhook_events (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id        uuid REFERENCES tenants(id) ON DELETE SET NULL,
    phone_number_id  text NOT NULL,
    signature_valid  boolean NOT NULL,
    payload          jsonb NOT NULL,
    processed        boolean NOT NULL DEFAULT false,
    received_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_webhook_events_phone ON webhook_events (phone_number_id, received_at DESC);

-- ---------------------------------------------------------------------------
-- 16. Row-Level Security (02 §5) — tenant-scoped tüm tablolara standart politika
-- ---------------------------------------------------------------------------
DO $$
DECLARE
    t text;
BEGIN
    FOREACH t IN ARRAY ARRAY[
        'users','staff','services','staff_services','working_hours','breaks',
        'holidays','customers','appointments','message_templates','message_log',
        'campaigns','ai_settings'
    ]
    LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        EXECUTE format(
            'CREATE POLICY tenant_isolation ON %I
                USING (tenant_id = current_setting(''app.current_tenant'', true)::uuid)
                WITH CHECK (tenant_id = current_setting(''app.current_tenant'', true)::uuid)',
            t
        );
    END LOOP;
END $$;

COMMIT;
