<?php $title = 'Raporlar'; $active = 'reports'; ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h1 class="h3 mb-0">Raporlar</h1>
  <form class="d-flex align-items-center gap-2" id="rangeForm">
    <input type="date" class="form-control form-control-sm" id="fromDate" required>
    <span class="text-body-secondary">–</span>
    <input type="date" class="form-control form-control-sm" id="toDate" required>
    <button type="submit" class="btn btn-sm btn-primary">Uygula</button>
  </form>
</div>
<div id="reportSummary" class="text-body-secondary small mb-3">Yükleniyor…</div>

<div class="row g-3">
  <div class="col-12">
    <div class="card"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <h2 class="h6 mb-0"><i class="bi bi-graph-up me-1"></i>Randevu Hacmi</h2>
        <div class="btn-group btn-group-sm" role="group" aria-label="Granülarite">
          <input type="radio" class="btn-check" name="gran" id="granDay" checked>
          <label class="btn btn-outline-secondary" for="granDay">Günlük</label>
          <input type="radio" class="btn-check" name="gran" id="granWeek">
          <label class="btn btn-outline-secondary" for="granWeek">Haftalık</label>
        </div>
      </div>
      <canvas id="volumeChart" height="80"></canvas>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6"><i class="bi bi-pie-chart me-1"></i>Durum Dağılımı (İptal / Gelmedi Oranı)</h2>
      <div class="mx-auto" style="max-width: 280px"><canvas id="statusChart"></canvas></div>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6"><i class="bi bi-bar-chart me-1"></i>Personel Doluluk Oranı (%)</h2>
      <canvas id="occupancyChart" height="120"></canvas>
      <div class="text-body-tertiary small mt-2">Doluluk = iptal dışı randevu süresi / seçili
        aralıktaki çalışma saati kapasitesi (mola ve tatiller kapasiteden düşülmez).</div>
    </div></div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php $content = ob_get_clean(); ob_start(); ?>
// 06§9: yeni Backend ucu yok — mevcut GET /appointments + GET /staff/{id}/schedule
// çıktıları panelde agregre edilir (hacim düşük varsayımı; >1000 randevu/ay olursa
// agregasyon Backend'e taşınır).
const COLORS = {
  pending: '#ffc107', confirmed: '#198754', completed: '#0d6efd',
  cancelled: '#6c757d', no_show: '#212529',
};
let allAppointments = [], staffList = [], schedules = {}, charts = {};

const dayKey = d => d.toLocaleDateString('sv-SE');
const timeToMin = t => { const [h, m] = String(t).split(':'); return Number(h) * 60 + Number(m); };
// Haftalık gruplama: haftanın pazartesi tarihi anahtar olur.
function weekKey(d) {
  const w = new Date(d);
  w.setDate(w.getDate() - (w.getDay() + 6) % 7);
  return dayKey(w);
}

async function loadData() {
  const [apptRes, staffRes] = await Promise.all([
    Panel.api('GET', '/appointments'),
    Panel.api('GET', '/staff'),
  ]);
  if (!apptRes.ok || !staffRes.ok) { Panel.alert('Rapor verisi yüklenemedi.'); return false; }
  allAppointments = apptRes.body.data
    .map(a => ({ ...a, range: Panel.parseRange(a.time_range) }))
    .filter(a => a.range);
  staffList = staffRes.body.data.filter(s => s.active !== false);
  const schedRes = await Promise.all(staffList.map(s => Panel.api('GET', '/staff/' + s.id + '/schedule')));
  staffList.forEach((s, i) => { schedules[s.id] = schedRes[i].ok ? schedRes[i].body.data : null; });
  return true;
}

function renderChart(key, canvasId, config) {
  if (charts[key]) charts[key].destroy();
  charts[key] = new Chart(document.getElementById(canvasId), config);
}

function apply() {
  const from = document.getElementById('fromDate').value;
  const to = document.getElementById('toDate').value;
  if (!from || !to || from > to) { Panel.alert('Geçerli bir tarih aralığı seçin.'); return; }

  const inRange = allAppointments.filter(a => {
    const k = dayKey(a.range.start);
    return k >= from && k <= to;
  });

  // --- 1. Hacim (çizgi) ---
  const weekly = document.getElementById('granWeek').checked;
  const buckets = new Map();
  for (let d = new Date(from + 'T00:00:00'); dayKey(d) <= to; d.setDate(d.getDate() + (weekly ? 7 : 1))) {
    buckets.set(weekly ? weekKey(d) : dayKey(d), 0);
  }
  inRange.forEach(a => {
    const k = weekly ? weekKey(a.range.start) : dayKey(a.range.start);
    buckets.set(k, (buckets.get(k) || 0) + 1);
  });
  const volLabels = [...buckets.keys()].sort();
  renderChart('volume', 'volumeChart', {
    type: 'line',
    data: {
      labels: volLabels.map(k => (weekly ? 'Hafta ' : '') + new Date(k + 'T00:00:00').toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit' })),
      datasets: [{
        label: weekly ? 'Haftalık randevu' : 'Günlük randevu',
        data: volLabels.map(k => buckets.get(k)),
        borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.15)',
        fill: true, tension: .3,
      }],
    },
    options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
  });

  // --- 2. Durum dağılımı (pasta) ---
  const byStatus = {};
  inRange.forEach(a => { byStatus[a.status] = (byStatus[a.status] || 0) + 1; });
  const statuses = Object.keys(Panel.STATUS).filter(s => byStatus[s]);
  renderChart('status', 'statusChart', {
    type: 'pie',
    data: {
      labels: statuses.map(s => Panel.STATUS[s].label),
      datasets: [{ data: statuses.map(s => byStatus[s]), backgroundColor: statuses.map(s => COLORS[s]) }],
    },
    options: { plugins: { legend: { position: 'bottom' } } },
  });

  // --- 3. Personel doluluk (bar) ---
  // Kapasite: aralıktaki her gün için o güne (day_of_week, 0=Pazar — JS getDay ile aynı)
  // denk gelen working_hours dakika toplamı. Payda 0 ise personel grafikte %0 + "(kapasite yok)".
  const occ = staffList.map(st => {
    let capacity = 0;
    const hours = (schedules[st.id] || {}).working_hours || [];
    for (let d = new Date(from + 'T00:00:00'); dayKey(d) <= to; d.setDate(d.getDate() + 1)) {
      hours.filter(h => Number(h.day_of_week) === d.getDay())
        .forEach(h => { capacity += timeToMin(h.end_time) - timeToMin(h.start_time); });
    }
    let booked = 0;
    inRange.filter(a => a.staff_id === st.id && a.status !== 'cancelled')
      .forEach(a => { booked += (a.range.end - a.range.start) / 60000; });
    return { name: st.name, capacity, booked, pct: capacity > 0 ? Math.round(booked / capacity * 1000) / 10 : 0 };
  });
  renderChart('occupancy', 'occupancyChart', {
    type: 'bar',
    data: {
      labels: occ.map(o => o.name + (o.capacity === 0 ? ' (kapasite yok)' : '')),
      datasets: [{ label: 'Doluluk %', data: occ.map(o => o.pct), backgroundColor: '#198754' }],
    },
    options: {
      indexAxis: 'y',
      scales: { x: { beginAtZero: true, suggestedMax: 100 } },
      plugins: { tooltip: { callbacks: {
        label: ctx => '%' + ctx.parsed.x + ' — ' + Math.round(occ[ctx.dataIndex].booked) + ' dk dolu / '
          + occ[ctx.dataIndex].capacity + ' dk kapasite',
      } } },
    },
  });

  // --- Özet satırı ---
  const total = inRange.length;
  const cancelled = byStatus.cancelled || 0, noShow = byStatus.no_show || 0;
  const pct = n => total > 0 ? Math.round(n / total * 1000) / 10 : 0;
  document.getElementById('reportSummary').textContent =
    'Seçili aralıkta ' + total + ' randevu — iptal oranı %' + pct(cancelled) +
    ', gelmeme (no-show) oranı %' + pct(noShow) + '.';
}

document.getElementById('rangeForm').addEventListener('submit', e => { e.preventDefault(); apply(); });
document.getElementById('granDay').addEventListener('change', apply);
document.getElementById('granWeek').addEventListener('change', apply);

// Varsayılan aralık: içinde bulunulan ay (dev verisi ve tipik kullanım için makul pencere).
(function init() {
  const now = new Date();
  document.getElementById('fromDate').value = dayKey(new Date(now.getFullYear(), now.getMonth(), 1));
  document.getElementById('toDate').value = dayKey(new Date(now.getFullYear(), now.getMonth() + 1, 0));
  loadData().then(ok => { if (ok) apply(); });
})();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
