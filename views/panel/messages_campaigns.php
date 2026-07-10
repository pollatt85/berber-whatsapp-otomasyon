<?php $title = 'Kampanyalar'; $active = 'messages_campaigns'; ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Kampanyalar</h1>
  <button class="btn btn-primary" id="btnNewCampaign"><i class="bi bi-plus-lg me-1"></i>Yeni Kampanya</button>
</div>

<div class="alert alert-light border small">
  <i class="bi bi-info-circle me-1"></i>Kampanya mesajı olarak yalnızca <strong>aktif</strong> ve
  <strong>kampanya türü</strong> şablonlar seçilebilir (şablonlar Meta'da onaylanır —
  <a href="/panel/messages/templates">Şablonlar</a>). Gönderim altyapısı henüz devrede değil;
  kampanyalar şimdilik taslak/planlı olarak kaydedilir.
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th>Ad</th><th>Şablon</th><th class="d-none d-md-table-cell">Hedef</th>
        <th class="d-none d-md-table-cell">Planlanan</th><th>Durum</th><th class="text-end">İşlem</th>
      </tr></thead>
      <tbody id="campRows"><tr><td colspan="6" class="text-center text-body-secondary py-4">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
</div>

<!-- Kampanya oluştur/düzenle modalı (06§7) -->
<div class="modal fade" id="campModal" tabindex="-1" aria-labelledby="campModalTitle">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="campForm">
        <div class="modal-header">
          <h5 class="modal-title" id="campModalTitle">Yeni Kampanya</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
        </div>
        <div class="modal-body">
          <div id="campAlert"></div>
          <input type="hidden" id="campId">
          <div class="mb-3">
            <label class="form-label" for="campName">Kampanya Adı</label>
            <input type="text" class="form-control" id="campName">
            <div class="invalid-feedback" data-field="name"></div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="campTemplate">Şablon (yalnızca aktif kampanya şablonları)</label>
            <select class="form-select" id="campTemplate"><option value="">Yükleniyor…</option></select>
            <div class="invalid-feedback" data-field="template_id"></div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="campMinDays">Hedef: son ziyaretten en az kaç gün geçmiş olsun?</label>
            <input type="number" class="form-control" id="campMinDays" min="1" placeholder="Boş = tüm müşteriler">
            <div class="invalid-feedback" data-field="last_visit_min_days"></div>
          </div>
          <div class="mb-2">
            <label class="form-label" for="campScheduledAt">Gönderim Zamanı (boş = taslak)</label>
            <input type="datetime-local" class="form-control" id="campScheduledAt">
            <div class="invalid-feedback" data-field="scheduled_at"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
          <button type="submit" class="btn btn-primary" id="campSaveBtn">Kaydet</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
let campaigns = [];
let campaignTemplates = [];
const campModal = new bootstrap.Modal(document.getElementById('campModal'));

const CAMP_STATUS = {
  draft: { label: 'Taslak', badge: 'secondary' },
  scheduled: { label: 'Planlandı', badge: 'info' },
  sent: { label: 'Gönderildi', badge: 'success' },
  cancelled: { label: 'İptal', badge: 'dark' },
};

function campStatusBadge(s) {
  const c = CAMP_STATUS[s] || { label: s, badge: 'light' };
  return '<span class="badge text-bg-' + c.badge + '">' + Panel.esc(c.label) + '</span>';
}

function targetSummary(raw) {
  let f = raw;
  if (typeof f === 'string') { try { f = JSON.parse(f); } catch (e) { f = {}; } }
  if (f && f.last_visit_min_days) return 'Son ziyaret ≥ ' + f.last_visit_min_days + ' gün önce';
  return 'Tüm müşteriler';
}

async function loadTemplateOptions() {
  const res = await Panel.api('GET', '/messages/templates');
  if (!res.ok) return;
  // 06§7: yalnızca template_type='campaign' ve active=true — sunucu da ayrıca doğrular.
  campaignTemplates = res.body.data.filter(t => t.template_type === 'campaign' && t.active);
  document.getElementById('campTemplate').innerHTML = '<option value="">Seçin…</option>' +
    campaignTemplates.map(t => '<option value="' + t.id + '">' + Panel.esc(t.internal_name) + '</option>').join('');
}

async function loadCampaigns() {
  const rows = document.getElementById('campRows');
  const res = await Panel.api('GET', '/campaigns');
  if (!res.ok) {
    Panel.alert(res.body.message || 'Kampanyalar yüklenemedi.');
    rows.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Hata oluştu.</td></tr>';
    return;
  }
  campaigns = res.body.data;
  if (campaigns.length === 0) {
    rows.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-4">Henüz kampanya yok.</td></tr>';
    return;
  }
  rows.innerHTML = campaigns.map(c => {
    const editable = c.status === 'draft' || c.status === 'scheduled';
    const actions = editable
      ? '<div class="btn-group">' +
        '<button class="btn btn-sm btn-outline-primary" onclick="editCampaign(\'' + c.id + '\')" title="Düzenle"><i class="bi bi-pencil"></i></button>' +
        '<button class="btn btn-sm btn-outline-danger" onclick="cancelCampaign(\'' + c.id + '\')" title="İptal et"><i class="bi bi-x-lg"></i></button></div>'
      : '';
    const sched = c.scheduled_at
      ? (() => { const d = new Date(String(c.scheduled_at).replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));
                 return Panel.fmtDate(d) + ' ' + Panel.fmtTime(d); })()
      : '—';
    return '<tr>' +
      '<td class="fw-medium">' + Panel.esc(c.name) + '</td>' +
      '<td><code class="small">' + Panel.esc(c.template_name) + '</code></td>' +
      '<td class="d-none d-md-table-cell">' + Panel.esc(targetSummary(c.target_filter)) + '</td>' +
      '<td class="d-none d-md-table-cell">' + sched + '</td>' +
      '<td>' + campStatusBadge(c.status) + '</td>' +
      '<td class="text-end">' + actions + '</td></tr>';
  }).join('');
}

function clearFieldErrors() {
  document.querySelectorAll('#campForm .is-invalid').forEach(el => el.classList.remove('is-invalid'));
  document.querySelectorAll('#campForm .invalid-feedback').forEach(el => { el.textContent = ''; });
}

function showFieldErrors(details) {
  const map = { name: 'campName', template_id: 'campTemplate', last_visit_min_days: 'campMinDays', scheduled_at: 'campScheduledAt' };
  for (const [field, msg] of Object.entries(details || {})) {
    const input = document.getElementById(map[field]);
    const fb = document.querySelector('#campForm .invalid-feedback[data-field="' + field + '"]');
    if (input) input.classList.add('is-invalid');
    if (fb) fb.textContent = msg;
  }
}

function openCampaignModal(campaign) {
  clearFieldErrors();
  document.getElementById('campAlert').innerHTML = '';
  document.getElementById('campForm').reset();
  document.getElementById('campId').value = campaign ? campaign.id : '';
  document.getElementById('campModalTitle').textContent = campaign ? 'Kampanyayı Düzenle' : 'Yeni Kampanya';
  if (campaign) {
    document.getElementById('campName').value = campaign.name;
    document.getElementById('campTemplate').value = campaign.template_id;
    let f = campaign.target_filter;
    if (typeof f === 'string') { try { f = JSON.parse(f); } catch (e) { f = {}; } }
    document.getElementById('campMinDays').value = (f && f.last_visit_min_days) || '';
    if (campaign.scheduled_at) {
      const d = new Date(String(campaign.scheduled_at).replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));
      // datetime-local yerel saat ister, ISO'nun ilk 16 karakteri (sv-SE = YYYY-MM-DD HH:mm).
      document.getElementById('campScheduledAt').value =
        d.toLocaleDateString('sv-SE') + 'T' + d.toLocaleTimeString('sv-SE').slice(0, 5);
    }
  }
  campModal.show();
}

function editCampaign(id) {
  const c = campaigns.find(x => x.id === id);
  if (c) openCampaignModal(c);
}

async function cancelCampaign(id) {
  if (!confirm('Kampanya iptal edilsin mi?')) return;
  const res = await Panel.api('PATCH', '/campaigns/' + id + '/cancel');
  if (res.ok) Panel.alert('Kampanya iptal edildi.', 'success');
  else Panel.alert(res.body.message || 'İptal başarısız.');
  loadCampaigns();
}

document.getElementById('btnNewCampaign').addEventListener('click', () => openCampaignModal(null));

document.getElementById('campForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  clearFieldErrors();
  const id = document.getElementById('campId').value;
  const schedLocal = document.getElementById('campScheduledAt').value;
  const payload = {
    name: document.getElementById('campName').value,
    template_id: document.getElementById('campTemplate').value,
    last_visit_min_days: document.getElementById('campMinDays').value || null,
    // TR kalıcı UTC+3 (randevu modalıyla aynı gerekçe).
    scheduled_at: schedLocal ? schedLocal + ':00+03:00' : null,
  };
  const res = id
    ? await Panel.api('PATCH', '/campaigns/' + id, payload)
    : await Panel.api('POST', '/campaigns', payload);
  if (res.ok) {
    campModal.hide();
    Panel.alert(id ? 'Kampanya güncellendi.' : 'Kampanya oluşturuldu.', 'success');
    loadCampaigns();
  } else if (res.status === 422 && res.body.details) {
    showFieldErrors(res.body.details);
  } else {
    Panel.alert(res.body.message || 'Kaydedilemedi.', 'danger', 'campAlert');
  }
});

loadTemplateOptions();
loadCampaigns();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
