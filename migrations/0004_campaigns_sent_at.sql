-- Migration 0004: campaigns.sent_at
-- BACKLOG.md §A madde 26: kampanya gönderim mekanizması eklendi. `status='sent'` geçişinin
-- ne zaman gerçekleştiğini ayrı bir zaman damgasıyla tutmak için (auditabilite,
-- message_log.sent_at ile aynı desen) eklendi — `scheduled_at` planlanan zamanı, `sent_at`
-- gerçek gönderim/claim zamanını taşır.
--
-- Uygulama: psql -U <user> -d <db> -f migrations/0004_campaigns_sent_at.sql

BEGIN;

ALTER TABLE campaigns ADD COLUMN sent_at timestamptz;

COMMIT;
