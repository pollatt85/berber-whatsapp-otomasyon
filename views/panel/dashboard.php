<?php $title = 'Özet'; $active = 'dashboard'; ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Özet</h1>
  <span class="text-body-secondary" id="todayLabel"></span>
</div>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-body-secondary small">Bugünkü randevu</div>
      <div class="fs-2 fw-semibold" id="cardToday">—</div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-body-secondary small">Onay bekleyen</div>
      <div class="fs-2 fw-semibold text-warning" id="cardPending">—</div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-body-secondary small">Aktif hizmet</div>
      <div class="fs-2 fw-semibold" id="cardServices">—</div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-body-secondary small">Aktif personel</div>
      <div class="fs-2 fw-semibold" id="cardStaff">—</div>
    </div></div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Bugünün randevuları</span>
    <a class="btn btn-sm btn-outline-primary" href="/panel/appointments">Tümü</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Saat</th><th>Müşteri</th><th>Personel</th><th>Hizmet</th><th>Durum</th></tr></thead>
      <tbody id="todayRows"><tr><td colspan="5" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
(async () => {
  document.getElementById('todayLabel').textContent = new Date().toLocaleDateString('tr-TR', { dateStyle: 'full' });
  const today = Panel.todayIso();
  const [appts, pending, services, staff, settings] = await Promise.all([
    Panel.api('GET', '/appointments?date=' + today),
    Panel.api('GET', '/appointments?status=pending'),
    Panel.api('GET', '/services'),
    Panel.api('GET', '/staff'),
    Panel.api('GET', '/settings'),
  ]);

  if (settings.ok) document.getElementById('brandName').textContent = settings.body.data.business_name;
  document.getElementById('cardToday').textContent = appts.ok ? appts.body.data.length : '?';
  document.getElementById('cardPending').textContent = pending.ok ? pending.body.data.length : '?';
  document.getElementById('cardServices').textContent = services.ok ? services.body.data.filter(s => s.active).length : '?';
  document.getElementById('cardStaff').textContent = staff.ok ? staff.body.data.length : '?';

  const rows = document.getElementById('todayRows');
  if (!appts.ok || appts.body.data.length === 0) {
    rows.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-4">Bugün için randevu yok.</td></tr>';
    return;
  }
  rows.innerHTML = appts.body.data.map(a => {
    const r = Panel.parseRange(a.time_range);
    return '<tr>' +
      '<td>' + (r ? Panel.fmtTime(r.start) + '–' + Panel.fmtTime(r.end) : '') + '</td>' +
      '<td>' + Panel.esc(a.customer_name || a.customer_whatsapp) + '</td>' +
      '<td>' + Panel.esc(a.staff_name) + '</td>' +
      '<td>' + Panel.esc(a.service_name) + '</td>' +
      '<td>' + Panel.statusBadge(a.status) + '</td></tr>';
  }).join('');
})();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
