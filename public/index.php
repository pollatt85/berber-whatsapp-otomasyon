<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    // Composer bu makinede henüz kurulmadı (bkz. PROJECT_MEMORY.md) — PSR-4 manuel autoloader.
    spl_autoload_register(function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $relative = substr($class, strlen('App\\'));
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

use App\Config\Env;
use App\Database\Connection;
use App\Http\Controllers\AiRespondController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignScanController;
use App\Http\Controllers\ConversationStateController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InternalScanController;
use App\Http\Controllers\MessageLogController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\PlatformAdminController;
use App\Http\Controllers\PlatformTenantController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffScheduleController;
use App\Http\Controllers\TenantResolutionController;
use App\Http\Controllers\WhatsAppInternalController;
use App\Http\Controllers\WhatsAppFlowController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Middleware\PlatformAdminAuthMiddleware;
use App\Http\Middleware\ServiceHmacMiddleware;
use App\Http\Middleware\TenantContextResolver;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AiSettingsRepository;
use App\Repository\AppointmentRepository;
use App\Repository\AppointmentScanRepository;
use App\Repository\CampaignRepository;
use App\Repository\CampaignScanRepository;
use App\Repository\ConversationStateRepository;
use App\Repository\CustomerRepository;
use App\Repository\PlatformAdminRepository;
use App\Repository\MessageLogRepository;
use App\Repository\MessageTemplateRepository;
use App\Repository\ServiceRepository;
use App\Repository\StaffRepository;
use App\Repository\StaffScheduleRepository;
use App\Repository\TenantRepository;
use App\Repository\TenantSettingsRepository;
use App\Repository\UserRepository;
use App\Repository\WebhookEventRepository;
use App\Service\AvailabilityService;
use App\Support\ClaudeClient;
use App\Support\GeminiClient;
use App\Support\LlmClient;
use App\Support\MetaGraphClient;
use App\Support\RateLimiter;
use App\Support\RedisClient;
use App\Support\WhatsAppNotifier;
use App\Panel\PanelView;
use App\Http\Router;

Env::load(dirname(__DIR__) . '/.env');

/**
 * AI yanıt sağlayıcısını .env'e göre seçer (07§4). Öncelik: Gemini (ücretsiz katman, kullanıcı
 * kararı) → Anthropic (geri dönüş). Hiçbiri yoksa null → AiRespondController fallback('no_api_key').
 */
function resolveLlmClient(): ?LlmClient
{
    $geminiKey = Env::get('GEMINI_API_KEY', '');
    if ($geminiKey !== '') {
        return new GeminiClient($geminiKey, Env::get('GEMINI_MODEL', ''));
    }
    $anthropicKey = Env::get('ANTHROPIC_API_KEY', '');
    if ($anthropicKey !== '') {
        return new ClaudeClient($anthropicKey);
    }

    return null;
}

$request = Request::fromGlobals();
$router = new Router();

// --- Tenant çözülmeden önce çalışan uçlar (Connection::service(), BYPASSRLS) ---

$router->post('/auth/login', function (Request $req) {
    $controller = new AuthController(new UserRepository(Connection::service()));
    return $controller->login($req);
});

$router->post('/internal/resolve-tenant', function (Request $req) {
    ServiceHmacMiddleware::authenticate($req);
    $controller = new TenantResolutionController(new TenantRepository(Connection::service()));
    return $controller->resolve($req);
});

// --- Meta doğrudan çağırır: gelen webhook (05§2, tenant'tan bağımsız, BYPASSRLS) ---

$router->get('/webhook/whatsapp', function (Request $req) {
    $db = Connection::service();
    $controller = new WhatsAppWebhookController(new WebhookEventRepository($db), new TenantRepository($db));
    return $controller->verify($req);
});

$router->post('/webhook/whatsapp', function (Request $req) {
    $db = Connection::service();
    $controller = new WhatsAppWebhookController(new WebhookEventRepository($db), new TenantRepository($db));
    return $controller->receive($req);
});

// --- WhatsApp Flows data-exchange (randevu formu, PHASE_35) — HMAC yok, Meta'nın kendi
// RSA/AES şifrelemesi kimlik doğrulamayı sağlıyor (FlowCrypto). ---
$router->post('/webhook/whatsapp-flow', function (Request $req) {
    $db = Connection::service();
    $controller = new WhatsAppFlowController(
        new ServiceRepository($db),
        new StaffRepository($db),
        new AvailabilityService(new ServiceRepository($db), new StaffRepository($db), new AppointmentRepository($db)),
        new TenantRepository($db)
    );
    return $controller->handle($req);
});

// --- Platform admin route grubu (09§5/§6, BYPASSRLS, tenant'tan bağımsız) ---

$router->post('/platform/auth/login', function (Request $req) {
    $controller = new PlatformAdminController(new PlatformAdminRepository(Connection::service()));
    return $controller->login($req);
});

$router->get('/platform/tenants', function (Request $req) {
    PlatformAdminAuthMiddleware::authenticate($req);
    $controller = new PlatformTenantController(new TenantRepository(Connection::service()));
    return $controller->index($req);
});

$router->patch('/platform/tenants/{id}', function (Request $req, array $args) {
    PlatformAdminAuthMiddleware::authenticate($req);
    $controller = new PlatformTenantController(new TenantRepository(Connection::service()));
    return $controller->updateStatus($req, $args['id']);
});

// --- n8n servis kanalı: tüm-tenant tarama uçları (04§5/§6/§7, BYPASSRLS) ---

$router->get('/internal/appointments-due-for-reminder', function (Request $req) {
    ServiceHmacMiddleware::authenticate($req);
    $controller = new InternalScanController(new AppointmentScanRepository(Connection::service()));
    return $controller->dueForReminder($req);
});

$router->get('/internal/appointments-expired-pending', function (Request $req) {
    ServiceHmacMiddleware::authenticate($req);
    $controller = new InternalScanController(new AppointmentScanRepository(Connection::service()));
    return $controller->expiredPending($req);
});

$router->get('/internal/campaigns-due-for-send', function (Request $req) {
    ServiceHmacMiddleware::authenticate($req);
    $controller = new CampaignScanController(new CampaignScanRepository(Connection::service()));
    return $controller->dueForSend($req);
});

$router->get('/conversation-state', function (Request $req) {
    ServiceHmacMiddleware::authenticate($req);
    $db = Connection::service();
    $controller = new ConversationStateController(new ConversationStateRepository($db), new CustomerRepository($db));
    return $controller->show($req);
});

$router->patch('/conversation-state', function (Request $req) {
    ServiceHmacMiddleware::authenticate($req);
    $db = Connection::service();
    $controller = new ConversationStateController(new ConversationStateRepository($db), new CustomerRepository($db));
    return $controller->update($req);
});

// --- n8n servis kanalı: AI yanıt üretimi (07§2/§4/§5) ---
// tenant_id body'den, HMAC + Connection::service() (/conversation-state deseninin aynısı).
// Tüm repo'lar tenant_id'yi açıkça parametre alır → BYPASSRLS bağlantıda da tek tenant izole.
// rate_limit_per_minute (07§5, BACKLOG §A m.11/16) RateLimiter+RedisClient ile uygulanıyor.
$router->post('/ai/respond', function (Request $req) {
    ServiceHmacMiddleware::authenticate($req);
    $db = Connection::service();
    $redis = new RedisClient(Env::get('REDIS_HOST', '127.0.0.1'), (int) Env::get('REDIS_PORT', '6379'));
    $controller = new AiRespondController(
        new AiSettingsRepository($db),
        new ServiceRepository($db),
        new StaffRepository($db),
        new StaffScheduleRepository($db),
        new MessageLogRepository($db),
        new RateLimiter($redis),
        resolveLlmClient()
    );
    return $controller->respond($req);
});

// --- n8n servis kanalı: WhatsApp gönderim/senkron (05§3/§5/§6/§7) ---

$router->post('/internal/whatsapp/send', function (Request $req) {
    ServiceHmacMiddleware::authenticate($req);
    $db = Connection::service();
    $controller = new WhatsAppInternalController(
        new TenantRepository($db),
        new CustomerRepository($db),
        new MessageLogRepository($db),
        new MessageTemplateRepository($db),
        new MetaGraphClient()
    );
    return $controller->send($req);
});

$router->post('/internal/whatsapp/log-inbound', function (Request $req) {
    ServiceHmacMiddleware::authenticate($req);
    $db = Connection::service();
    $controller = new WhatsAppInternalController(
        new TenantRepository($db),
        new CustomerRepository($db),
        new MessageLogRepository($db),
        new MessageTemplateRepository($db),
        new MetaGraphClient()
    );
    return $controller->logInbound($req);
});

$router->post('/internal/whatsapp/templates/sync', function (Request $req) {
    ServiceHmacMiddleware::authenticate($req);
    $db = Connection::service();
    $controller = new WhatsAppInternalController(
        new TenantRepository($db),
        new CustomerRepository($db),
        new MessageLogRepository($db),
        new MessageTemplateRepository($db),
        new MetaGraphClient()
    );
    return $controller->syncTemplates($req);
});

// --- Tenant-scoped uçlar (JWT panel veya HMAC+body tenant_id ile n8n, 03§2.1/§2.2) ---

$tenantScoped = function (callable $build) {
    return function (Request $req, array $args = []) use ($build) {
        $context = TenantContextResolver::resolve($req);
        $db = Connection::tenant($context['tenant_id']);
        $controller = $build($db);
        return $controller($req, $context['tenant_id'], $args, $context['role']);
    };
};

$router->get('/services', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new ServiceController(new ServiceRepository($db)))->index($r, $tid);
}));
$router->post('/services', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new ServiceController(new ServiceRepository($db)))->store($r, $tid);
}));
$router->put('/services/{id}', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db) {
    return (new ServiceController(new ServiceRepository($db)))->update($r, $tid, $a['id']);
}));
$router->delete('/services/{id}', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db) {
    return (new ServiceController(new ServiceRepository($db)))->destroy($r, $tid, $a['id']);
}));

$router->get('/staff', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new StaffController(new StaffRepository($db)))->index($r, $tid);
}));
$router->post('/staff', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new StaffController(new StaffRepository($db)))->store($r, $tid);
}));
$router->put('/staff/{id}', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db) {
    return (new StaffController(new StaffRepository($db)))->update($r, $tid, $a['id']);
}));
$router->delete('/staff/{id}', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db) {
    return (new StaffController(new StaffRepository($db)))->destroy($r, $tid, $a['id']);
}));

$router->get('/availability', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    $service = new AvailabilityService(new ServiceRepository($db), new StaffRepository($db), new AppointmentRepository($db));
    return (new AvailabilityController($service, new TenantRepository(Connection::service())))->index($r, $tid);
}));

$router->get('/customers', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new CustomerController(new CustomerRepository($db)))->index($r, $tid);
}));
$router->get('/customers/{id}', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db) {
    return (new CustomerController(new CustomerRepository($db)))->show($r, $tid, $a['id']);
}));
$router->post('/customers', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new CustomerController(new CustomerRepository($db)))->store($r, $tid);
}));
$router->delete('/customers/{id}', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) use ($db) {
    return (new CustomerController(new CustomerRepository($db)))->destroy($r, $tid, $a['id'], $role);
}));

$router->get('/messages/log', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new MessageLogController(new MessageLogRepository($db)))->index($r, $tid);
}));

$router->get('/messages/templates', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new MessageTemplateController(new MessageTemplateRepository($db)))->index($r, $tid);
}));

// Kampanyalar (06§7) — CRUD; gönderim bu fazda yok, durum makinesi draft/scheduled/cancelled.
$campaignController = fn ($db) => new CampaignController(new CampaignRepository($db), new MessageTemplateRepository($db));

$router->get('/campaigns', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db, $campaignController) {
    return $campaignController($db)->index($r, $tid);
}));
$router->post('/campaigns', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) use ($db, $campaignController) {
    return $campaignController($db)->store($r, $tid, $a, $role);
}));
$router->patch('/campaigns/{id}', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) use ($db, $campaignController) {
    return $campaignController($db)->update($r, $tid, $a['id'], $role);
}));
$router->patch('/campaigns/{id}/cancel', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) use ($db, $campaignController) {
    return $campaignController($db)->cancel($r, $tid, $a['id'], $role);
}));

// Panel JWT'siyle şablon senkronu (06§7) — HMAC kanalındaki /internal/whatsapp/templates/sync'in
// tenant-scoped sarmalayıcısı. tenant_id JWT'den gelir, body'den asla okunmaz; token çözümü
// BYPASSRLS istediği için controller service bağlantısıyla kurulur.
$router->post('/messages/templates/sync', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) {
    if (!in_array($role, ['owner', 'manager'], true)) {
        throw new \App\Http\ApiException('forbidden', 'Role does not permit this action.', 403);
    }
    $sdb = Connection::service();
    $controller = new WhatsAppInternalController(
        new TenantRepository($sdb),
        new CustomerRepository($sdb),
        new MessageLogRepository($sdb),
        new MessageTemplateRepository($sdb),
        new MetaGraphClient()
    );
    return $controller->syncTemplates($r, $tid);
}));

// WhatsApp bağlantı/health uçları (05§1, BACKLOG m.17/25/29) — panel JWT kanalı; token
// çözümü/yazımı BYPASSRLS istediği için controller service bağlantısıyla kurulur
// (/messages/templates/sync sarmalayıcısıyla aynı desen).
$whatsAppInternal = function () {
    $sdb = Connection::service();
    return new WhatsAppInternalController(
        new TenantRepository($sdb),
        new CustomerRepository($sdb),
        new MessageLogRepository($sdb),
        new MessageTemplateRepository($sdb),
        new MetaGraphClient()
    );
};

$router->get('/settings/whatsapp/health', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($whatsAppInternal) {
    return $whatsAppInternal()->health($r, $tid);
}));
$router->post('/settings/whatsapp/connect', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) use ($whatsAppInternal) {
    return $whatsAppInternal()->connect($r, $tid, $role);
}));

$router->post('/settings/logo', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) use ($db) {
    return (new SettingsController(new TenantSettingsRepository($db)))->uploadLogo($r, $tid, $a, $role);
}));
$router->get('/settings/ai', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new AiSettingsController(new AiSettingsRepository($db)))->show($r, $tid);
}));
$router->patch('/settings/ai', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) use ($db) {
    return (new AiSettingsController(new AiSettingsRepository($db)))->update($r, $tid, $a, $role);
}));
$router->get('/settings', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new SettingsController(new TenantSettingsRepository($db)))->show($r, $tid);
}));
$router->patch('/settings', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) use ($db) {
    return (new SettingsController(new TenantSettingsRepository($db)))->update($r, $tid, $a, $role);
}));

// --- Personel programı: çalışma saatleri + molalar + tatiller + hizmet ataması (06§5) ---

$staffSchedule = fn ($db) => new StaffScheduleController(new StaffScheduleRepository($db), new StaffRepository($db));

$router->get('/staff/{id}/schedule', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db, $staffSchedule) {
    return $staffSchedule($db)->show($r, $tid, $a['id']);
}));
$router->put('/staff/{id}/schedule', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db, $staffSchedule) {
    return $staffSchedule($db)->replace($r, $tid, $a['id']);
}));
$router->post('/staff/{id}/holidays', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db, $staffSchedule) {
    return $staffSchedule($db)->addHoliday($r, $tid, $a['id']);
}));
$router->delete('/staff/{id}/holidays/{holidayId}', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db, $staffSchedule) {
    return $staffSchedule($db)->deleteHoliday($r, $tid, $a['id'], $a['holidayId']);
}));
$router->get('/staff/{id}/services', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db, $staffSchedule) {
    return $staffSchedule($db)->services($r, $tid, $a['id']);
}));
$router->put('/staff/{id}/services', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db, $staffSchedule) {
    return $staffSchedule($db)->replaceServices($r, $tid, $a['id']);
}));

$router->get('/appointments', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new AppointmentController(new AppointmentRepository($db), new ServiceRepository($db), new CustomerRepository($db)))->index($r, $tid);
}));
$router->post('/appointments', $tenantScoped(fn ($db) => function (Request $r, string $tid) use ($db) {
    return (new AppointmentController(new AppointmentRepository($db), new ServiceRepository($db), new CustomerRepository($db)))->store($r, $tid);
}));
$appointmentNotifier = fn ($db) => new WhatsAppNotifier(
    new TenantRepository(Connection::service()),
    new CustomerRepository($db),
    new MessageLogRepository($db),
    new MetaGraphClient(),
    new AvailabilityService(new ServiceRepository($db), new StaffRepository($db), new AppointmentRepository($db)),
    new ConversationStateRepository(Connection::service())
);

$router->patch('/appointments/{id}/confirm', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) use ($db, $appointmentNotifier) {
    return (new AppointmentController(new AppointmentRepository($db), new ServiceRepository($db), new CustomerRepository($db), $appointmentNotifier($db)))->confirm($r, $tid, $a['id'], $role);
}));
$router->patch('/appointments/{id}/cancel', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a, string $role) use ($db, $appointmentNotifier) {
    return (new AppointmentController(new AppointmentRepository($db), new ServiceRepository($db), new CustomerRepository($db), $appointmentNotifier($db)))->cancel($r, $tid, $a['id'], $role);
}));
$router->patch('/appointments/{id}/request-reschedule', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db, $appointmentNotifier) {
    return (new AppointmentController(new AppointmentRepository($db), new ServiceRepository($db), new CustomerRepository($db), $appointmentNotifier($db)))->requestReschedule($r, $tid, $a['id']);
}));
$router->patch('/appointments/{id}/complete', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db) {
    return (new AppointmentController(new AppointmentRepository($db), new ServiceRepository($db), new CustomerRepository($db)))->complete($r, $tid, $a['id']);
}));
$router->patch('/appointments/{id}/no-show', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db) {
    return (new AppointmentController(new AppointmentRepository($db), new ServiceRepository($db), new CustomerRepository($db)))->noShow($r, $tid, $a['id']);
}));
$router->patch('/appointments/{id}/reschedule', $tenantScoped(fn ($db) => function (Request $r, string $tid, array $a) use ($db) {
    return (new AppointmentController(new AppointmentRepository($db), new ServiceRepository($db), new CustomerRepository($db)))->reschedule($r, $tid, $a['id']);
}));

// --- Admin Panel sayfaları (06_Admin_Panel.md) ---
// Sunucu yalnızca HTML iskeleti render eder; veri, istemcide localStorage'daki panel JWT'siyle
// fetch edilir (06§2 — 401'de /panel/login'e yönlendirme istemci tarafında).
// Not: 06§1'deki yollar (/login, /dashboard...) API kökleriyle (/services vb.) çakıştığı için
// panel sayfaları /panel öneki altında yaşar.

// Kök URL (/) → panele düşür. Hem doğrudan :8081/ girişini hem de port-80 Apache
// RedirectMatch'inin ($1='/') buraya attığı isteği panele yönlendirir; giriş yoksa
// dashboard'un istemci JS'i 401'de /panel/login'e çevirir (06§2).
$router->get('/', fn (Request $req) => Response::redirect('/panel/dashboard'));

foreach ([
    '/panel' => 'dashboard',
    '/panel/login' => 'login',
    '/panel/dashboard' => 'dashboard',
    '/panel/appointments' => 'appointments',
    '/panel/services' => 'services',
    '/panel/staff' => 'staff',
    '/panel/settings/whatsapp' => 'settings_whatsapp',
    '/panel/settings/reminders' => 'settings_reminders',
    '/panel/customers' => 'customers',
    '/panel/messages/log' => 'messages_log',
    '/panel/messages/templates' => 'messages_templates',
    '/panel/messages/campaigns' => 'messages_campaigns',
    '/panel/reports' => 'reports',
    '/panel/settings/business' => 'settings_business',
    '/panel/settings/ai' => 'settings_ai',
] as $path => $page) {
    $router->get($path, fn (Request $req) => PanelView::render($page));
}

$router->get('/panel/staff/{id}/hours', function (Request $req, array $args) {
    return PanelView::render('staff_hours', ['staffId' => $args['id']]);
});

$router->get('/panel/customers/{id}', function (Request $req, array $args) {
    return PanelView::render('customer_detail', ['customerId' => $args['id']]);
});

// Platform admin UI (09§5) — tenant panelinden ayrı hafif kabuk, ayrı JWT (platform_jwt).
// Sayfa yolu /platform'dur (liste); /platform/tenants GET'i JSON API olarak kalır.
$router->get('/platform', fn (Request $req) => PanelView::render('platform_tenants'));
$router->get('/platform/login', fn (Request $req) => PanelView::render('platform_login'));

try {
    $response = $router->dispatch($request);
} catch (\Throwable $e) {
    error_log($e->getMessage());
    $response = Response::error('internal_error', 'Unexpected server error.', 500);
}

$response->send();
