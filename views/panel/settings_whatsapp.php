<?php $title = 'WhatsApp Ayarları'; $active = 'settings_whatsapp'; ob_start(); ?>
<h1 class="h3 mb-4">WhatsApp Bağlantısı</h1>

<div class="card" style="max-width: 640px">
  <div class="card-body">
    <div class="d-flex align-items-center gap-3 mb-4">
      <i class="bi bi-whatsapp fs-1 text-success"></i>
      <div>
        <div class="fw-semibold" id="waBusinessName">—</div>
        <div class="text-body-secondary small">Telefon Numarası ID: <code id="waPhoneId">—</code></div>
      </div>
      <div class="ms-auto" id="waStatusBadge"></div>
    </div>

    <div id="waStatusInfo" class="alert alert-secondary"></div>

    <!-- 05§1 Embedded Signup — gerçek Meta App yapılandırması olmadan tetiklenemez;
         buton, akış hazır olana dek bilgilendirme gösterir (BACKLOG notu). -->
    <button class="btn btn-success" id="reconnectBtn" onclick="reconnect()">
      <i class="bi bi-arrow-repeat me-1"></i>Yeniden Bağlan
    </button>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
const WA_STATUS = {
  connected:    { badge: 'success',  label: 'Bağlı',
                  info: 'WhatsApp Business hesabınız bağlı, mesaj gönderimi aktif.' },
  pending:      { badge: 'warning',  label: 'Bağlantı bekleniyor',
                  info: 'WhatsApp Business hesabınız henüz bağlanmadı. Kurulum ekibiyle iletişime geçin veya "Yeniden Bağlan" ile Embedded Signup akışını başlatın.' },
  disconnected: { badge: 'danger',   label: 'Bağlantı kesildi',
                  info: 'Erişim anahtarı geçersiz (ör. Meta 190 hatası). "Yeniden Bağlan" ile yeniden yetkilendirme gerekir.' },
};

(async () => {
  const res = await Panel.api('GET', '/settings');
  if (!res.ok) {
    Panel.alert(res.body.message || 'Ayarlar yüklenemedi.');
    return;
  }
  const t = res.body.data;
  document.getElementById('brandName').textContent = t.business_name;
  document.getElementById('waBusinessName').textContent = t.business_name;
  document.getElementById('waPhoneId').textContent = t.phone_number_id || '—';

  const st = WA_STATUS[t.whatsapp_status] || WA_STATUS.pending;
  document.getElementById('waStatusBadge').innerHTML =
    '<span class="badge fs-6 text-bg-' + st.badge + '">' + st.label + '</span>';
  document.getElementById('waStatusInfo').textContent = st.info;
})();

function reconnect() {
  // Embedded Signup (05§1) gerçek bir Meta App ID + config gerektirir — henüz yapılandırılmadı.
  Panel.alert('Embedded Signup akışı henüz yapılandırılmadı. Gerçek Meta WABA bağlantısı kurulduğunda bu buton yeniden yetkilendirme penceresini açacak.', 'warning');
}
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
