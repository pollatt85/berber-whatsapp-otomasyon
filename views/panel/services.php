<?php $title = 'Hizmetler'; $active = 'services'; ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Hizmetler</h1>
  <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg me-1"></i>Yeni Hizmet</button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Ad</th><th>Süre</th><th>Fiyat</th><th>Durum</th><th class="text-end">İşlem</th></tr></thead>
      <tbody id="svcRows"><tr><td colspan="5" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="svcModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="svcForm" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="svcModalTitle">Yeni Hizmet</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="svcModalAlert"></div>
          <input type="hidden" id="svcId">
          <div class="mb-3">
            <label class="form-label" for="svcName">Hizmet adı</label>
            <input type="text" class="form-control" id="svcName" required>
            <div class="invalid-feedback" id="err-name"></div>
          </div>
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label" for="svcDuration">Süre (dk)</label>
              <input type="number" class="form-control" id="svcDuration" min="5" step="5" required>
              <div class="invalid-feedback" id="err-duration_minutes"></div>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label" for="svcPrice">Fiyat (₺)</label>
              <input type="number" class="form-control" id="svcPrice" min="0" step="0.01" required>
              <div class="invalid-feedback" id="err-price"></div>
            </div>
          </div>
          <div class="form-check" id="svcActiveWrap" style="display:none">
            <input class="form-check-input" type="checkbox" id="svcActive">
            <label class="form-check-label" for="svcActive">Aktif</label>
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
<?php $content = ob_get_clean(); ob_start(); ?>
let services = [];
let modal;

async function loadServices() {
  const res = await Panel.api('GET', '/services');
  const rows = document.getElementById('svcRows');
  if (!res.ok) {
    rows.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Yüklenemedi.</td></tr>';
    return;
  }
  services = res.body.data;
  if (services.length === 0) {
    rows.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-4">Henüz hizmet yok.</td></tr>';
    return;
  }
  rows.innerHTML = services.map(s => '<tr>' +
    '<td>' + Panel.esc(s.name) + '</td>' +
    '<td>' + s.duration_minutes + ' dk</td>' +
    '<td>' + Number(s.price).toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ₺</td>' +
    '<td>' + (s.active ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>') + '</td>' +
    '<td class="text-end"><div class="btn-group">' +
      '<button class="btn btn-sm btn-outline-primary" onclick="openModal(\'' + s.id + '\')" title="Düzenle"><i class="bi bi-pencil"></i></button>' +
      (s.active ? '<button class="btn btn-sm btn-outline-danger" onclick="deactivate(\'' + s.id + '\')" title="Pasifleştir"><i class="bi bi-archive"></i></button>' : '') +
    '</div></td></tr>').join('');
}

function clearErrors() {
  document.getElementById('svcModalAlert').innerHTML = '';
  for (const el of document.querySelectorAll('#svcForm .is-invalid')) el.classList.remove('is-invalid');
}

function openModal(id) {
  clearErrors();
  const s = id ? services.find(x => x.id === id) : null;
  document.getElementById('svcModalTitle').textContent = s ? 'Hizmeti Düzenle' : 'Yeni Hizmet';
  document.getElementById('svcId').value = s ? s.id : '';
  document.getElementById('svcName').value = s ? s.name : '';
  document.getElementById('svcDuration').value = s ? s.duration_minutes : 30;
  document.getElementById('svcPrice').value = s ? s.price : '';
  document.getElementById('svcActiveWrap').style.display = s ? '' : 'none';
  document.getElementById('svcActive').checked = s ? !!s.active : true;
  modal = modal || new bootstrap.Modal(document.getElementById('svcModal'));
  modal.show();
}

document.getElementById('svcForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  clearErrors();
  const id = document.getElementById('svcId').value;
  const payload = {
    name: document.getElementById('svcName').value.trim(),
    duration_minutes: parseInt(document.getElementById('svcDuration').value, 10),
    price: document.getElementById('svcPrice').value,
  };
  if (id) payload.active = document.getElementById('svcActive').checked;

  const res = id
    ? await Panel.api('PUT', '/services/' + id, payload)
    : await Panel.api('POST', '/services', payload);

  if (res.ok) {
    modal.hide();
    Panel.alert('Hizmet kaydedildi.', 'success');
    loadServices();
    return;
  }
  // 06§5: Backend 422 sözleşmesi doğrudan form altına basılır, panelde ayrıca iş kuralı yok.
  document.getElementById('svcModalAlert').innerHTML =
    '<div class="alert alert-danger py-2">' + Panel.esc(res.body.message || 'Kaydedilemedi.') + '</div>';
});

async function deactivate(id) {
  if (!confirm('Hizmet pasifleştirilsin mi? (Mevcut randevular etkilenmez)')) return;
  const res = await Panel.api('DELETE', '/services/' + id);
  if (res.ok) Panel.alert('Hizmet pasifleştirildi.', 'success');
  else Panel.alert(res.body.message || 'İşlem başarısız.');
  loadServices();
}

loadServices();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
