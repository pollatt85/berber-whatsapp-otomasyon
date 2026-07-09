-- Migration 0003: message_log inbound status
-- BACKLOG.md §A madde 28: inbound müşteri mesajları hiç loglanmıyordu; loglama eklendiğinde
-- inbound satırlar için 'sent/delivered/read/failed' anlamsız — 'received' eklendi.
--
-- Uygulama: psql -U <user> -d <db> -f migrations/0003_message_log_inbound_status.sql

BEGIN;

ALTER TABLE message_log DROP CONSTRAINT message_log_status_check;
ALTER TABLE message_log ADD CONSTRAINT message_log_status_check
    CHECK (status IN ('received','sent','delivered','read','failed'));

COMMIT;
