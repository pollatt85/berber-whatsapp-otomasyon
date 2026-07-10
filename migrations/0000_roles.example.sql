-- Örnek/şablon dosyadır — otomatik migration turunda ÇALIŞTIRILMAZ.
-- Superuser (postgres) ile elle, ortam kurulumunda bir kez uygulanır; şifreler burada
-- düz metin bırakılmaz, gerçek değerler yalnızca .env'de tutulur (09_SaaS_Deployment.md §1).
--
-- İki rol ayrımının nedeni (02_Database_Design.md §5, 03_Backend_API.md §2.1, 09§5):
--   berber_app     -> standart istekler; RLS/FORCE ROW LEVEL SECURITY bu role de uygulanır.
--   berber_service -> yalnızca tenant henüz çözülmemişken (login, resolve-tenant) veya
--                     n8n'in tüm-tenant tarayan internal endpoint'lerinde (04§5/§6) ve
--                     platform admin panelinde (09§5) kullanılır; BYPASSRLS taşır.
--                     Uygulama kodu bu rolü yalnızca belirtilen dar endpoint setinde seçer.

CREATE ROLE berber_app LOGIN PASSWORD 'change_me' NOBYPASSRLS;
CREATE ROLE berber_service LOGIN PASSWORD 'change_me' BYPASSRLS;

GRANT CONNECT ON DATABASE berber_saas TO berber_app, berber_service;
GRANT USAGE ON SCHEMA public TO berber_app, berber_service;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO berber_app, berber_service;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO berber_app, berber_service;
