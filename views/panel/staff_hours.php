<?php
/* /panel/staff/{id}/hours (06§5) — haftalık çalışma saati + molalar + tatil takvimi.
   $staffId route'tan gelir (public/index.php). */
$title = 'Çalışma Saatleri'; $active = 'staff'; $staffId = $staffId ?? '';
ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" id="pageTitle">Çalışma Saatleri</h1>
    <a href="/panel/staff" class="small"><i class="bi bi-arrow-left me-1"></i>Personel listesine dön</a>
  </div>
  <button class="btn btn-primary" onclick="saveSchedule()"><i class="bi bi-save me-1"></i>Kaydet</button>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header">Haftalık çalışma saatleri</div>
      <div class="card-body p-2">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th style="width:30%">Gün</th><th style="width:15%">Açık</th><th>Başlangıç</th><th>Bitiş</th></tr></thead>
          <tbody id="dayRows"></tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Molalar</span>
        <button class="btn btn-sm btn-outline-primary" onclick="addBreakRow()"><i class="bi bi-plus-lg"></i> Mola ekle</button>
      </div>
      <div class="card-body p-2">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Gün</th><th>Başlangıç</th><th>Bitiş</th><th></th></tr></thead>
          <tbody id="breakRows"></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Tatil / İzin Takvimi</div>
      <div class="card-body">
        <form id="holidayForm" class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label small mb-1" for="holStart">Başlangıç</label>
            <input type="date" class="form-control form-control-sm" id="holStart" required>
          </div>
          <div class="col-6">
            <label class="form-label small mb-1" for="holEnd">Bitiş</label>
            <input type="date" class="form-control form-control-sm" id="holEnd" required>
          </div>
          <div class="col-8">
            <input type="text" class="form-control form-control-sm" id="holReason" placeholder="Açıklama (isteğe bağlı)">
          </div>
          <div class="col-4 d-grid">
            <button class="btn btn-sm btn-outline-primary" type="submit">Ekle</button>
          </div>
        </form>
        <ul class="list-group list-group-flush" id="holidayList"></ul>
      </div>
    </div>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
const STAFF_ID = <?= json_encode($staffId) ?>;
// Görsel sıra Pazartesi→Pazar; DB day_of_week 0=Pazar (02§3.6).
const DAY_ORDER = [1, 2, 3, 4, 5, 6, 0];

function dayRowHtml(day) {
  return '<tr data-day="' + day + '">' +
    '<td>' + Panel.DAYS[day] + '</td>' +
    '<td><div class="form-check form-switch"><input class="form-check-input day-open" type="checkbox" onchange="toggleDay(this)"></div></td>' +
    '<td><input type="time" class="form-control form-control-sm day-start" value="09:00" disabled></td>' +
    '<td><input type="time" class="form-control form-control-sm day-end" value="19:00" disabled></td></tr>';
}

function toggleDay(cb) {
  const tr = cb.closest('tr');
  tr.querySelector('.day-start').disabled = !cb.checked;
  tr.querySelector('.day-end').disabled = !cb.checked;
}

function addBreakRow(day = 1, start = '12:30', end = '13:00') {
  const tr = document.createElement('tr');
  tr.innerHTML =
    '<td><select class="form-select form-select-sm br-day">' +
      DAY_ORDER.map(d => '<option value="' + d + '"' + (d === day ? ' selected' : '') + '>' + Panel.DAYS[d] + '</option>').join('') +
    '</select></td>' +
    '<td><input type="time" class="form-control form-control-sm br-start" value="' + start + '"></td>' +
    '<td><input type="time" class="form-control form-control-sm br-end" value="' + end + '"></td>' +
    '<td class="text-end"><button class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove()"><i class="bi bi-trash"></i></button></td>';
  document.getElementById('breakRows').appendChild(tr);
}

async function load() {
  // Sayfa başlığına personel adı
  const stRes = await Panel.api('GET', '/staff');
  if (stRes.ok) {
    const s = stRes.body.data.find(x => x.id === STAFF_ID);
    if (s) document.getElementById('pageTitle').textContent = s.name + ' — Çalışma Saatleri';
  }

  document.getElementById('dayRows').innerHTML = DAY_ORDER.map(dayRowHtml).join('');

  const res = await Panel.api('GET', '/staff/' + STAFF_ID + '/schedule');
  if (!res.ok) {
    Panel.alert(res.body.message || 'Program yüklenemedi.');
    return;
  }
  const { working_hours, breaks, holidays } = res.body.data;

  for (const wh of working_hours) {
    const tr = document.querySelector('#dayRows tr[data-day="' + wh.day_of_week + '"]');
    if (!tr) continue;
    tr.querySelector('.day-open').checked = true;
    tr.querySelector('.day-start').value = wh.start_time.slice(0, 5);
    tr.querySelector('.day-start').disabled = false;
    tr.querySelector('.day-end').value = wh.end_time.slice(0, 5);
    tr.querySelector('.day-end').disabled = false;
  }

  document.getElementById('breakRows').innerHTML = '';
  for (const br of breaks) addBreakRow(br.day_of_week, br.start_time.slice(0, 5), br.end_time.slice(0, 5));

  renderHolidays(holidays);
}

function renderHolidays(holidays) {
  const ul = document.getElementById('holidayList');
  ul.innerHTML = holidays.length === 0
    ? '<li class="list-group-item text-body-secondary px-0">Kayıtlı tatil yok.</li>'
    : holidays.map(h =>
        '<li class="list-group-item d-flex justify-content-between align-items-center px-0">' +
        '<span>' + Panel.fmtDate(new Date(h.start_date)) + ' – ' + Panel.fmtDate(new Date(h.end_date)) +
        (h.reason ? ' <small class="text-body-secondary">(' + Panel.esc(h.reason) + ')</small>' : '') + '</span>' +
        '<button class="btn btn-sm btn-outline-danger" onclick="deleteHoliday(\'' + h.id + '\')"><i class="bi bi-trash"></i></button></li>'
      ).join('');
}

async function saveSchedule() {
  const working_hours = [];
  for (const tr of document.querySelectorAll('#dayRows tr')) {
    if (!tr.querySelector('.day-open').checked) continue;
    working_hours.push({
      day_of_week: parseInt(tr.dataset.day, 10),
      start_time: tr.querySelector('.day-start').value,
      end_time: tr.querySelector('.day-end').value,
    });
  }
  const breaks = [];
  for (const tr of document.querySelectorAll('#breakRows tr')) {
    breaks.push({
      day_of_week: parseInt(tr.querySelector('.br-day').value, 10),
      start_time: tr.querySelector('.br-start').value,
      end_time: tr.querySelector('.br-end').value,
    });
  }

  const res = await Panel.api('PUT', '/staff/' + STAFF_ID + '/schedule', { working_hours, breaks });
  if (res.ok) Panel.alert('Çalışma programı kaydedildi.', 'success');
  else Panel.alert(res.body.message || 'Kaydedilemedi.');
}

document.getElementById('holidayForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const res = await Panel.api('POST', '/staff/' + STAFF_ID + '/holidays', {
    start_date: document.getElementById('holStart').value,
    end_date: document.getElementById('holEnd').value,
    reason: document.getElementById('holReason').value.trim() || null,
  });
  if (res.ok) {
    Panel.alert('Tatil eklendi.', 'success');
    document.getElementById('holidayForm').reset();
    const sch = await Panel.api('GET', '/staff/' + STAFF_ID + '/schedule');
    if (sch.ok) renderHolidays(sch.body.data.holidays);
  } else {
    const details = res.body.details && Object.values(res.body.details).join(' ');
    Panel.alert(details || res.body.message || 'Tatil eklenemedi.');
  }
});

async function deleteHoliday(id) {
  if (!confirm('Tatil kaydı silinsin mi?')) return;
  const res = await Panel.api('DELETE', '/staff/' + STAFF_ID + '/holidays/' + id);
  if (!res.ok) Panel.alert(res.body.message || 'Silinemedi.');
  const sch = await Panel.api('GET', '/staff/' + STAFF_ID + '/schedule');
  if (sch.ok) renderHolidays(sch.body.data.holidays);
}

load();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
