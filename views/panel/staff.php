<?php $title = 'Personel'; $active = 'staff'; ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Personel</h1>
  <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg me-1"></i>Yeni Personel</button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Ad</th><th class="d-none d-md-table-cell">Telefon</th><th>Hizmetler</th><th class="text-end">İşlem</th></tr></thead>
      <tbody id="staffRows"><tr><td colspan="4" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="staffModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="staffForm" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="staffModalTitle">Yeni Personel</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="staffModalAlert"></div>
          <input type="hidden" id="stId">
          <div class="mb-3">
            <label class="form-label" for="stName">Ad Soyad</label>
            <input type="text" class="form-control" id="stName" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="stPhone">Telefon <small class="text-body-secondary">(isteğe bağlı)</small></label>
            <input type="tel" class="form-control" id="stPhone">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
          <button type="submit" class="btn btn-primary">Kaydet</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- 06§5: personel-hizmet ataması çoktan-çoğa checkbox listesi -->
<div class="modal fade" id="svcAssignModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="assignTitle">Hizmet Ataması</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="assignAlert"></div>
        <div id="assignList" class="vstack gap-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
        <button type="button" class="btn btn-primary" onclick="saveAssignments()">Kaydet</button>
      </div>
    </div>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
let staffList = [], allServices = [], staffServices = {};
let staffModal, assignModal, assigningStaffId = null;

async function loadAll() {
  const [stRes, svRes] = await Promise.all([Panel.api('GET', '/staff'), Panel.api('GET', '/services')]);
  const rows = document.getElementById('staffRows');
  if (!stRes.ok) {
    rows.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-4">Yüklenemedi.</td></tr>';
    return;
  }
  staffList = stRes.body.data;
  allServices = svRes.ok ? svRes.body.data.filter(s => s.active) : [];

  // Her personelin atanmış hizmetleri (rozet gösterimi için)
  const assignments = await Promise.all(staffList.map(s => Panel.api('GET', '/staff/' + s.id + '/services')));
  staffServices = {};
  staffList.forEach((s, i) => { staffServices[s.id] = assignments[i].ok ? assignments[i].body.data.service_ids : []; });

  if (staffList.length === 0) {
    rows.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-4">Henüz personel yok.</td></tr>';
    return;
  }
  rows.innerHTML = staffList.map(s => {
    const badges = (staffServices[s.id] || []).map(id => {
      const sv = allServices.find(x => x.id === id);
      return sv ? '<span class="badge text-bg-light border me-1">' + Panel.esc(sv.name) + '</span>' : '';
    }).join('') || '<span class="text-body-tertiary small">Atanmamış</span>';
    return '<tr>' +
      '<td>' + Panel.esc(s.name) + '</td>' +
      '<td class="d-none d-md-table-cell">' + Panel.esc(s.phone || '—') + '</td>' +
      '<td>' + badges + '</td>' +
      '<td class="text-end"><div class="btn-group">' +
        '<button class="btn btn-sm btn-outline-secondary" onclick="openAssign(\'' + s.id + '\')" title="Hizmet ata"><i class="bi bi-link-45deg"></i></button>' +
        '<a class="btn btn-sm btn-outline-secondary" href="/panel/staff/' + s.id + '/hours" title="Çalışma saatleri"><i class="bi bi-clock"></i></a>' +
        '<button class="btn btn-sm btn-outline-primary" onclick="openModal(\'' + s.id + '\')" title="Düzenle"><i class="bi bi-pencil"></i></button>' +
        '<button class="btn btn-sm btn-outline-danger" onclick="deactivate(\'' + s.id + '\')" title="Pasifleştir"><i class="bi bi-archive"></i></button>' +
      '</div></td></tr>';
  }).join('');
}

function openModal(id) {
  document.getElementById('staffModalAlert').innerHTML = '';
  const s = id ? staffList.find(x => x.id === id) : null;
  document.getElementById('staffModalTitle').textContent = s ? 'Personeli Düzenle' : 'Yeni Personel';
  document.getElementById('stId').value = s ? s.id : '';
  document.getElementById('stName').value = s ? s.name : '';
  document.getElementById('stPhone').value = s ? (s.phone || '') : '';
  staffModal = staffModal || new bootstrap.Modal(document.getElementById('staffModal'));
  staffModal.show();
}

document.getElementById('staffForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const id = document.getElementById('stId').value;
  const payload = {
    name: document.getElementById('stName').value.trim(),
    phone: document.getElementById('stPhone').value.trim() || null,
  };
  const res = id
    ? await Panel.api('PUT', '/staff/' + id, payload)
    : await Panel.api('POST', '/staff', payload);
  if (res.ok) {
    staffModal.hide();
    Panel.alert('Personel kaydedildi.', 'success');
    loadAll();
    return;
  }
  document.getElementById('staffModalAlert').innerHTML =
    '<div class="alert alert-danger py-2">' + Panel.esc(res.body.message || 'Kaydedilemedi.') + '</div>';
});

function openAssign(staffId) {
  assigningStaffId = staffId;
  const s = staffList.find(x => x.id === staffId);
  document.getElementById('assignTitle').textContent = Panel.esc(s.name) + ' — Hizmet Ataması';
  document.getElementById('assignAlert').innerHTML = '';
  const current = staffServices[staffId] || [];
  document.getElementById('assignList').innerHTML = allServices.length === 0
    ? '<p class="text-body-secondary mb-0">Önce hizmet ekleyin.</p>'
    : allServices.map(sv =>
        '<div class="form-check">' +
        '<input class="form-check-input assign-cb" type="checkbox" value="' + sv.id + '" id="cb-' + sv.id + '"' +
        (current.includes(sv.id) ? ' checked' : '') + '>' +
        '<label class="form-check-label" for="cb-' + sv.id + '">' + Panel.esc(sv.name) +
        ' <small class="text-body-secondary">(' + sv.duration_minutes + ' dk)</small></label></div>'
      ).join('');
  assignModal = assignModal || new bootstrap.Modal(document.getElementById('svcAssignModal'));
  assignModal.show();
}

async function saveAssignments() {
  const ids = [...document.querySelectorAll('.assign-cb:checked')].map(cb => cb.value);
  const res = await Panel.api('PUT', '/staff/' + assigningStaffId + '/services', { service_ids: ids });
  if (res.ok) {
    assignModal.hide();
    Panel.alert('Hizmet ataması güncellendi.', 'success');
    loadAll();
  } else {
    document.getElementById('assignAlert').innerHTML =
      '<div class="alert alert-danger py-2">' + Panel.esc(res.body.message || 'Kaydedilemedi.') + '</div>';
  }
}

async function deactivate(id) {
  if (!confirm('Personel pasifleştirilsin mi?')) return;
  const res = await Panel.api('DELETE', '/staff/' + id);
  if (res.ok) Panel.alert('Personel pasifleştirildi.', 'success');
  else Panel.alert(res.body.message || 'İşlem başarısız.');
  loadAll();
}

loadAll();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
