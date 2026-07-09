<?php $title = 'Mesaj Şablonları'; $active = 'messages_templates'; ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Mesaj Şablonları</h1>
  <button class="btn btn-primary" id="btnSync">
    <span class="spinner-border spinner-border-sm me-1 d-none" id="syncSpinner"></span>
    <i class="bi bi-arrow-repeat me-1" id="syncIcon"></i>Meta ile Senkronize Et
  </button>
</div>

<div class="alert alert-light border small">
  <i class="bi bi-info-circle me-1"></i>Şablonlar salt okunurdur — yeni şablon
  <strong>Meta Business Manager</strong>'da oluşturulup onaylanır; buradaki liste
  "Meta ile Senkronize Et" ile güncellenir.
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th>İç Ad</th><th class="d-none d-md-table-cell">Meta Şablon Adı</th>
        <th>Tür</th><th class="d-none d-md-table-cell">Değişkenler</th><th>Durum</th>
      </tr></thead>
      <tbody id="tplRows"><tr><td colspan="5" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
const TPL_TYPE = {
  reminder: { label: 'Hatırlatma', badge: 'info' },
  confirmation: { label: 'Onay', badge: 'success' },
  cancellation: { label: 'İptal', badge: 'secondary' },
  campaign: { label: 'Kampanya', badge: 'warning' },
  other: { label: 'Diğer', badge: 'light' },
};

function typeBadge(t) {
  const s = TPL_TYPE[t] || { label: t, badge: 'light' };
  return '<span class="badge text-bg-' + s.badge + '">' + Panel.esc(s.label) + '</span>';
}

function variablesSummary(raw) {
  let v = raw;
  if (typeof v === 'string') { try { v = JSON.parse(v); } catch (e) { v = []; } }
  if (!Array.isArray(v) || v.length === 0) return '<span class="text-body-tertiary">—</span>';
  return v.map(x => '<code class="small me-1">{{' + Panel.esc(x) + '}}</code>').join('');
}

async function loadTemplates() {
  const rows = document.getElementById('tplRows');
  const res = await Panel.api('GET', '/messages/templates');
  if (!res.ok) {
    Panel.alert(res.body.message || 'Şablonlar yüklenemedi.');
    rows.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Hata oluştu.</td></tr>';
    return;
  }
  const tpls = res.body.data;
  if (tpls.length === 0) {
    rows.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-4">' +
      'Henüz şablon yok. "Meta ile Senkronize Et" ile Meta\'daki onaylı şablonları çekebilirsiniz.</td></tr>';
    return;
  }
  rows.innerHTML = tpls.map(t => '<tr>' +
    '<td class="fw-medium">' + Panel.esc(t.internal_name) + '</td>' +
    '<td class="d-none d-md-table-cell"><code class="small">' + Panel.esc(t.meta_template_name) + '</code></td>' +
    '<td>' + typeBadge(t.template_type) + '</td>' +
    '<td class="d-none d-md-table-cell">' + variablesSummary(t.variables) + '</td>' +
    '<td>' + (t.active
      ? '<span class="badge text-bg-success">Aktif</span>'
      : '<span class="badge text-bg-secondary">Pasif</span>') + '</td></tr>').join('');
}

document.getElementById('btnSync').addEventListener('click', async () => {
  const btn = document.getElementById('btnSync');
  btn.disabled = true;
  document.getElementById('syncSpinner').classList.remove('d-none');
  document.getElementById('syncIcon').classList.add('d-none');
  try {
    const res = await Panel.api('POST', '/messages/templates/sync', {});
    if (res.ok) {
      Panel.alert('Senkronizasyon tamamlandı: ' + res.body.data.length + ' şablon güncellendi.', 'success');
      loadTemplates();
    } else {
      Panel.alert(res.body.message || 'Senkronizasyon başarısız.');
    }
  } finally {
    btn.disabled = false;
    document.getElementById('syncSpinner').classList.add('d-none');
    document.getElementById('syncIcon').classList.remove('d-none');
  }
});

loadTemplates();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
