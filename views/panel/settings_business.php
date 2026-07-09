<?php $title = 'İşletme Ayarları'; $active = 'settings_business'; ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">İşletme Ayarları</h1>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-body">
        <form id="bizForm" novalidate>
          <div class="mb-3">
            <label class="form-label" for="fBusinessName">İşletme Adı</label>
            <input type="text" class="form-control" id="fBusinessName" data-field="business_name" required>
            <div class="invalid-feedback"></div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="fAddress">Adres</label>
            <textarea class="form-control" id="fAddress" data-field="address" rows="2"></textarea>
            <div class="invalid-feedback"></div>
          </div>
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label" for="fLat">Enlem (lat)</label>
              <input type="number" step="any" class="form-control" id="fLat" data-field="location_lat" placeholder="ör. 41.0082">
              <div class="invalid-feedback"></div>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label" for="fLng">Boylam (lng)</label>
              <input type="number" step="any" class="form-control" id="fLng" data-field="location_lng" placeholder="ör. 28.9784">
              <div class="invalid-feedback"></div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="fTimezone">Zaman Dilimi</label>
            <select class="form-select" id="fTimezone" data-field="timezone">
              <option value="Europe/Istanbul">Europe/Istanbul</option>
            </select>
            <div class="invalid-feedback"></div>
          </div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-header bg-body"><i class="bi bi-image me-1"></i>Logo</div>
      <div class="card-body text-center">
        <div id="logoPreview" class="mb-3 text-body-tertiary">Logo yüklenmedi</div>
        <form id="logoForm">
          <input type="file" class="form-control mb-2" id="fLogo" accept="image/png,image/jpeg,image/webp">
          <div class="form-text mb-2">PNG, JPEG veya WEBP — en fazla 2MB.</div>
          <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-upload me-1"></i>Yükle</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
const FIELDS = ['business_name', 'address', 'location_lat', 'location_lng', 'timezone'];

function inputFor(field) { return document.querySelector('[data-field="' + field + '"]'); }

function showLogo(url) {
  document.getElementById('logoPreview').innerHTML = url
    ? '<img src="' + Panel.esc(url) + '" alt="Logo" class="img-fluid rounded" style="max-height:120px">'
    : 'Logo yüklenmedi';
}

function clearErrors() {
  document.querySelectorAll('#bizForm .is-invalid').forEach(el => el.classList.remove('is-invalid'));
}

async function loadSettings() {
  const res = await Panel.api('GET', '/settings');
  if (!res.ok) { Panel.alert(res.body.message || 'Ayarlar yüklenemedi.'); return; }
  const t = res.body.data;
  // Timezone listesini kayıtlı değerle senkron tut (select'te yoksa ekle).
  const sel = document.getElementById('fTimezone');
  if (t.timezone && ![...sel.options].some(o => o.value === t.timezone)) {
    sel.add(new Option(t.timezone, t.timezone));
  }
  FIELDS.forEach(f => { inputFor(f).value = t[f] ?? ''; });
  showLogo(t.logo_url);
  if (t.business_name) document.getElementById('brandName').textContent = t.business_name;
}

document.getElementById('bizForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  clearErrors();
  const body = {};
  FIELDS.forEach(f => {
    const v = inputFor(f).value.trim();
    body[f] = v === '' ? null : v;
  });
  if (body.business_name === null) { body.business_name = ''; } // backend 422 versin, alan hatası görünsün

  const res = await Panel.api('PATCH', '/settings', body);
  if (res.ok) {
    Panel.alert('İşletme ayarları kaydedildi.', 'success');
    if (res.body.data.business_name) document.getElementById('brandName').textContent = res.body.data.business_name;
  } else if (res.status === 422 && res.body.details) {
    // 03§6 alan bazlı hata sözleşmesi — form altına bas (06§5 ile aynı desen).
    Object.entries(res.body.details).forEach(([field, msg]) => {
      const el = inputFor(field);
      if (el) { el.classList.add('is-invalid'); el.parentElement.querySelector('.invalid-feedback').textContent = msg; }
    });
    Panel.alert('Formda hatalı alanlar var.');
  } else {
    Panel.alert(res.body.message || 'Kaydedilemedi.');
  }
});

document.getElementById('logoForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const file = document.getElementById('fLogo').files[0];
  if (!file) { Panel.alert('Önce bir dosya seçin.'); return; }

  // Multipart upload — Panel.api JSON'a sabit olduğu için düz fetch (Content-Type'ı tarayıcı koyar).
  const fd = new FormData();
  fd.append('logo', file);
  const res = await fetch('/settings/logo', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + Panel.token() },
    body: fd,
  });
  if (res.status === 401) { Panel.logout(); return; }
  const json = await res.json().catch(() => ({}));
  if (res.ok) {
    showLogo(json.data.logo_url);
    Panel.alert('Logo yüklendi.', 'success');
  } else {
    Panel.alert(json.message || 'Logo yüklenemedi.');
  }
});

loadSettings();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
