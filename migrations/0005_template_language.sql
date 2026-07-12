-- 0005 — message_templates.language (BACKLOG §A: şablon dili sabitlemesi, PHASE_30 yan bulgusu)
-- WhatsAppInternalController::send() şablon dilini sabit 'tr' gönderiyordu; Meta'daki gerçek
-- şablonlar farklı dilde olabilir (yeni WABA'nın tüm onaylı şablonları en_US). Dil artık
-- senkronizasyonda Meta'dan alınıp burada saklanır, gönderimde bu kolon kullanılır.

BEGIN;

ALTER TABLE message_templates ADD COLUMN language text NOT NULL DEFAULT 'tr';

COMMIT;
