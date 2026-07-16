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
    <div class="d-flex align-items-center gap-3">
      <span class="text-body-secondary small" id="tenantCount"></span>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newTenantModal">
        <i class="bi bi-plus-lg me-1"></i>Yeni İşletme</button>
    </div>
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

<!-- A3: Yeni İşletme oluşturma formu -->
<div class="modal fade" id="newTenantModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="newTenantForm" onsubmit="return createTenant(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-shop me-1"></i>Yeni İşletme</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="modalAlert"></div>
        <div class="mb-3">
          <label class="form-label">İşletme adı</label>
          <input type="text" class="form-control" name="business_name" required maxlength="120" placeholder="Örn. Berber Ahmet">
        </div>
        <div class="mb-3">
          <label class="form-label">Plan</label>
          <select class="form-select" name="plan" required>
            <option value="starter">Starter</option>
            <option value="pro" selected>Pro</option>
            <option value="business">Business</option>
          </select>
        </div>
        <hr>
        <p class="text-body-secondary small mb-2">İşletme sahibinin panel giriş bilgileri:</p>
        <div class="mb-3">
          <label class="form-label">Sahip e-posta</label>
          <input type="email" class="form-control" name="owner_email" required placeholder="sahip@isletme.com">
        </div>
        <div class="mb-1">
          <label class="form-label">Sahip parolası</label>
          <input type="text" class="form-control" name="owner_password" required minlength="8" placeholder="En az 8 karakter">
          <div class="form-text">İşletme sahibine iletilecek. En az 8 karakter.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
        <button type="submit" class="btn btn-primary" id="newTenantSubmit">
          <i class="bi bi-check-lg me-1"></i>Oluştur</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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

async function createTenant(ev) {
  ev.preventDefault();
  const form = document.getElementById('newTenantForm');
  const btn = document.getElementById('newTenantSubmit');
  const modalAlert = document.getElementById('modalAlert');
  modalAlert.innerHTML = '';
  const data = Object.fromEntries(new FormData(form).entries());
  btn.disabled = true;
  try {
    const res = await PlatformUI.api('POST', '/platform/tenants', data);
    if (res.ok) {
      bootstrap.Modal.getInstance(document.getElementById('newTenantModal')).hide();
      form.reset();
      PlatformUI.alert('"' + res.body.data.business_name + '" oluşturuldu. Sahibi ' +
        data.owner_email + ' ile giriş yapabilir.', 'success');
      loadTenants();
    } else {
      modalAlert.innerHTML = '<div class="alert alert-danger py-2">' +
        PlatformUI.esc(res.body.message || 'İşletme oluşturulamadı.') + '</div>';
    }
  } catch (e) {
    modalAlert.innerHTML = '<div class="alert alert-danger py-2">İşlem başarısız.</div>';
  } finally {
    btn.disabled = false;
  }
  return false;
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
