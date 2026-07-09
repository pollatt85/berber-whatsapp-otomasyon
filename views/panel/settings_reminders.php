<?php $title = 'Hatırlatma Ayarları'; $active = 'settings_reminders'; ob_start(); ?>
<h1 class="h3 mb-4">Hatırlatma Ayarları</h1>

<div class="card" style="max-width: 640px">
  <div class="card-body">
    <form id="remForm" novalidate>
      <div class="mb-4">
        <label class="form-label" for="remHours">Randevu hatırlatması (saat önce)</label>
        <input type="number" class="form-control" id="remHours" min="1" max="168" required>
        <div class="invalid-feedback" id="err-reminder_hours_before"></div>
        <div class="form-text">Müşteriye WhatsApp hatırlatma mesajı, randevudan bu kadar saat önce gönderilir (1-168).</div>
      </div>
      <div class="mb-4">
        <label class="form-label" for="remTtl">Onaysız randevu zaman aşımı (dakika)</label>
        <input type="number" class="form-control" id="remTtl" min="1" max="1440" required>
        <div class="invalid-feedback" id="err-pending_ttl_minutes"></div>
        <div class="form-text">Bu süre içinde onaylanmayan "onay bekliyor" randevular otomatik iptal edilir (1-1440).</div>
      </div>
      <button class="btn btn-primary" type="submit" id="remSaveBtn"><i class="bi bi-save me-1"></i>Kaydet</button>
    </form>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
(async () => {
  const res = await Panel.api('GET', '/settings');
  if (!res.ok) {
    Panel.alert(res.body.message || 'Ayarlar yüklenemedi.');
    return;
  }
  document.getElementById('brandName').textContent = res.body.data.business_name;
  document.getElementById('remHours').value = res.body.data.reminder_hours_before;
  document.getElementById('remTtl').value = res.body.data.pending_ttl_minutes;
})();

document.getElementById('remForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  for (const el of document.querySelectorAll('#remForm .is-invalid')) el.classList.remove('is-invalid');

  const btn = document.getElementById('remSaveBtn');
  btn.disabled = true;
  const res = await Panel.api('PATCH', '/settings', {
    reminder_hours_before: parseInt(document.getElementById('remHours').value, 10),
    pending_ttl_minutes: parseInt(document.getElementById('remTtl').value, 10),
  });
  btn.disabled = false;

  if (res.ok) {
    Panel.alert('Hatırlatma ayarları kaydedildi.', 'success');
    return;
  }
  if (res.status === 422 && res.body.details) {
    // 03§6 alan bazlı hata sözleşmesi doğrudan form alanlarına basılır (06§5).
    const map = { reminder_hours_before: 'remHours', pending_ttl_minutes: 'remTtl' };
    for (const [field, msg] of Object.entries(res.body.details)) {
      const input = document.getElementById(map[field]);
      const err = document.getElementById('err-' + field);
      if (input && err) { input.classList.add('is-invalid'); err.textContent = msg; }
    }
    return;
  }
  Panel.alert(res.body.message || 'Kaydedilemedi.');
});
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
