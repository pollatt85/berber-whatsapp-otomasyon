<?php $title = 'AI Asistan Ayarları'; $active = 'settings_ai'; ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">AI Asistan Ayarları</h1>
</div>

<div class="alert alert-light border small">
  <i class="bi bi-info-circle me-1"></i>AI asistan yalnızca <strong>bilgi verir</strong>
  (SSS, adres, politika); randevu oluşturma/iptal her zaman menü akışıyla yürür (07§1).
  Fiyat, süre ve çalışma saatleri buraya girilmez — Hizmetler ve Personel sayfalarındaki
  veriler asistana otomatik yansır (tek veri kaynağı, 07§3).
</div>

<form id="aiForm">
  <div class="card mb-3">
    <div class="card-body">
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch" id="aiEnabled">
        <label class="form-check-label fw-medium" for="aiEnabled">AI asistan etkin</label>
        <div class="form-text">Kapalıyken serbest metin sorulara sabit "menüden seçim yapın" yanıtı döner.</div>
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="aiTone">Konuşma Tonu</label>
          <select class="form-select" id="aiTone">
            <option value="friendly">Samimi (friendly)</option>
            <option value="formal">Resmî (formal)</option>
            <option value="concise">Kısa ve öz (concise)</option>
          </select>
          <div class="invalid-feedback" data-field="tone"></div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Dakikalık istek limiti</label>
          <input type="text" class="form-control" id="aiRateLimit" disabled>
          <div class="form-text">Platform tarafından belirlenir, panelden değiştirilemez.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center bg-body">
      <span class="fw-medium">Sık Sorulan Sorular</span>
      <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddFaq">
        <i class="bi bi-plus-lg me-1"></i>Soru Ekle
      </button>
    </div>
    <div class="card-body">
      <div id="faqList"></div>
      <div class="text-body-secondary small d-none" id="faqEmpty">
        Henüz SSS girilmedi. Örn: "Otopark var mı?" → "Bina önünde ücretsiz otopark mevcut."
      </div>
      <div class="invalid-feedback d-block" data-field="knowledge_base"></div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header bg-body fw-medium">Politikalar</div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label" for="polCancellation">İptal Politikası</label>
        <textarea class="form-control" id="polCancellation" rows="2"
          placeholder="Örn: Randevudan 2 saat öncesine kadar ücretsiz iptal edilebilir."></textarea>
      </div>
      <div>
        <label class="form-label" for="polLateArrival">Geç Kalma Politikası</label>
        <textarea class="form-control" id="polLateArrival" rows="2"
          placeholder="Örn: 10 dakikadan fazla gecikmede randevu iptal sayılabilir."></textarea>
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary" id="aiSaveBtn">
    <span class="spinner-border spinner-border-sm me-1 d-none" id="aiSaveSpinner"></span>Kaydet
  </button>
</form>
<?php $content = ob_get_clean(); ob_start(); ?>
function faqRow(q, a) {
  const div = document.createElement('div');
  div.className = 'row g-2 mb-2 faq-row align-items-start';
  div.innerHTML =
    '<div class="col-md-5"><input type="text" class="form-control faq-q" placeholder="Soru" value=""></div>' +
    '<div class="col-md-6"><input type="text" class="form-control faq-a" placeholder="Cevap" value=""></div>' +
    '<div class="col-md-1 d-grid"><button type="button" class="btn btn-outline-danger faq-del" title="Sil">' +
    '<i class="bi bi-trash"></i></button></div>';
  div.querySelector('.faq-q').value = q || '';
  div.querySelector('.faq-a').value = a || '';
  div.querySelector('.faq-del').addEventListener('click', () => { div.remove(); toggleFaqEmpty(); });
  return div;
}

function toggleFaqEmpty() {
  document.getElementById('faqEmpty').classList.toggle('d-none',
    document.querySelectorAll('#faqList .faq-row').length > 0);
}

document.getElementById('btnAddFaq').addEventListener('click', () => {
  document.getElementById('faqList').appendChild(faqRow('', ''));
  toggleFaqEmpty();
});

function clearErrors() {
  document.querySelectorAll('#aiForm .is-invalid').forEach(el => el.classList.remove('is-invalid'));
  document.querySelectorAll('#aiForm .invalid-feedback').forEach(el => { el.textContent = ''; });
}

async function loadAiSettings() {
  const res = await Panel.api('GET', '/settings/ai');
  if (!res.ok) { Panel.alert(res.body.message || 'AI ayarları yüklenemedi.'); return; }
  const s = res.body.data;
  document.getElementById('aiEnabled').checked = !!s.enabled;
  document.getElementById('aiTone').value = s.tone;
  document.getElementById('aiRateLimit').value = s.rate_limit_per_minute + ' istek/dk';
  let kb = s.knowledge_base;
  if (typeof kb === 'string') { try { kb = JSON.parse(kb); } catch (e) { kb = {}; } }
  const list = document.getElementById('faqList');
  list.innerHTML = '';
  ((kb && kb.faq) || []).forEach(item => list.appendChild(faqRow(item.q, item.a)));
  toggleFaqEmpty();
  const pol = (kb && kb.policies) || {};
  document.getElementById('polCancellation').value = pol.cancellation || '';
  document.getElementById('polLateArrival').value = pol.late_arrival || '';
}

document.getElementById('aiForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  clearErrors();
  const faq = [...document.querySelectorAll('#faqList .faq-row')].map(row => ({
    q: row.querySelector('.faq-q').value.trim(),
    a: row.querySelector('.faq-a').value.trim(),
  }));
  const btn = document.getElementById('aiSaveBtn');
  btn.disabled = true;
  document.getElementById('aiSaveSpinner').classList.remove('d-none');
  try {
    const res = await Panel.api('PATCH', '/settings/ai', {
      enabled: document.getElementById('aiEnabled').checked,
      tone: document.getElementById('aiTone').value,
      knowledge_base: {
        faq,
        policies: {
          cancellation: document.getElementById('polCancellation').value.trim(),
          late_arrival: document.getElementById('polLateArrival').value.trim(),
        },
      },
    });
    if (res.ok) {
      Panel.alert('AI ayarları kaydedildi.', 'success');
      loadAiSettings();
    } else if (res.status === 422 && res.body.details) {
      for (const [field, msg] of Object.entries(res.body.details)) {
        const fb = document.querySelector('#aiForm .invalid-feedback[data-field="' + field + '"]');
        if (fb) fb.textContent = msg;
        const input = { tone: 'aiTone' }[field];
        if (input) document.getElementById(input).classList.add('is-invalid');
      }
      Panel.alert('Formda hatalar var, işaretli alanları düzeltin.', 'warning');
    } else {
      Panel.alert(res.body.message || 'Kaydedilemedi.');
    }
  } finally {
    btn.disabled = false;
    document.getElementById('aiSaveSpinner').classList.add('d-none');
  }
});

loadAiSettings();
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
