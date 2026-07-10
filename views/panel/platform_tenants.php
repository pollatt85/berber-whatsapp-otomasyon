<?php /* /platform — platform admin tenant listesi (09§5): suspend/activate.
   Tenant panel çekirdeğinden (layout.php/Panel) bilinçli olarak ayrı hafif kabuk —
   platform JWT'si farklı (type: platform_admin), yanlışlıkla tenant guard'ına düşmesin. */ ?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tenant'lar — Platform Yönetimi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<nav class="navbar navbar-expand bg-dark border-bottom px-3" data-bs-theme="dark">
  <span class="navbar-brand fw-semibold"><i class="bi bi-shield-lock me-1"></i>Platform Yönetimi</span>
  <div class="ms-auto">
    <button class="btn btn-sm btn-outline-light" onclick="PlatformUI.logout()">
      <i class="bi bi-box-arrow-right me-1"></i>Çıkış</button>
  </div>
</nav>
<main class="container py-4">
  <div id="pageAlert"></div>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Tenant'lar</h1>
    <span class="text-body-secondary small" id="tenantCount"></span>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead><tr>
          <th>İşletme</th><th>Durum</th><th class="d-none d-md-table-cell">Abonelik</th>
          <th class="d-none d-md-table-cell">WhatsApp</th>
          <th class="d-none d-lg-table-cell">Kayıt</th><th class="text-end">İşlem</th>
        </tr></thead>
        <tbody id="tenantRows">
          <tr><td colspan="6" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</main>
<script>
// Panel çekirdeğinin platform karşılığı — ayrı anahtar, ayrı login yolu, type claim kontrolü.
const PlatformUI = {
  TOKEN_KEY: 'platform_jwt',

  token() { return localStorage.getItem(this.TOKEN_KEY); },

  claims() {
    try {
      let part = this.token().split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
      part += '='.repeat((4 - part.length % 4) % 4);
      return JSON.parse(atob(part));
    } catch (e) { return null; }
  },

  logout() {
    localStorage.removeItem(this.TOKEN_KEY);
    location.href = '/platform/login';
  },

  guard() {
    const c = this.claims();
    if (!c || c.type !== 'platform_admin' || (c.exp && c.exp * 1000 < Date.now())) this.logout();
  },

  async api(method, path, body) {
    const res = await fetch(path, {
      method,
      headers: {
        'Authorization': 'Bearer ' + this.token(),
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    if (res.status === 401 || res.status === 403) { this.logout(); throw new Error('unauthorized'); }
    let json = {};
    try { json = await res.json(); } catch (e) { /* boş gövde */ }
    return { status: res.status, ok: res.ok, body: json };
  },

  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  },

  alert(message, type = 'danger') {
    document.getElementById('pageAlert').innerHTML =
      '<div class="alert alert-' + type + ' alert-dismissible fade show">' + this.esc(message) +
      '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    if (type === 'success') setTimeout(() => { document.getElementById('pageAlert').innerHTML = ''; }, 3000);
  },
};

PlatformUI.guard();

const TENANT_STATUS = {
  active: { label: 'Aktif', badge: 'success' },
  suspended: { label: 'Askıda', badge: 'warning' },
  cancelled: { label: 'İptal', badge: 'secondary' },
};
const WA_STATUS = {
  pending: { label: 'Bekliyor', badge: 'warning' },
  connected: { label: 'Bağlı', badge: 'success' },
  disconnected: { label: 'Kopuk', badge: 'danger' },
};
const badge = (map, v) => {
  const s = map[v] || { label: v, badge: 'light' };
  return '<span class="badge text-bg-' + s.badge + '">' + PlatformUI.esc(s.label) + '</span>';
};

async function loadTenants() {
  const rows = document.getElementById('tenantRows');
  const res = await PlatformUI.api('GET', '/platform/tenants');
  if (!res.ok) {
    PlatformUI.alert(res.body.message || 'Tenant listesi yüklenemedi.');
    rows.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Hata oluştu.</td></tr>';
    return;
  }
  const tenants = res.body.data;
  document.getElementById('tenantCount').textContent = tenants.length + ' tenant';
  if (tenants.length === 0) {
    rows.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-4">Henüz tenant yok.</td></tr>';
    return;
  }
  rows.innerHTML = tenants.map(t => {
    const action = t.status === 'active'
      ? '<button class="btn btn-sm btn-outline-warning" onclick="setStatus(\'' + t.id + '\', \'suspended\')">' +
        '<i class="bi bi-pause-circle me-1"></i>Askıya Al</button>'
      : '<button class="btn btn-sm btn-outline-success" onclick="setStatus(\'' + t.id + '\', \'active\')">' +
        '<i class="bi bi-play-circle me-1"></i>Aktifleştir</button>';
    return '<tr data-tenant="' + t.id + '">' +
      '<td class="fw-medium">' + PlatformUI.esc(t.business_name) + '</td>' +
      '<td>' + badge(TENANT_STATUS, t.status) + '</td>' +
      '<td class="d-none d-md-table-cell">' + PlatformUI.esc(t.subscription_status ?? '—') + '</td>' +
      '<td class="d-none d-md-table-cell">' + badge(WA_STATUS, t.whatsapp_status) + '</td>' +
      '<td class="d-none d-lg-table-cell">' + new Date(t.created_at).toLocaleDateString('tr-TR') + '</td>' +
      '<td class="text-end">' + action + '</td></tr>';
  }).join('');
}

async function setStatus(id, status) {
  const res = await PlatformUI.api('PATCH', '/platform/tenants/' + id, { status });
  if (res.ok) {
    PlatformUI.alert('"' + res.body.data.business_name + '" durumu güncellendi: ' +
      (TENANT_STATUS[status] || { label: status }).label, 'success');
    loadTenants();
  } else {
    PlatformUI.alert(res.body.message || 'Durum güncellenemedi.');
  }
}

loadTenants();
</script>
</body>
</html>
