# 06 — Admin Panel

Bu doküman, panelin sayfa yapısını, hangi Backend endpoint'ini kullandığını ve önceki
fazlarda kararlaştırılan kısıtları (RLS/JWT `tenant_id` enjeksiyonu, panel şablon
oluşturamaz, vb.) nasıl yansıttığını tanımlar. Teknoloji seçimi (PHP + Bootstrap 5,
responsive) ve auth modeli (panel JWT) tekrar edilmez; bkz. 01_System_Architecture.md,
03_Backend_API.md.

## 1. Bilgi Mimarisi (Sayfa Haritası)

```
/login
/dashboard                     ← özet kartlar + günlük takvim
/appointments                  ← liste + takvim görünümü
/appointments/{id}             ← detay, durum değiştirme
/services                      ← hizmet + fiyat + süre CRUD
/staff                         ← personel CRUD + çalışma saatleri + molalar
/staff/{id}/hours              ← haftalık çalışma saati + tatil takvimi
/customers                     ← müşteri listesi + geçmiş
/messages/templates            ← şablon listesi (salt okunur senkron, §6 05'e bağlı)
/messages/campaigns            ← kampanya oluştur/listele
/messages/log                  ← gönderilen/alınan mesaj denetimi
/settings/business              ← işletme adı, logo, adres, konum
/settings/whatsapp              ← WABA bağlantı durumu (05 §1 Embedded Signup tetikleyici)
/settings/reminders             ← reminder_hours_before, pending_ttl_minutes (04 madde 7)
/settings/ai                    ← ai_settings (07'de detaylandırılacak, bu fazda yalnızca CRUD iskeleti)
/reports                        ← grafikler (randevu hacmi, iptal oranı, doluluk)
```

Tüm sayfalar tek bir responsive shell (sidebar + topbar, Bootstrap 5 `offcanvas` ile mobilde
hamburger menüye düşer) üzerinde render edilir; mobilde takvim görünümü otomatik olarak
liste görünümüne düşer (§4).

## 2. Kimlik Doğrulama ve Tenant Bağlamı

- Login, 03'teki panel JWT akışını kullanır (`POST /auth/login` → JWT, `tenant_id` token
  içine gömülü, istemciden asla parametre kabul edilmez — 03 ile birebir).
- Panel hiçbir istekte `tenant_id` göndermez; tüm sayfa verisi JWT'den middleware'in
  enjekte ettiği tenant bağlamına göre gelir.
- Oturum süresi dolarsa (401) panel sessizce `/login`'e yönlendirir, mevcut form verisi
  `localStorage`'da geçici tutulur (kayıp önleme, yalnızca UX — güvenlik kararı değil).

## 3. Dashboard

Kartlar (03'teki mevcut endpoint'lerden türetilir, yeni endpoint gerekmez):

| Kart | Kaynak |
|---|---|
| Bugünkü randevu sayısı | `GET /appointments?date=today` |
| Bekleyen onay sayısı | `GET /appointments?status=pending` |
| Bu haftaki doluluk oranı | `GET /appointments?range=week` + `working_hours` üzerinden panelde hesaplanır |
| Son 24 saat mesaj hacmi | `GET /messages/log?range=24h` |

Günlük takvim şeridi (saat bazlı, personel kolonlu) dashboard'da özet olarak gösterilir,
tam takvim `/appointments` sayfasındadır (§4).

## 4. Takvim ve Randevu Yönetimi

- **Masaüstü:** FullCalendar (resourceTimeGrid, personel = resource kolonu) — 03'teki
  müsaitlik endpoint'i (`GET /availability`) slot önerisini, `GET /appointments` mevcut
  randevuları besler.
- **Mobil (`<768px`):** Aynı veri, gün bazlı dikey liste görünümüne düşer (FullCalendar
  `listDay` view) — takvim grid'i küçük ekranda okunaksız olduğu için otomatik geçiş.
- Randevu durumu değişimi (`confirmed→completed`, manuel `cancelled`) panelden
  `PATCH /appointments/{id}` ile yapılır; 03'teki durum makinesi kısıtları (yalnızca izin
  verilen geçişler) Backend'de zaten uygulanıyor, panel yalnızca izinli aksiyonları gösterir.
- Slot çakışması durumunda (`409 slot_taken`, 03/04'te tanımlı exclusion constraint
  kaynaklı) panel aynı hatayı n8n'in yaptığı gibi alternatif slot önerisiyle karşılar.

## 5. Hizmet / Personel / Çalışma Saatleri

Standart CRUD tabloları, doğrudan 02'deki tablolara 1:1 karşılık gelir:

- `/services` → `services`, `staff_services` (personel-hizmet ataması çoktan-çoğa checkbox listesi)
- `/staff` → `staff`
- `/staff/{id}/hours` → `working_hours` (haftanın 7 günü için başlangıç/bitiş), `breaks`
  (personel bazlı tekrar eden mola), `holidays` (tarih aralığı tatil — resmi tatil veya
  izin)
- Tüm formlar Backend'in 03'teki validasyon hata sözleşmesini (`422` + alan bazlı hata
  listesi) doğrudan form altına basar, panelde ayrıca iş kuralı doğrulaması yapılmaz
  (tek doğruluk kaynağı Backend).

## 6. Müşteriler

- `/customers`: liste + arama (isim/telefon), her satırda son randevu tarihi
- Detay: `customers` + o müşteriye ait `appointments` ve `message_log` geçmişi (tenant
  bazlı, RLS zaten izole ediyor)
- Panelden müşteri silme yok (KVKK/GDPR silme talebi bu fazın kapsamı dışında — 08/09'da
  ele alınabilir, not olarak düşülür)

## 7. Mesajlaşma Sayfaları (05 ile doğrudan bağlı)

- **Şablonlar (`/messages/templates`):** Salt okunur liste + `active` durumu; "Meta ile
  Senkronize Et" butonu 05 §7'de tanımlanan `POST /internal/whatsapp/templates/sync`
  endpoint'ini tetikler. Panelden yeni şablon **oluşturulamaz** (05'te karar verildi —
  şablonlar Meta Business Manager'da onaylanır).
- **Kampanyalar (`/messages/campaigns`):** `campaigns` tablosuna yazan form — hedef filtre
  (`target_filter` jsonb, ör. "son ziyaretten X gün önce") + şablon seçimi (yalnızca
  `template_type='campaign'` ve `active=true` olanlar seçilebilir).
- **Mesaj Logu (`/messages/log`):** `message_log` tablosunun filtrelenebilir görünümü
  (yön, durum, tarih aralığı); 05 §5'teki Meta hata kodları burada `failed` satırlarında
  tooltip olarak gösterilir (bu, 05 madde 7'de önerilen `meta_error_code` kolonuna bağımlı
  — o migration yapılana kadar ham `content` jsonb'den okunur).

## 8. Ayarlar

- **`/settings/business`:** `tenants` tablosundaki işletme adı, logo (dosya upload →
  Backend'de statik dosya olarak saklanır, CDN kapsam dışı), adres, konum (lat/lng, harita
  seçici)
- **`/settings/whatsapp`:** `whatsapp_status` göstergesi (`connected/disconnected/pending`
  — 05 madde 7'de tanımlanan kolon) + "Yeniden Bağlan" butonu (05 §1 Embedded Signup akışını
  yeniden tetikler, `190` token hatası sonrası gerekir)
- **`/settings/reminders`:** `reminder_hours_before`, `pending_ttl_minutes` (04 madde 7'de
  tanımlanan, henüz eklenmemiş `tenants` kolonları — bu sayfa o migration'a bağımlı)
- **`/settings/ai`:** `ai_settings` tablosuna CRUD iskeleti (alan bazında içerik 07'de
  tanımlanacak, bu fazda yalnızca sayfa/form iskeleti var — 07'yi beklemeden boş bırakılmaz,
  aksi halde tenant AI'ı hiç yapılandıramaz)

## 9. Raporlar / Grafikler

Chart.js ile üç temel grafik (yeni endpoint gerekmez, mevcut liste endpoint'leri panelde
agregre edilir):

- Günlük/haftalık randevu hacmi (çizgi grafik)
- İptal/no-show oranı (`status='cancelled'` oranı, pasta grafik)
- Personel bazlı doluluk oranı (bar grafik, `working_hours` toplam kapasitesine oranla)

Büyük veri setlerinde (>1000 randevu/ay) agregasyon panelde değil Backend'de yapılmalıdır;
bu fazda hacim düşük varsayılıp panel tarafı agregasyon kabul edilir, ölçek sorunu
çıkarsa 09'da (SaaS Deployment) ele alınır.

## 10. Bu Fazda Tespit Edilen Backend Gereksinimleri (henüz eklenmemiş)

- Logo/medya dosyaları için statik dosya yükleme endpoint'i (`POST /settings/logo`) — 03'te
  tanımlı değildi, panel dosya upload'ı gerektirdiği için eklenmeli
- Müşteri silme/anonimleştirme (KVKK) endpoint'i — kapsam dışına not düşüldü, 08/09'da
  değerlendirilecek
- `/settings/ai` sayfasının gerçek alan listesi 07_AI_Module.md tamamlanınca netleşecek

Sonraki faz: **Faz 7 — 07_AI_Module.md** (doğal dil asistanı, işletmeye özel bilgi tabanı)
