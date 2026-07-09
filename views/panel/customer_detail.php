<?php $title = 'Müşteri Detayı'; $active = 'customers'; $customerId = $customerId ?? ''; ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0"><a href="/panel/customers" class="text-decoration-none me-2" title="Listeye dön"><i class="bi bi-arrow-left"></i></a>Müşteri Detayı</h1>
</div>

<div class="card mb-3">
  <div class="card-body" id="custInfo"><span class="text-body-secondary">Yükleniyor…</span></div>
</div>

<div class="card mb-3">
  <div class="card-header bg-body"><i class="bi bi-calendar-check me-1"></i>Randevu Geçmişi</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Tarih</th><th>Saat</th><th>Personel</th><th>Hizmet</th><th>Durum</th></tr></thead>
      <tbody id="apptRows"><tr><td colspan="5" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header bg-body"><i class="bi bi-chat-dots me-1"></i>Mesaj Geçmişi <small class="text-body-secondary">(son 200)</small></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Zaman</th><th>Yön</th><th>İçerik</th><th>Durum</th></tr></thead>
      <tbody id="msgRows"><tr><td colspan="4" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
const CUSTOMER_ID = <?= json_encode($customerId) ?>;

// Postgres timestamptz "+03" → JS'in istediği "+03:00".
function pgDate(s) { return new Date(String(s).replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00')); }

// message_log.content jsonb'sinden okunabilir özet (06§7): text > template > ham JSON.
function contentSummary(raw) {
  let c = raw;
  if (typeof c === 'string') { try { c = JSON.parse(c); } catch (e) { return Panel.esc(c); } }
  if (c && c.text) return Panel.esc(c.text);
  if (c && c.template) return '<span class="badge text-bg-light">şablon</span> ' + Panel.esc(c.template);
  return '<code class="small">' + Panel.esc(JSON.stringify(c)) + '</code>';
}

async function loadCustomer() {
  const res = await Panel.api('GET', '/customers/' + CUSTOMER_ID);
  const el = document.getElementById('custInfo');
  if (!res.ok) {
    el.innerHTML = '<span class="text-danger">Müşteri bulunamadı.</span>';
    return;
  }
  const c = res.body.data;
  const anonymized = String(c.whatsapp_number).startsWith('deleted-');
  el.innerHTML =
    '<div class="row g-3">' +
    '<div class="col-12 col-md-4"><div class="small text-body-secondary">Ad</div><div class="fs-5">' +
      (anonymized ? '<span class="text-body-tertiary">(anonim)</span>' : Panel.esc(c.name || '—')) + '</div></div>' +
    '<div class="col-6 col-md-4"><div class="small text-body-secondary">Telefon</div><div class="fs-5">' +
      (anonymized ? '—' : Panel.esc(c.whatsapp_number)) + '</div></div>' +
    '<div class="col-6 col-md-4"><div class="small text-body-secondary">Kayıt Tarihi</div><div class="fs-5">' +
      Panel.fmtDate(pgDate(c.created_at)) + '</div></div></div>';
}

async function loadAppointments() {
  const rows = document.getElementById('apptRows');
  const res = await Panel.api('GET', '/appointments?customer_id=' + CUSTOMER_ID);
  if (!res.ok) { rows.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Hata oluştu.</td></tr>'; return; }
  const appts = res.body.data;
  if (appts.length === 0) {
    rows.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-4">Randevu geçmişi yok.</td></tr>';
    return;
  }
  // En yeni üstte — backend lower(time_range) artan sıralıyor.
  rows.innerHTML = appts.slice().reverse().map(a => {
    const r = Panel.parseRange(a.time_range);
    return '<tr>' +
      '<td>' + (r ? Panel.fmtDate(r.start) : '') + '</td>' +
      '<td>' + (r ? Panel.fmtTime(r.start) + '–' + Panel.fmtTime(r.end) : '') + '</td>' +
      '<td>' + Panel.esc(a.staff_name) + '</td>' +
      '<td>' + Panel.esc(a.service_name) + '</td>' +
      '<td>' + Panel.statusBadge(a.status) + '</td></tr>';
  }).join('');
}

async function loadMessages() {
  const rows = document.getElementById('msgRows');
  const res = await Panel.api('GET', '/messages/log?customer_id=' + CUSTOMER_ID);
  if (!res.ok) { rows.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-4">Hata oluştu.</td></tr>'; return; }
  const msgs = res.body.data;
  if (msgs.length === 0) {
    rows.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-4">Mesaj geçmişi yok.</td></tr>';
    return;
  }
  rows.innerHTML = msgs.map(m => {
    const d = pgDate(m.sent_at);
    const dir = m.direction === 'inbound'
      ? '<span class="badge text-bg-info"><i class="bi bi-arrow-down-left me-1"></i>Gelen</span>'
      : '<span class="badge text-bg-secondary"><i class="bi bi-arrow-up-right me-1"></i>Giden</span>';
    const failed = m.status === 'failed' && m.meta_error_code
      ? ' <i class="bi bi-exclamation-triangle text-danger" title="Meta hata kodu: ' + Panel.esc(m.meta_error_code) + '"></i>' : '';
    return '<tr>' +
      '<td class="text-nowrap">' + Panel.fmtDate(d) + ' ' + Panel.fmtTime(d) + '</td>' +
      '<td>' + dir + '</td>' +
      '<td>' + contentSummary(m.content) + '</td>' +
      '<td><span class="badge text-bg-' + (m.status === 'failed' ? 'danger' : 'light') + '">' + Panel.esc(m.status) + '</span>' + failed + '</td></tr>';
  }).join('');
}

loadCustomer(); loadAppointments(); loadMessages();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
