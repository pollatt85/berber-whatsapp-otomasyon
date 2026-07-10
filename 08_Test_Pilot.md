# 08 — Test Pilot

Bu doküman, 01-07'de tasarlanan sistemin gerçek berberlerle sınırlı bir pilotta nasıl
doğrulanacağını tanımlar. Mimari/API/akış detayları tekrar edilmez; burada yalnızca pilot
seçimi, test senaryo matrisi, hata kayıt süreci ve çıkış kriterleri var.

## 1. Pilot Seçim Kriterleri

- 2-3 berber işletmesi, farklı profil: (a) tek personelli küçük dükkan, (b) 3-5 personelli
  orta ölçek, (c) yoğun randevu trafiği olan işletme (takvim çakışma/exclusion constraint'i
  gerçek yükte test etmek için — 02'deki `EXCLUDE USING gist` kısıtı)
- Her pilot işletmenin kendi WABA'sı olmalı (05'teki Embedded Signup gerçek Meta onayına
  tabi, sandbox yeterli değil — şablon onay süreci gerçek olmalı)
- Pilot süresi: 2-3 hafta, günlük gerçek randevu trafiği ile

## 2. Onboarding Kontrol Listesi (Pilot Başlangıcı)

Sırayla, önceki fazlardaki adımların uçtan uca çalıştığının doğrulanması:

1. Tenant kaydı + panel `owner` kullanıcı oluşturma (03 §2.2)
2. WABA bağlama (05 §1) → `whatsapp_status='connected'` olana kadar
3. Panelden hizmet/personel/çalışma saati/mola/tatil girişi (06 §5) — **hiçbir veri kodda
   sabit olmamalı** kuralının fiilen doğrulanması (Development Pack madde: Yönetim Paneli Kuralı)
4. Şablonların Meta'da onaylanması + panelden senkronizasyon (05 §6, 06 §7)
5. `reminder_hours_before` / `pending_ttl_minutes` ayarlarının girilmesi (04 madde 7, 06 §8)
6. Test müşterisiyle uçtan uca bir randevu döngüsü (mesaj → menü → onay → hatırlatma)

## 3. Test Senaryo Matrisi

### 3.1 Randevu Akışı (04, 05)

| # | Senaryo | Beklenen |
|---|---|---|
| 1 | Müşteri "randevu almak istiyorum" yazar | Hizmet listesi (interactive list) gelir |
| 2 | Hizmet + personel + slot seçimi, slot boş | Onay isteği (reply button) |
| 3 | İki müşteri aynı slotu aynı anda seçer | İkincisi `409 slot_taken`, alternatif slot önerisi |
| 4 | Müşteri onaylar | `confirmed`, panelde görünür, `message_log` kaydı |
| 5 | Müşteri son anda "iptal" yazar | `cancelled`, slot yeniden açılır (exclusion constraint testi) |
| 6 | `pending` randevu TTL süresi dolar | Otomatik iptal (04 cron), müşteriye bilgi mesajı |
| 7 | Hatırlatma zamanı gelir | Şablon mesaj gönderilir (24 saat penceresi kapalıysa da) |

### 3.2 WhatsApp Entegrasyonu (05)

| # | Senaryo | Beklenen |
|---|---|---|
| 8 | Webhook imzası geçersiz | 403, `webhook_events`'e düşmez, Meta'ya 200 yine dönülür mü kontrol edilir |
| 9 | Şablon Meta'da reddedilmiş | `132001`, panelde "onaylı değil" uyarısı |
| 10 | Access token süresi dolmuş | `190`, `whatsapp_status='disconnected'`, panelde yeniden bağlan uyarısı |
| 11 | Müşteri 24 saat sonra tekrar yazar | Serbest metin yerine şablon gerekliliği doğru tetiklenir |
| 12 | Müşteri list message'dan seçim yapar | `list_reply.id` doğru state'e eşlenir |

### 3.3 Panel (06)

| # | Senaryo | Beklenen |
|---|---|---|
| 13 | Mobil ekranda takvim | Liste görünümüne otomatik düşer |
| 14 | Personel çalışma saati dışı slot | Müsaitlik listesinde görünmez |
| 15 | Tatil günü girilmiş personel | O gün tüm slotlar kapalı |
| 16 | `staff` rolüyle giriş | Yalnızca kendi randevuları görünür (03 §2.2 rol kısıtı) |

### 3.4 AI Modülü (07)

| # | Senaryo | Beklenen |
|---|---|---|
| 17 | "Saç kesimi ne kadar sürer?" | `services.duration_minutes`'tan doğru cevap |
| 18 | Var olmayan hizmet sorulur | "net bilgim yok" tarzı yanıt, uydurma yok |
| 19 | "Randevumu iptal et" (serbest metin) | AI işlem yapmaz, ilgili menüye yönlendirir |
| 20 | `ai_settings.enabled=false` | Sabit fallback şablonu, LLM çağrısı yapılmaz |
| 21 | Kapsam dışı soru (hava durumu) | Nazik yönlendirme, uydurma cevap yok |

## 4. Hata Kayıt Süreci

- Pilot süresince tüm hatalar bir `BACKLOG.md` dosyasında toplanır (Development Pack'te
  planlanan ama henüz oluşturulmamış dosya — bu fazda oluşturulur, format: tarih, senaryo
  no, gözlem, önerilen düzeltme, öncelik)
- `webhook_events` ve `message_log` tabloları zaten denetim izi tutuyor (02) — panel
  hata ayıklamasında birincil kaynak, ayrıca log sistemi kurulmaz
- Kritik hata (veri kaybı, yanlış ücretlendirme, çakışan randevu) aynı gün, düşük öncelik
  haftalık toplu değerlendirilir

## 5. Çıkış Kriterleri (Pilot → Genel Kullanım)

- §3'teki 21 senaryonun tamamı pilotta en az bir kez gerçek trafikte gözlemlenmiş olmalı
  (simülasyon değil — özellikle #3, #6, #9, #10 gerçek Meta davranışına bağlı)
- Kritik öncelikli `BACKLOG.md` maddesi kalmamalı
- En az bir pilot işletmede 2 hafta kesintisiz `whatsapp_status='connected'` (token
  yenileme/kopma sorunu yaşanmamış)

## 6. Bu Fazda Tespit Edilen Gereksinimler

- `BACKLOG.md` dosyası oluşturulmalı (Development Pack'te planlı, önceki fazlarda
  oluşturulmadı) — pilot başlamadan önce boş şablon olarak eklenmeli
- Pilot geri bildirimi 06'daki rapor sayfalarının gerçek veriyle okunabilirliğini de test
  eder; Chart.js agregasyonunun düşük hacimde yeterli olduğu varsayımı (06 §9) burada
  doğrulanacak

Sonraki faz: **Faz 9 — 09_SaaS_Deployment.md** (yayın, ölçekleme, lisanslama, abonelik)
