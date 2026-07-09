<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Env;
use PDO;

/**
 * İki bağlantı modu (02_Database_Design.md §5, 03_Backend_API.md §2.1, 09_SaaS_Deployment.md §5):
 * - tenant(): standart istekler, RLS bu rol için de zorunlu (FORCE ROW LEVEL SECURITY).
 * - service(): BYPASSRLS rolü; yalnızca tenant henüz çözülmemişken (login, resolve-tenant)
 *   veya n8n'in tüm-tenant tarayan internal endpoint'lerinde kullanılır.
 */
final class Connection
{
    private static ?PDO $tenantPdo = null;
    private static ?PDO $servicePdo = null;

    public static function tenant(string $tenantId): PDO
    {
        $pdo = self::baseConnection(
            self::$tenantPdo,
            Env::required('DB_APP_USER'),
            Env::required('DB_APP_PASSWORD')
        );
        self::$tenantPdo = $pdo;

        $stmt = $pdo->prepare('SELECT set_config(\'app.current_tenant\', :tenant_id, false)');
        $stmt->execute(['tenant_id' => $tenantId]);

        return $pdo;
    }

    public static function service(): PDO
    {
        $pdo = self::baseConnection(
            self::$servicePdo,
            Env::required('DB_SERVICE_USER'),
            Env::required('DB_SERVICE_PASSWORD')
        );
        self::$servicePdo = $pdo;

        return $pdo;
    }

    private static function baseConnection(?PDO $existing, string $user, string $password): PDO
    {
        if ($existing !== null) {
            return $existing;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Env::required('DB_HOST'),
            Env::get('DB_PORT', '5432'),
            Env::required('DB_NAME')
        );

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
