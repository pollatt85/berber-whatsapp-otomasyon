<?php

declare(strict_types=1);

/**
 * Yerel geliştirme seed'i — panel ekranlarını gerçek veriyle test edebilmek için idempotent
 * bir dev tenant'ı + örnek işletme verisi oluşturur. ÜRETİMDE ASLA ÇALIŞTIRILMAZ.
 *
 * Kullanım: php scripts/dev_seed.php
 * Giriş:    dev@berber.local / DevPassw0rd!  (owner)
 *
 * İkinci kez çalıştırmak güvenlidir: tenant phone_number_id ('dev-panel-000') üzerinden
 * bulunur, varsa yeniden kullanılır; alt kayıtlar yalnızca yoksa eklenir.
 */

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Config\Env;
use App\Database\Connection;
use App\Support\TokenCipher;

Env::load(dirname(__DIR__) . '/.env');

$db = Connection::service(); // BYPASSRLS — tenant satırı oluşturmak için gerekli
$db->beginTransaction();

// 1. Tenant
$stmt = $db->prepare("SELECT id FROM tenants WHERE phone_number_id = 'dev-panel-000'");
$stmt->execute();
$tenantId = $stmt->fetchColumn();

if ($tenantId === false) {
    $stmt = $db->prepare(
        "INSERT INTO tenants (business_name, phone_number_id, waba_id, access_token_encrypted,
                              webhook_verify_token, whatsapp_status, plan_id)
         VALUES ('Dev Berber', 'dev-panel-000', 'dev-waba-000', :token, :verify, 'pending',
                 (SELECT id FROM plans WHERE name = 'pro'))
         RETURNING id"
    );
    $stmt->bindValue('token', TokenCipher::encrypt('dev-fake-token', Env::required('APP_ENCRYPTION_KEY')), PDO::PARAM_LOB);
    $stmt->bindValue('verify', bin2hex(random_bytes(16)));
    $stmt->execute();
    $tenantId = $stmt->fetchColumn();
    echo "Tenant oluşturuldu: {$tenantId}\n";
} else {
    echo "Tenant zaten var: {$tenantId}\n";
}

// 2. Owner kullanıcı
$stmt = $db->prepare('SELECT id FROM users WHERE tenant_id = :t AND email = :e');
$stmt->execute(['t' => $tenantId, 'e' => 'dev@berber.local']);
if ($stmt->fetchColumn() === false) {
    $stmt = $db->prepare(
        "INSERT INTO users (tenant_id, email, password_hash, role)
         VALUES (:t, 'dev@berber.local', :hash, 'owner')"
    );
    $stmt->execute(['t' => $tenantId, 'hash' => password_hash('DevPassw0rd!', PASSWORD_DEFAULT)]);
    echo "Kullanıcı oluşturuldu: dev@berber.local / DevPassw0rd!\n";
} else {
    echo "Kullanıcı zaten var: dev@berber.local\n";
}

// 3. Hizmetler
$services = [
    ['Saç Kesimi', 30, '350.00'],
    ['Sakal Tıraşı', 20, '200.00'],
    ['Saç + Sakal', 45, '500.00'],
];
$serviceIds = [];
foreach ($services as [$name, $duration, $price]) {
    $stmt = $db->prepare('SELECT id FROM services WHERE tenant_id = :t AND name = :n');
    $stmt->execute(['t' => $tenantId, 'n' => $name]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        $stmt = $db->prepare(
            'INSERT INTO services (tenant_id, name, duration_minutes, price)
             VALUES (:t, :n, :d, :p) RETURNING id'
        );
        $stmt->execute(['t' => $tenantId, 'n' => $name, 'd' => $duration, 'p' => $price]);
        $id = $stmt->fetchColumn();
    }
    $serviceIds[$name] = $id;
}
echo 'Hizmetler hazır: ' . implode(', ', array_keys($serviceIds)) . "\n";

// 4. Personel
$staffIds = [];
foreach (['Ahmet Usta', 'Mehmet Kalfa'] as $name) {
    $stmt = $db->prepare('SELECT id FROM staff WHERE tenant_id = :t AND name = :n');
    $stmt->execute(['t' => $tenantId, 'n' => $name]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        $stmt = $db->prepare('INSERT INTO staff (tenant_id, name) VALUES (:t, :n) RETURNING id');
        $stmt->execute(['t' => $tenantId, 'n' => $name]);
        $id = $stmt->fetchColumn();
    }
    $staffIds[$name] = $id;
}
echo 'Personel hazır: ' . implode(', ', array_keys($staffIds)) . "\n";

// 5. Personel-hizmet ataması (Ahmet hepsi, Mehmet yalnızca kesim/tıraş)
$assign = $db->prepare(
    'INSERT INTO staff_services (tenant_id, staff_id, service_id)
     VALUES (:t, :st, :sv) ON CONFLICT DO NOTHING'
);
foreach ($serviceIds as $svId) {
    $assign->execute(['t' => $tenantId, 'st' => $staffIds['Ahmet Usta'], 'sv' => $svId]);
}
foreach (['Saç Kesimi', 'Sakal Tıraşı'] as $name) {
    $assign->execute(['t' => $tenantId, 'st' => $staffIds['Mehmet Kalfa'], 'sv' => $serviceIds[$name]]);
}

// 6. Çalışma saatleri (Salı-Pazar 09-19, Pazartesi kapalı — day_of_week 0=Pazar) + mola
foreach ($staffIds as $staffId) {
    $stmt = $db->prepare('SELECT count(*) FROM working_hours WHERE tenant_id = :t AND staff_id = :s');
    $stmt->execute(['t' => $tenantId, 's' => $staffId]);
    if ((int) $stmt->fetchColumn() === 0) {
        $wh = $db->prepare(
            'INSERT INTO working_hours (tenant_id, staff_id, day_of_week, start_time, end_time)
             VALUES (:t, :s, :d, :st, :en)'
        );
        foreach ([0, 2, 3, 4, 5, 6] as $day) {
            $wh->execute(['t' => $tenantId, 's' => $staffId, 'd' => $day, 'st' => '09:00', 'en' => '19:00']);
        }
        $br = $db->prepare(
            'INSERT INTO breaks (tenant_id, staff_id, day_of_week, start_time, end_time)
             VALUES (:t, :s, :d, :st, :en)'
        );
        foreach ([0, 2, 3, 4, 5, 6] as $day) {
            $br->execute(['t' => $tenantId, 's' => $staffId, 'd' => $day, 'st' => '12:30', 'en' => '13:00']);
        }
    }
}
echo "Çalışma saatleri hazır.\n";

// 7. Müşteriler + bugüne randevular (pending + confirmed — panel aksiyon testi için)
$customerIds = [];
foreach ([['905551110001', 'Ali Veli'], ['905551110002', 'Can Demir']] as [$phone, $name]) {
    $stmt = $db->prepare('SELECT id FROM customers WHERE tenant_id = :t AND whatsapp_number = :w');
    $stmt->execute(['t' => $tenantId, 'w' => $phone]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        $stmt = $db->prepare(
            'INSERT INTO customers (tenant_id, whatsapp_number, name) VALUES (:t, :w, :n) RETURNING id'
        );
        $stmt->execute(['t' => $tenantId, 'w' => $phone, 'n' => $name]);
        $id = $stmt->fetchColumn();
    }
    $customerIds[] = $id;
}

$stmt = $db->prepare('SELECT count(*) FROM appointments WHERE tenant_id = :t');
$stmt->execute(['t' => $tenantId]);
if ((int) $stmt->fetchColumn() === 0) {
    $appt = $db->prepare(
        "INSERT INTO appointments (tenant_id, customer_id, staff_id, service_id, time_range, status)
         VALUES (:t, :c, :st, :sv,
                 tstzrange(current_date + :start::time, current_date + :end::time), :status)"
    );
    $appt->execute([
        't' => $tenantId, 'c' => $customerIds[0], 'st' => $staffIds['Ahmet Usta'],
        'sv' => $serviceIds['Saç Kesimi'], 'start' => '10:00', 'end' => '10:30', 'status' => 'pending',
    ]);
    $appt->execute([
        't' => $tenantId, 'c' => $customerIds[1], 'st' => $staffIds['Mehmet Kalfa'],
        'sv' => $serviceIds['Sakal Tıraşı'], 'start' => '11:00', 'end' => '11:20', 'status' => 'confirmed',
    ]);
    echo "Bugüne 2 randevu eklendi (pending + confirmed).\n";
} else {
    echo "Randevular zaten var, eklenmedi.\n";
}

// 8. Mesaj logu (/panel/messages/log ekran testi için — inbound/outbound + failed örneği)
$stmt = $db->prepare('SELECT count(*) FROM message_log WHERE tenant_id = :t');
$stmt->execute(['t' => $tenantId]);
if ((int) $stmt->fetchColumn() === 0) {
    $msg = $db->prepare(
        "INSERT INTO message_log (tenant_id, customer_id, direction, content, status, meta_error_code, sent_at)
         VALUES (:t, :c, :dir, :content, :status, :err, now() - :age::interval)"
    );
    $samples = [
        [$customerIds[0], 'inbound', ['text' => 'Merhaba, yarın için randevu almak istiyorum'], 'sent', null, '3 hours'],
        [$customerIds[0], 'outbound', ['text' => 'Hangi hizmeti istersiniz? 1) Saç Kesimi 2) Sakal Tıraşı 3) Saç + Sakal'], 'delivered', null, '3 hours'],
        [$customerIds[0], 'inbound', ['text' => '1'], 'sent', null, '2 hours'],
        [$customerIds[1], 'outbound', ['text' => 'Randevunuz onaylandı: yarın 11:00, Mehmet Kalfa'], 'read', null, '1 day'],
        [$customerIds[1], 'outbound', ['template' => 'reminder_24h'], 'failed', '131047', '5 hours'],
    ];
    foreach ($samples as [$cid, $dir, $content, $status, $err, $age]) {
        $msg->execute([
            't' => $tenantId, 'c' => $cid, 'dir' => $dir,
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE),
            'status' => $status, 'err' => $err, 'age' => $age,
        ]);
    }
    echo "Mesaj logu örnekleri eklendi (5 satır, 1 failed).\n";
} else {
    echo "Mesaj logu zaten dolu, eklenmedi.\n";
}

// 9. Mesaj şablonları (/panel/messages/templates + kampanya şablon seçimi testi için)
$templates = [
    // [internal_name, meta_template_name, template_type, variables, active]
    ['reminder_24h', 'randevu_hatirlatma_24s', 'reminder', ['musteri_adi', 'saat'], true],
    ['appointment_confirmed', 'randevu_onay', 'confirmation', ['musteri_adi', 'tarih', 'saat'], true],
    ['appointment_cancelled', 'randevu_iptal', 'cancellation', ['musteri_adi'], true],
    ['campaign_discount', 'kampanya_indirim', 'campaign', ['musteri_adi', 'indirim_orani'], true],
    ['campaign_expired', 'kampanya_eski', 'campaign', [], false],
    ['welcome_misc', 'hosgeldin', 'other', [], true],
];
$tpl = $db->prepare(
    'INSERT INTO message_templates (tenant_id, internal_name, meta_template_name, template_type, variables, active)
     VALUES (:t, :i, :m, :ty, :v, :a)
     ON CONFLICT (tenant_id, internal_name) DO NOTHING'
);
foreach ($templates as [$internal, $meta, $type, $vars, $active]) {
    $tpl->bindValue('t', $tenantId);
    $tpl->bindValue('i', $internal);
    $tpl->bindValue('m', $meta);
    $tpl->bindValue('ty', $type);
    $tpl->bindValue('v', json_encode($vars, JSON_UNESCAPED_UNICODE));
    $tpl->bindValue('a', $active, PDO::PARAM_BOOL);
    $tpl->execute();
}
echo "Mesaj şablonları hazır (6 örnek, 2'si campaign).\n";

// 10. Platform admin (/platform UI girişi — tenant'lar üstü, 09§5; panel JWT'sinden ayrı)
$stmt = $db->prepare('SELECT id FROM platform_admins WHERE email = :e');
$stmt->execute(['e' => 'platform@berber.local']);
if ($stmt->fetchColumn() === false) {
    $stmt = $db->prepare(
        "INSERT INTO platform_admins (email, password_hash) VALUES ('platform@berber.local', :hash)"
    );
    $stmt->execute(['hash' => password_hash('PlatformDev1!', PASSWORD_DEFAULT)]);
    echo "Platform admin oluşturuldu: platform@berber.local / PlatformDev1!\n";
} else {
    echo "Platform admin zaten var: platform@berber.local\n";
}

$db->commit();
echo "\nSeed tamam. Panel girişi: http://localhost:8000/panel/login — dev@berber.local / DevPassw0rd!\n";
echo "Platform admin: http://localhost:8000/platform/login — platform@berber.local / PlatformDev1!\n";
