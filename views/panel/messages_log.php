<?php $title = 'Mesaj Logu'; $active = 'messages_log'; ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Mesaj Logu</h1>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form id="filterForm" class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="fltDirection">Yön</label>
        <select class="form-select" id="fltDirection">
          <option value="">Tümü</option>
          <option value="inbound">Gelen</option>
          <option value="outbound">Giden</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="fltStatus">Durum</label>
        <select class="form-select" id="fltStatus">
          <option value="">Tümü</option>
          <option value="sent">Gönderildi</option>
          <option value="delivered">İletildi</option>
          <option value="read">Okundu</option>
          <option value="failed">Başarısız</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="fltFrom">Başlangıç</label>
        <input type="date" class="form-control" id="fltFrom">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="fltTo">Bitiş</label>
        <input type="date" class="form-control" id="fltTo">
      </div>
      <div class="col-12 col-md-2 d-flex gap-2">
        <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel me-1"></i>Filtrele</button>
        <button class="btn btn-outline-secondary" type="button" id="fltClear" title="Temizle"><i class="bi bi-x-lg"></i></button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th>Zaman</th><th>Yön</th><th>Müşteri</th><th class="d-none d-md-table-cell">Telefon</th>
        <th>İçerik</th><th>Durum</th>
      </tr></thead>
      <tbody id="msgRows"><tr><td colspan="6" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
  <div class="card-footer bg-body text-body-secondary small">En yeni 200 kayıt gösterilir.</div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
function pgDate(s) { return new Date(String(s).replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00')); }

function contentSummary(raw) {
  let c = raw;
  if (typeof c === 'string') { try { c = JSON.parse(c); } catch (e) { return Panel.esc(c); } }
  if (c && c.text) return Panel.esc(c.text);
  if (c && c.template) return '<span class="badge text-bg-light">şablon</span> ' + Panel.esc(c.template);
  return '<code class="small">' + Panel.esc(JSON.stringify(c)) + '</code>';
}

const MSG_STATUS = { sent: 'Gönderildi', delivered: 'İletildi', read: 'Okundu', failed: 'Başarısız' };

async function loadMessages() {
  const rows = document.getElementById('msgRows');
  rows.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr>';

  const params = new URLSearchParams();
  const dir = document.getElementById('fltDirection').value;
  const status = document.getElementById('fltStatus').value;
  const from = document.getElementById('fltFrom').value;
  const to = document.getElementById('fltTo').value;
  if (dir) params.set('direction', dir);
  if (status) params.set('status', status);
  if (from) params.set('date_from', from);
  if (to) params.set('date_to', to);

  const res = await Panel.api('GET', '/messages/log' + (params.size ? '?' + params : ''));
  if (!res.ok) {
    Panel.alert(res.body.message || 'Mesaj logu yüklenemedi.');
    rows.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Hata oluştu.</td></tr>';
    return;
  }

  const msgs = res.body.data;
  if (msgs.length === 0) {
    rows.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-4">Kayıt bulunamadı.</td></tr>';
    return;
  }

  rows.innerHTML = msgs.map(m => {
    const d = pgDate(m.sent_at);
    const dirBadge = m.direction === 'inbound'
      ? '<span class="badge text-bg-info"><i class="bi bi-arrow-down-left me-1"></i>Gelen</span>'
      : '<span class="badge text-bg-secondary"><i class="bi bi-arrow-up-right me-1"></i>Giden</span>';
    // 06§7: failed satırlarda Meta hata kodu tooltip olarak gösterilir (meta_error_code kolonu).
    const failed = m.status === 'failed' && m.meta_error_code
      ? ' <i class="bi bi-exclamation-triangle text-danger" data-bs-toggle="tooltip" title="Meta hata kodu: ' + Panel.esc(m.meta_error_code) + '"></i>' : '';
    return '<tr>' +
      '<td class="text-nowrap">' + Panel.fmtDate(d) + ' ' + Panel.fmtTime(d) + '</td>' +
      '<td>' + dirBadge + '</td>' +
      '<td>' + (m.customer_id
        ? '<a href="/panel/customers/' + m.customer_id + '" class="text-decoration-none">' + Panel.esc(m.customer_name || '—') + '</a>'
        : '—') + '</td>' +
      '<td class="d-none d-md-table-cell">' + Panel.esc(m.customer_whatsapp || '—') + '</td>' +
      '<td>' + contentSummary(m.content) + '</td>' +
      '<td><span class="badge text-bg-' + (m.status === 'failed' ? 'danger' : 'light') + '">' +
        Panel.esc(MSG_STATUS[m.status] || m.status) + '</span>' + failed + '</td></tr>';
  }).join('');

  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
}

document.getElementById('filterForm').addEventListener('submit', (ev) => { ev.preventDefault(); loadMessages(); });
document.getElementById('fltClear').addEventListener('click', () => {
  ['fltDirection', 'fltStatus', 'fltFrom', 'fltTo'].forEach(id => { document.getElementById(id).value = ''; });
  loadMessages();
});

loadMessages();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
