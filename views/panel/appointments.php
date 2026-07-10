<?php $title = 'Randevular'; $active = 'appointments'; ob_start(); ?>
<!-- FullCalendar çekirdek paketi (ücretsiz, MIT) — 06§4/BACKLOG E8: Scheduler eklentisi
     (resourceTimeGrid, ticari lisans gerektirir) kaldırıldı, personel ayrımı sütun yerine
     event title'ında gösteriliyor. -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Randevular</h1>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-success" id="btnNewAppt" data-bs-toggle="modal" data-bs-target="#newApptModal">
      <i class="bi bi-plus-lg me-1"></i>Yeni Randevu
    </button>
    <div class="btn-group" role="group" aria-label="Görünüm">
      <button type="button" class="btn btn-outline-primary active" id="viewListBtn"><i class="bi bi-list-ul me-1"></i>Liste</button>
      <button type="button" class="btn btn-outline-primary" id="viewCalBtn"><i class="bi bi-calendar3 me-1"></i>Takvim</button>
    </div>
  </div>
</div>

<!-- Yeni Randevu modalı (06§4: panelden randevu oluşturma; 409 slot_taken → alternatif slotlar) -->
<div class="modal fade" id="newApptModal" tabindex="-1" aria-labelledby="newApptTitle">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="newApptForm">
        <div class="modal-header">
          <h5 class="modal-title" id="newApptTitle">Yeni Randevu</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
        </div>
        <div class="modal-body">
          <div id="modalAlert"></div>
          <div class="mb-3">
            <label class="form-label">Müşteri</label>
            <div class="btn-group w-100 mb-2" role="group">
              <input type="radio" class="btn-check" name="custMode" id="custModeExisting" value="existing" checked>
              <label class="btn btn-outline-secondary" for="custModeExisting">Kayıtlı müşteri</label>
              <input type="radio" class="btn-check" name="custMode" id="custModeNew" value="new">
              <label class="btn btn-outline-secondary" for="custModeNew">Yeni müşteri</label>
            </div>
            <select class="form-select" id="apptCustomer"><option value="">Yükleniyor…</option></select>
            <div class="row g-2 d-none" id="newCustFields">
              <div class="col-6"><input type="text" class="form-control" id="newCustPhone" placeholder="Telefon (9055…)" inputmode="tel"></div>
              <div class="col-6"><input type="text" class="form-control" id="newCustName" placeholder="Ad Soyad"></div>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-4">
              <label class="form-label" for="apptService">Hizmet</label>
              <select class="form-select" id="apptService"><option value="">Seçin…</option></select>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="apptStaff">Personel</label>
              <select class="form-select" id="apptStaff"><option value="">Seçin…</option></select>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="apptDate">Tarih</label>
              <input type="date" class="form-control" id="apptDate">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label d-block">Uygun Saatler</label>
            <div id="slotArea" class="d-flex flex-wrap gap-2">
              <span class="text-body-secondary small">Hizmet, personel ve tarih seçince uygun saatler listelenir.</span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
          <button type="submit" class="btn btn-success" id="apptSaveBtn" disabled>
            <span class="spinner-border spinner-border-sm me-1 d-none" id="apptSaveSpinner"></span>Randevu Oluştur
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form id="filterForm" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="fltDate">Tarih</label>
        <input type="date" class="form-control" id="fltDate">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="fltStatus">Durum</label>
        <select class="form-select" id="fltStatus">
          <option value="">Tümü</option>
          <option value="pending">Onay bekliyor</option>
          <option value="confirmed">Onaylı</option>
          <option value="cancelled">İptal</option>
          <option value="completed">Tamamlandı</option>
          <option value="no_show">Gelmedi</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="fltStaff">Personel</label>
        <select class="form-select" id="fltStaff"><option value="">Tümü</option></select>
      </div>
      <div class="col-6 col-md-3 d-flex gap-2">
        <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel me-1"></i>Filtrele</button>
        <button class="btn btn-outline-secondary" type="button" id="fltClear" title="Temizle"><i class="bi bi-x-lg"></i></button>
      </div>
    </form>
  </div>
</div>

<div class="card" id="listCard">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th>Tarih</th><th>Saat</th><th>Müşteri</th><th class="d-none d-md-table-cell">Telefon</th>
        <th>Personel</th><th>Hizmet</th><th>Durum</th><th class="text-end">İşlem</th>
      </tr></thead>
      <tbody id="apptRows"><tr><td colspan="8" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
</div>

<div class="card d-none" id="calCard">
  <div class="card-body"><div id="calendar"></div></div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
let appointments = [];
let staffList = [];
let calendar = null;

async function loadStaffFilter() {
  const res = await Panel.api('GET', '/staff');
  if (!res.ok) return;
  staffList = res.body.data;
  document.getElementById('fltStaff').innerHTML = '<option value="">Tümü</option>' +
    staffList.map(s => '<option value="' + s.id + '">' + Panel.esc(s.name) + '</option>').join('');
}

// --- Takvim görünümü (06§4, BACKLOG E8): masaüstü timeGridDay (personel = event title'ında), mobil listDay ---

const STATUS_COLOR = { pending: '#ffc107', confirmed: '#198754', cancelled: '#adb5bd', completed: '#0d6efd', no_show: '#212529' };

function staffName(staffId) {
  const s = staffList.find(x => x.id === staffId);
  return s ? s.name : '';
}

function initCalendar() {
  if (calendar) return;
  const mobile = window.matchMedia('(max-width: 767px)').matches; // 06§4: <768px listDay
  calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
    initialView: mobile ? 'listDay' : 'timeGridDay',
    initialDate: document.getElementById('fltDate').value || undefined,
    locale: 'tr',
    height: 'auto',
    slotMinTime: '08:00:00',
    slotMaxTime: '22:00:00',
    allDaySlot: false,
    headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
    events: async (info, success, failure) => {
      // Görünüm tek günlük — backend'in tek `date` parametresiyle birebir eşleşir.
      const res = await Panel.api('GET', '/appointments?date=' + info.startStr.slice(0, 10));
      if (!res.ok) { failure(new Error('load')); return; }
      success(res.body.data.map(a => {
        const r = Panel.parseRange(a.time_range);
        const staff = a.staff_name || staffName(a.staff_id);
        return {
          id: a.id,
          title: (staff ? staff + ' — ' : '') + (a.customer_name || a.customer_whatsapp) + ' — ' + a.service_name,
          start: r ? r.start : null,
          end: r ? r.end : null,
          color: STATUS_COLOR[a.status] || '#6c757d',
          textColor: a.status === 'pending' ? '#212529' : '#fff',
        };
      }));
    },
    eventClick: (info) => {
      // Aksiyonlar liste görünümünde — takvimde tıklama o güne filtrelenmiş listeye götürür.
      document.getElementById('fltDate').value = info.event.start.toLocaleDateString('sv-SE');
      showView('list');
      loadAppointments();
    },
    // 06§4: <768px'te otomatik listDay'e düş — pencere yeniden boyutlanınca da geçerli.
    windowResize: () => {
      const want = window.matchMedia('(max-width: 767px)').matches ? 'listDay' : 'timeGridDay';
      if (calendar.view.type !== want) calendar.changeView(want);
    },
  });
  calendar.render();
}

function showView(view) {
  const cal = view === 'cal';
  document.getElementById('listCard').classList.toggle('d-none', cal);
  document.getElementById('calCard').classList.toggle('d-none', !cal);
  document.getElementById('viewListBtn').classList.toggle('active', !cal);
  document.getElementById('viewCalBtn').classList.toggle('active', cal);
  if (cal) {
    initCalendar();
    const d = document.getElementById('fltDate').value;
    if (d) calendar.gotoDate(d);
    calendar.refetchEvents();
    calendar.updateSize(); // d-none'dan çıkınca boyut yeniden hesaplanmalı
  }
}

document.getElementById('viewListBtn').addEventListener('click', () => showView('list'));
document.getElementById('viewCalBtn').addEventListener('click', () => showView('cal'));

async function loadAppointments() {
  const rows = document.getElementById('apptRows');
  rows.innerHTML = '<tr><td colspan="8" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr>';

  const params = new URLSearchParams();
  const date = document.getElementById('fltDate').value;
  const status = document.getElementById('fltStatus').value;
  const staff = document.getElementById('fltStaff').value;
  if (date) params.set('date', date);
  if (status) params.set('status', status);
  if (staff) params.set('staff_id', staff);

  const res = await Panel.api('GET', '/appointments' + (params.size ? '?' + params : ''));
  if (!res.ok) {
    Panel.alert(res.body.message || 'Randevular yüklenemedi.');
    rows.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">Hata oluştu.</td></tr>';
    return;
  }

  appointments = res.body.data;
  if (appointments.length === 0) {
    rows.innerHTML = '<tr><td colspan="8" class="text-center text-body-secondary py-4">Kayıt bulunamadı.</td></tr>';
    return;
  }

  rows.innerHTML = appointments.map(a => {
    const r = Panel.parseRange(a.time_range);
    // 03§5 durum makinesi — panel yalnızca izinli geçişlerin butonunu gösterir (06§4).
    const actions = [];
    if (a.status === 'pending') {
      actions.push('<button class="btn btn-sm btn-success" onclick="act(\'' + a.id + '\',\'confirm\')" title="Onayla"><i class="bi bi-check-lg"></i></button>');
    }
    if (a.status === 'confirmed') {
      actions.push('<button class="btn btn-sm btn-primary" onclick="act(\'' + a.id + '\',\'complete\')" title="Tamamlandı"><i class="bi bi-check2-all"></i></button>');
      actions.push('<button class="btn btn-sm btn-dark" onclick="act(\'' + a.id + '\',\'no-show\')" title="Gelmedi"><i class="bi bi-person-x"></i></button>');
    }
    if (a.status === 'pending' || a.status === 'confirmed') {
      actions.push('<button class="btn btn-sm btn-outline-danger" onclick="act(\'' + a.id + '\',\'cancel\', true)" title="İptal"><i class="bi bi-x-lg"></i></button>');
    }
    return '<tr>' +
      '<td>' + (r ? Panel.fmtDate(r.start) : '') + '</td>' +
      '<td>' + (r ? Panel.fmtTime(r.start) + '–' + Panel.fmtTime(r.end) : '') + '</td>' +
      '<td>' + Panel.esc(a.customer_name || '—') + '</td>' +
      '<td class="d-none d-md-table-cell">' + Panel.esc(a.customer_whatsapp) + '</td>' +
      '<td>' + Panel.esc(a.staff_name) + '</td>' +
      '<td>' + Panel.esc(a.service_name) + '</td>' +
      '<td>' + Panel.statusBadge(a.status) + '</td>' +
      '<td class="text-end"><div class="btn-group">' + actions.join('') + '</div></td></tr>';
  }).join('');
}

async function act(id, action, confirmFirst) {
  if (confirmFirst && !confirm('Randevu iptal edilsin mi?')) return;
  const res = await Panel.api('PATCH', '/appointments/' + id + '/' + action);
  if (res.ok) {
    Panel.alert('Randevu güncellendi.', 'success');
  } else if (res.status === 409) {
    Panel.alert('Seçilen saat dolu (çakışma).'); // 06§4: 409 slot_taken
  } else {
    Panel.alert(res.body.message || 'İşlem başarısız.');
  }
  loadAppointments();
  if (calendar) calendar.refetchEvents();
}

document.getElementById('filterForm').addEventListener('submit', (ev) => { ev.preventDefault(); loadAppointments(); });
document.getElementById('fltClear').addEventListener('click', () => {
  document.getElementById('fltDate').value = '';
  document.getElementById('fltStatus').value = '';
  document.getElementById('fltStaff').value = '';
  loadAppointments();
});

// --- Yeni Randevu modalı (06§4) ---

let selectedSlot = null;

function modalAlert(msg, type = 'danger') { Panel.alert(msg, type, 'modalAlert'); }

async function openNewApptModal() {
  document.getElementById('newApptForm').reset();
  document.getElementById('modalAlert').innerHTML = '';
  document.getElementById('newCustFields').classList.add('d-none');
  document.getElementById('apptCustomer').classList.remove('d-none');
  document.getElementById('apptDate').value = Panel.todayIso();
  selectedSlot = null;
  renderSlots(null);

  const [customers, services] = await Promise.all([
    Panel.api('GET', '/customers'),
    Panel.api('GET', '/services'),
  ]);
  if (customers.ok) {
    document.getElementById('apptCustomer').innerHTML = '<option value="">Seçin…</option>' +
      customers.body.data.map(c => '<option value="' + c.id + '">' +
        Panel.esc((c.name || '(anonim)') + ' — ' + c.whatsapp_number) + '</option>').join('');
  }
  if (services.ok) {
    document.getElementById('apptService').innerHTML = '<option value="">Seçin…</option>' +
      services.body.data.map(s => '<option value="' + s.id + '">' +
        Panel.esc(s.name + ' (' + s.duration_minutes + ' dk)') + '</option>').join('');
  }
  document.getElementById('apptStaff').innerHTML = '<option value="">Seçin…</option>' +
    staffList.map(s => '<option value="' + s.id + '">' + Panel.esc(s.name) + '</option>').join('');
}

function renderSlots(slots, note) {
  const area = document.getElementById('slotArea');
  document.getElementById('apptSaveBtn').disabled = true;
  selectedSlot = null;
  if (slots === null) {
    area.innerHTML = '<span class="text-body-secondary small">Hizmet, personel ve tarih seçince uygun saatler listelenir.</span>';
    return;
  }
  if (slots.length === 0) {
    area.innerHTML = '<span class="text-danger small">Bu gün için uygun saat yok — başka tarih veya personel deneyin.</span>';
    return;
  }
  area.innerHTML = (note ? '<div class="w-100 small text-warning-emphasis mb-1">' + Panel.esc(note) + '</div>' : '') +
    slots.map(t => '<input type="radio" class="btn-check" name="slot" id="slot' + t.replace(':', '') + '" value="' + t + '">' +
      '<label class="btn btn-sm btn-outline-primary" for="slot' + t.replace(':', '') + '">' + t + '</label>').join('');
  area.querySelectorAll('input[name="slot"]').forEach(el => el.addEventListener('change', () => {
    selectedSlot = el.value;
    document.getElementById('apptSaveBtn').disabled = false;
  }));
}

async function loadSlots(note) {
  const serviceId = document.getElementById('apptService').value;
  const staffId = document.getElementById('apptStaff').value;
  const date = document.getElementById('apptDate').value;
  if (!serviceId || !staffId || !date) { renderSlots(null); return; }
  const area = document.getElementById('slotArea');
  area.innerHTML = '<span class="text-body-secondary small">Uygun saatler yükleniyor…</span>';
  const res = await Panel.api('GET', '/availability?service_id=' + serviceId + '&staff_id=' + staffId + '&date=' + date);
  if (!res.ok) {
    area.innerHTML = '<span class="text-danger small">' + Panel.esc(res.body.message || 'Uygun saatler alınamadı.') + '</span>';
    return;
  }
  renderSlots(res.body.slots, note);
}

['apptService', 'apptStaff', 'apptDate'].forEach(id =>
  document.getElementById(id).addEventListener('change', () => loadSlots()));

document.querySelectorAll('input[name="custMode"]').forEach(el => el.addEventListener('change', () => {
  const isNew = document.getElementById('custModeNew').checked;
  document.getElementById('apptCustomer').classList.toggle('d-none', isNew);
  document.getElementById('newCustFields').classList.toggle('d-none', !isNew);
}));

document.getElementById('newApptModal').addEventListener('show.bs.modal', openNewApptModal);

document.getElementById('newApptForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const isNew = document.getElementById('custModeNew').checked;
  const date = document.getElementById('apptDate').value;
  if (!selectedSlot) { modalAlert('Lütfen bir saat seçin.'); return; }

  let customerId = document.getElementById('apptCustomer').value;
  if (isNew) {
    const phone = document.getElementById('newCustPhone').value.trim();
    if (!phone) { modalAlert('Yeni müşteri için telefon gerekli.'); return; }
    const cres = await Panel.api('POST', '/customers', {
      whatsapp_number: phone,
      name: document.getElementById('newCustName').value.trim() || null,
    });
    if (!cres.ok) { modalAlert(cres.body.message || 'Müşteri oluşturulamadı.'); return; }
    customerId = cres.body.data.id;
  } else if (!customerId) {
    modalAlert('Lütfen bir müşteri seçin.');
    return;
  }

  const btn = document.getElementById('apptSaveBtn');
  btn.disabled = true;
  document.getElementById('apptSaveSpinner').classList.remove('d-none');
  try {
    const res = await Panel.api('POST', '/appointments', {
      staff_id: document.getElementById('apptStaff').value,
      service_id: document.getElementById('apptService').value,
      // Slotlar tenant TZ'sinde üretilir; TR kalıcı UTC+3 olduğundan ofset sabitlenir
      // (JS lokal TZ'sine güvenmek, tarayıcı farklı TZ'deyse yanlış saat üretirdi).
      start_time: date + 'T' + selectedSlot + ':00+03:00',
      customer_id: customerId,
    });
    if (res.ok) {
      bootstrap.Modal.getInstance(document.getElementById('newApptModal')).hide();
      Panel.alert('Randevu oluşturuldu: ' + date + ' ' + selectedSlot, 'success');
      loadAppointments();
      if (calendar) calendar.refetchEvents();
    } else if (res.status === 409) {
      // 06§4: slot_taken — güncel uygun saatler alternatif olarak yeniden listelenir.
      await loadSlots('Seçilen saat az önce doldu — güncel uygun saatler yeniden listelendi:');
      modalAlert('Seçilen saat az önce doldu, lütfen alternatif bir saat seçin.', 'warning');
    } else {
      modalAlert(res.body.message || 'Randevu oluşturulamadı.');
    }
  } finally {
    document.getElementById('apptSaveSpinner').classList.add('d-none');
  }
});

document.getElementById('fltDate').value = Panel.todayIso();
loadStaffFilter().then(loadAppointments);
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
