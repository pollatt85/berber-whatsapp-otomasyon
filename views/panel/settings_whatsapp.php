<?php
$title = 'WhatsApp Ayarları';
$active = 'settings_whatsapp';
// Embedded Signup (05§1) istemci yapılandırması — App ID/config ID sır değildir (popup'ta
// zaten görünür); App Secret ASLA buraya konmaz, code exchange sunucuda yapılır.
$metaAppId = \App\Config\Env::get('META_APP_ID', '');
$metaEsConfigId = \App\Config\Env::get('META_ES_CONFIG_ID', '');
ob_start();
?>
<h1 class="h3 mb-4">WhatsApp Bağlantısı</h1>

<div class="card mb-4" style="max-width: 640px">
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

    <!-- 05§1 Embedded Signup — FB.login + config_id; dönen code sunucuda token'a çevrilir,
         WABA webhook aboneliği (subscribed_apps, BACKLOG m.29) otomatik yapılır. -->
    <button class="btn btn-success" id="reconnectBtn" onclick="startEmbeddedSignup()">
      <i class="bi bi-arrow-repeat me-1"></i>Yeniden Bağlan
    </button>
    <div id="esResult" class="mt-3"></div>
  </div>
</div>

<!-- Mesajlaşma tier/kalite uyarı alanı (09§2/§6, BACKLOG m.17) -->
<div class="card" style="max-width: 640px">
  <div class="card-body">
    <h2 class="h5 mb-3">Mesajlaşma Limiti ve Kalite</h2>
    <div id="waHealthBody" class="text-body-secondary">Yükleniyor…</div>
  </div>
</div>
<?php $content = ob_get_clean(); ob_start(); ?>
const META_APP_ID = <?= json_encode($metaAppId) ?>;
const META_ES_CONFIG_ID = <?= json_encode($metaEsConfigId) ?>;

const WA_STATUS = {
  connected:    { badge: 'success',  label: 'Bağlı',
                  info: 'WhatsApp Business hesabınız bağlı, mesaj gönderimi aktif.' },
  pending:      { badge: 'warning',  label: 'Bağlantı bekleniyor',
                  info: 'WhatsApp Business hesabınız henüz bağlanmadı. "Yeniden Bağlan" ile Embedded Signup akışını başlatın.' },
  disconnected: { badge: 'danger',   label: 'Bağlantı kesildi',
                  info: 'Erişim anahtarı geçersiz (ör. Meta 190 hatası). "Yeniden Bağlan" ile yeniden yetkilendirme gerekir.' },
};

// Meta messaging_limit_tier → insan okur etiket (09§2: tier düşükse kampanya gönderimi kısıtlı).
const TIER_LABELS = {
  TIER_50: '50 müşteri / 24 saat', TIER_250: '250 müşteri / 24 saat',
  TIER_1K: '1.000 müşteri / 24 saat', TIER_10K: '10.000 müşteri / 24 saat',
  TIER_100K: '100.000 müşteri / 24 saat', TIER_UNLIMITED: 'Sınırsız',
};
const QUALITY_BADGES = { GREEN: 'success', YELLOW: 'warning', RED: 'danger' };

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

  loadHealth();
})();

async function loadHealth() {
  const el = document.getElementById('waHealthBody');
  const res = await Panel.api('GET', '/settings/whatsapp/health');
  if (!res.ok) {
    el.textContent = 'Bilgi alınamadı.';
    return;
  }
  const h = res.body.data;
  if (!h.available) {
    el.textContent = h.reason === 'not_connected'
      ? 'WhatsApp bağlantısı kurulunca numara kalite ve limit bilgisi burada görünür.'
      : 'Meta\'dan bilgi alınamadı' + (h.meta_error ? ': ' + h.meta_error : '.');
    return;
  }
  const tier = TIER_LABELS[h.messaging_limit_tier] || h.messaging_limit_tier || 'Bilinmiyor';
  const qBadge = QUALITY_BADGES[h.quality_rating] || 'secondary';
  const lowTier = h.messaging_limit_tier === 'TIER_50' || h.messaging_limit_tier === 'TIER_250';
  el.innerHTML =
    '<dl class="row mb-0">' +
    '<dt class="col-sm-5">Numara</dt><dd class="col-sm-7">' + Panel.esc(h.display_phone_number || '—') +
    (h.verified_name ? ' <span class="text-body-secondary">(' + Panel.esc(h.verified_name) + ')</span>' : '') + '</dd>' +
    '<dt class="col-sm-5">Kalite puanı</dt><dd class="col-sm-7"><span class="badge text-bg-' + qBadge + '">' +
    Panel.esc(h.quality_rating || 'Bilinmiyor') + '</span></dd>' +
    '<dt class="col-sm-5">Mesajlaşma limiti</dt><dd class="col-sm-7">' + Panel.esc(tier) + '</dd>' +
    '</dl>' +
    (lowTier ? '<div class="alert alert-warning mt-3 mb-0 small">Mesajlaşma limitiniz düşük kademede — ' +
      'toplu kampanya gönderimleri bu sınırı aşamaz. Limit, Meta tarafından kaliteli gönderim ' +
      'geçmişiyle otomatik yükseltilir.</div>' : '');
}

// --- Embedded Signup (05§1) ---
// Akış: FB SDK yüklenir → FB.login(config_id, response_type:'code') popup'ı → popup
// tamamlanınca Meta iki şey verir: (1) message event ile seçilen waba_id/phone_number_id,
// (2) login callback ile tek kullanımlık code. Üçü birlikte POST /settings/whatsapp/connect'e
// gider; sunucu code'u token'a çevirir, WABA'yı app webhook'una abone eder (m.29) ve kaydeder.
let esSession = { wabaId: null, phoneNumberId: null };

window.addEventListener('message', (event) => {
  if (!event.origin.endsWith('facebook.com')) return;
  try {
    const data = JSON.parse(event.data);
    if (data.type === 'WA_EMBEDDED_SIGNUP' && data.event === 'FINISH') {
      esSession.wabaId = data.data.waba_id;
      esSession.phoneNumberId = data.data.phone_number_id;
    }
  } catch (e) { /* facebook.com'dan JSON olmayan mesajlar da gelir — yok say */ }
});

function startEmbeddedSignup() {
  if (!META_APP_ID || !META_ES_CONFIG_ID) {
    Panel.alert('Embedded Signup için META_APP_ID ve META_ES_CONFIG_ID (.env) yapılandırılmalı. ' +
      'Config ID, Meta Dashboard > Facebook Login for Business > Configurations bölümünden oluşturulur.', 'warning');
    return;
  }
  if (typeof FB === 'undefined') {
    loadFbSdk(() => startEmbeddedSignup());
    return;
  }
  showEsResult(null, 'Popup açıldı, bekleniyor…');
  FB.login((response) => {
    const code = response.authResponse && response.authResponse.code;
    if (!code) {
      showEsResult(false, 'Meta yetkilendirmesi tamamlanmadı (popup kapatıldı veya reddedildi). Ham yanıt: ' +
        JSON.stringify(response));
      return;
    }
    submitConnect(code).catch((e) => {
      console.error('submitConnect hatası:', e);
      showEsResult(false, 'Beklenmeyen hata: ' + (e && e.message ? e.message : String(e)));
    });
  }, {
    config_id: META_ES_CONFIG_ID,
    response_type: 'code',
    override_default_response_type: true,
    extras: {
      setup: {},
      featureType: '',
      sessionInfoVersion: '3',
    },
  });
}

function loadFbSdk(onReady) {
  window.fbAsyncInit = function () {
    FB.init({ appId: META_APP_ID, autoLogAppEvents: false, xfbml: false, version: 'v20.0' });
    onReady();
  };
  const s = document.createElement('script');
  s.src = 'https://connect.facebook.net/en_US/sdk.js';
  s.async = true;
  s.onerror = () => showEsResult(false, 'Facebook SDK yüklenemedi (ağ/engelleyici?).');
  document.head.appendChild(s);
}

async function submitConnect(code) {
  // WA_EMBEDDED_SIGNUP mesajı bazen FB.login callback'inden sonra gelir (postMessage
  // async) — hemen hata vermek yerine kısa bir süre (max 3sn, 100ms aralıklarla) bekle.
  for (let i = 0; i < 30 && (!esSession.wabaId || !esSession.phoneNumberId); i++) {
    await new Promise((r) => setTimeout(r, 100));
  }
  if (!esSession.wabaId || !esSession.phoneNumberId) {
    showEsResult(false, 'Popup\'tan WABA/numara bilgisi alınamadı. Akışı sonuna kadar tamamlayıp tekrar deneyin.');
    return;
  }
  showEsResult(null, 'Bağlanıyor…');
  const res = await Panel.api('POST', '/settings/whatsapp/connect', {
    code: code, waba_id: esSession.wabaId, phone_number_id: esSession.phoneNumberId,
  });
  if (!res.ok) {
    showEsResult(false, res.body.message || 'Bağlantı başarısız.');
    return;
  }
  showEsResult(true, 'WhatsApp hesabınız bağlandı. Sayfa yenileniyor…');
  setTimeout(() => window.location.reload(), 1500);
}

function showEsResult(ok, msg) {
  const cls = ok === null ? 'alert-secondary' : ok ? 'alert-success' : 'alert-danger';
  document.getElementById('esResult').innerHTML =
    '<div class="alert ' + cls + ' mb-0">' + Panel.esc(msg) + '</div>';
}
<?php $script = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
