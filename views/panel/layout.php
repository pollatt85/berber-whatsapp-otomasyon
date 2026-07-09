<?php
/**
 * Ortak panel kabuğu (06_Admin_Panel.md §1): sidebar + topbar, Bootstrap 5, mobilde offcanvas.
 * Sayfa şablonları şu değişkenleri tanımlayıp bu dosyayı require eder:
 *   $title (string), $active (string, sidebar anahtarı), $content (HTML), $script (JS).
 */
$title = $title ?? 'Panel';
$active = $active ?? '';
$content = $content ?? '';
$script = $script ?? '';

$nav = [
    ['key' => 'dashboard', 'href' => '/panel/dashboard', 'icon' => 'speedometer2', 'label' => 'Özet'],
    ['key' => 'appointments', 'href' => '/panel/appointments', 'icon' => 'calendar-check', 'label' => 'Randevular'],
    ['key' => 'services', 'href' => '/panel/services', 'icon' => 'scissors', 'label' => 'Hizmetler'],
    ['key' => 'staff', 'href' => '/panel/staff', 'icon' => 'people', 'label' => 'Personel'],
    ['key' => 'customers', 'href' => '/panel/customers', 'icon' => 'person-lines-fill', 'label' => 'Müşteriler'],
    ['key' => 'messages_log', 'href' => '/panel/messages/log', 'icon' => 'chat-dots', 'label' => 'Mesaj Logu'],
    ['key' => 'messages_templates', 'href' => '/panel/messages/templates', 'icon' => 'card-text', 'label' => 'Şablonlar'],
    ['key' => 'messages_campaigns', 'href' => '/panel/messages/campaigns', 'icon' => 'megaphone', 'label' => 'Kampanyalar'],
    ['key' => 'reports', 'href' => '/panel/reports', 'icon' => 'bar-chart', 'label' => 'Raporlar'],
    ['key' => 'settings_business', 'href' => '/panel/settings/business', 'icon' => 'shop', 'label' => 'İşletme'],
    ['key' => 'settings_whatsapp', 'href' => '/panel/settings/whatsapp', 'icon' => 'whatsapp', 'label' => 'WhatsApp'],
    ['key' => 'settings_reminders', 'href' => '/panel/settings/reminders', 'icon' => 'bell', 'label' => 'Hatırlatmalar'],
    ['key' => 'settings_ai', 'href' => '/panel/settings/ai', 'icon' => 'robot', 'label' => 'AI Asistan'],
];

$sidebarLinks = '';
foreach ($nav as $item) {
    $cls = $item['key'] === $active ? 'active' : 'link-body-emphasis';
    $sidebarLinks .= '<li class="nav-item"><a class="nav-link ' . $cls . '" href="' . $item['href'] . '">'
        . '<i class="bi bi-' . $item['icon'] . ' me-2"></i>' . $item['label'] . '</a></li>';
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — Berber Paneli</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background: var(--bs-tertiary-bg); }
  .sidebar .nav-link.active { background: var(--bs-primary); color: #fff; border-radius: .375rem; }
  .sidebar .nav-link { color: var(--bs-body-color); }
  @media (min-width: 992px) { .sidebar { min-height: calc(100vh - 56px); } }
</style>
</head>
<body>
<nav class="navbar navbar-expand bg-body border-bottom sticky-top px-3">
  <button class="btn btn-outline-secondary d-lg-none me-2" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-label="Menü">
    <i class="bi bi-list"></i>
  </button>
  <a class="navbar-brand fw-semibold" href="/panel/dashboard"><i class="bi bi-scissors me-1"></i><span id="brandName">Berber Paneli</span></a>
  <div class="ms-auto d-flex align-items-center gap-3">
    <span class="text-body-secondary small d-none d-sm-inline" id="userEmail"></span>
    <button class="btn btn-sm btn-outline-danger" onclick="Panel.logout()"><i class="bi bi-box-arrow-right me-1"></i>Çıkış</button>
  </div>
</nav>

<div class="container-fluid">
  <div class="row">
    <div class="col-lg-2 p-0">
      <div class="offcanvas-lg offcanvas-start sidebar bg-body border-end" id="sidebarOffcanvas">
        <div class="offcanvas-header d-lg-none">
          <h5 class="offcanvas-title">Menü</h5>
          <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebarOffcanvas"></button>
        </div>
        <div class="offcanvas-body p-3">
          <ul class="nav nav-pills flex-column gap-1"><?= $sidebarLinks ?></ul>
        </div>
      </div>
    </div>
    <main class="col-lg-10 p-3 p-lg-4">
      <div id="pageAlert"></div>
      <?= $content ?>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Ortak panel çekirdeği (06§2): JWT localStorage'da, 401'de sessizce /panel/login'e dönülür.
const Panel = {
  TOKEN_KEY: 'panel_jwt',

  token() { return localStorage.getItem(this.TOKEN_KEY); },

  claims() {
    try {
      // JWT payload base64url kodludur (-/_ + eksik padding) — atob öncesi normalize edilir.
      let part = this.token().split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
      part += '='.repeat((4 - part.length % 4) % 4);
      return JSON.parse(atob(part));
    } catch (e) { return null; }
  },

  logout() {
    localStorage.removeItem(this.TOKEN_KEY);
    location.href = '/panel/login';
  },

  guard() {
    const c = this.claims();
    if (!c || (c.exp && c.exp * 1000 < Date.now())) this.logout();
  },

  // JSON API çağrısı. 401 → login'e yönlendirir; diğer hatalar {status, body} olarak döner.
  async api(method, path, body) {
    const res = await fetch(path, {
      method,
      headers: {
        'Authorization': 'Bearer ' + this.token(),
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    if (res.status === 401) { this.logout(); throw new Error('unauthorized'); }
    let json = {};
    try { json = await res.json(); } catch (e) { /* boş gövde */ }
    return { status: res.status, ok: res.ok, body: json };
  },

  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  },

  alert(message, type = 'danger', targetId = 'pageAlert') {
    const el = document.getElementById(targetId);
    if (!el) return;
    el.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show">' +
      this.esc(message) +
      '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    if (type === 'success') setTimeout(() => { el.innerHTML = ''; }, 3000);
  },

  // "["2026-07-04 10:00:00+03","2026-07-04 10:30:00+03")" biçimli tstzrange → {start, end}.
  // Postgres ofseti "+03" yazar; JS Date ISO için "+03:00" ister — normalize edilir.
  parseRange(range) {
    const m = String(range).match(/"([^"]+)","([^"]+)"/);
    if (!m) return null;
    const norm = s => {
      s = s.replace(' ', 'T');
      return /[+-]\d{2}$/.test(s) ? s + ':00' : s;
    };
    return { start: new Date(norm(m[1])), end: new Date(norm(m[2])) };
  },

  fmtTime(d) { return d.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' }); },
  fmtDate(d) { return d.toLocaleDateString('tr-TR'); },
  todayIso() { return new Date().toLocaleDateString('sv-SE'); },

  STATUS: {
    pending:   { label: 'Onay bekliyor', badge: 'warning' },
    confirmed: { label: 'Onaylı', badge: 'success' },
    cancelled: { label: 'İptal', badge: 'secondary' },
    completed: { label: 'Tamamlandı', badge: 'primary' },
    no_show:   { label: 'Gelmedi', badge: 'dark' },
  },

  statusBadge(status) {
    const s = this.STATUS[status] || { label: status, badge: 'light' };
    return '<span class="badge text-bg-' + s.badge + '">' + this.esc(s.label) + '</span>';
  },

  DAYS: ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'],
};

Panel.guard();
const claims = Panel.claims();
if (claims) document.getElementById('userEmail').textContent = 'Rol: ' + claims.role;
</script>
<script><?= $script ?></script>
</body>
</html>
