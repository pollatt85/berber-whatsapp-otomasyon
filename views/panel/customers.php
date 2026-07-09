<?php $title = 'Müşteriler'; $active = 'customers'; ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Müşteriler</h1>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form id="searchForm" class="row g-2 align-items-end">
      <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="srcText">İsim veya telefon ara</label>
        <input type="search" class="form-control" id="srcText" placeholder="ör. Ali veya 0555…">
      </div>
      <div class="col-12 col-md-3 d-flex gap-2">
        <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-search me-1"></i>Ara</button>
        <button class="btn btn-outline-secondary" type="button" id="srcClear" title="Temizle"><i class="bi bi-x-lg"></i></button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th>Ad</th><th>Telefon</th><th class="d-none d-md-table-cell">Randevu Sayısı</th>
        <th>Son Randevu</th><th class="text-end">İşlem</th>
      </tr></thead>
      <tbody id="custRows"><tr><td colspan="5" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
async function loadCustomers() {
  const rows = document.getElementById('custRows');
  rows.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr>';

  const q = document.getElementById('srcText').value.trim();
  const res = await Panel.api('GET', '/customers' + (q ? '?search=' + encodeURIComponent(q) : ''));
  if (!res.ok) {
    Panel.alert(res.body.message || 'Müşteriler yüklenemedi.');
    rows.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Hata oluştu.</td></tr>';
    return;
  }

  const customers = res.body.data;
  if (customers.length === 0) {
    rows.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-4">Kayıt bulunamadı.</td></tr>';
    return;
  }

  rows.innerHTML = customers.map(c => {
    // Postgres timestamptz "+03" ofsetini JS Date "+03:00" ister — parseRange'deki normalizasyonun tekili.
    const last = c.last_appointment_at ? new Date(c.last_appointment_at.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00')) : null;
    const anonymized = String(c.whatsapp_number).startsWith('deleted-');
    return '<tr>' +
      '<td>' + (anonymized ? '<span class="text-body-tertiary">(anonim)</span>' : Panel.esc(c.name || '—')) + '</td>' +
      '<td>' + (anonymized ? '—' : Panel.esc(c.whatsapp_number)) + '</td>' +
      '<td class="d-none d-md-table-cell">' + c.appointment_count + '</td>' +
      '<td>' + (last ? Panel.fmtDate(last) + ' ' + Panel.fmtTime(last) : '<span class="text-body-tertiary">Hiç yok</span>') + '</td>' +
      '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/panel/customers/' + c.id + '">' +
        '<i class="bi bi-eye me-1"></i>Detay</a></td></tr>';
  }).join('');
}

document.getElementById('searchForm').addEventListener('submit', (ev) => { ev.preventDefault(); loadCustomers(); });
document.getElementById('srcClear').addEventListener('click', () => {
  document.getElementById('srcText').value = '';
  loadCustomers();
});

loadCustomers();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
