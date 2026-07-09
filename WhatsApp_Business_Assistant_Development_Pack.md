# WhatsApp Business Assistant SaaS - Development Pack

## Amaç

Bu proje uzun soluklu geliştirilecektir. Claude her oturumda kaldığı
yerden devam edecek, tekrar üretmeyecek.

## Çalışma Kuralları

-   Her aşama sonunda durum kaydedilecek.
-   CHANGELOG güncellenecek.
-   Bir sonraki oturum için hazır komut üretilecek.
-   Tamamlanan aşamalar tekrar yazılmayacak.
-   Token tasarrufu önceliklidir.
-   Önce mevcut dosyaları okuyup sonra geliştirmeye devam et.

## Doküman Yapısı

00_Master_Roadmap.md - Projenin genel özeti - Fazların listesi -
İlerleme yüzdesi

01_System_Architecture.md - Genel mimari - Teknoloji seçimi - SaaS
yapısı - Çoklu işletme desteği

02_Database_Design.md - ER diyagramı - Tablolar - İlişkiler - Tenant
(işletme) izolasyonu

03_Backend_API.md - API tasarımı - Yetkilendirme - İş kuralları - Takvim
algoritması

04_n8n_Workflows.md - Tüm otomasyon akışları - WhatsApp
tetikleyicileri - Hatırlatmalar - Onay/İptal süreçleri

05_WhatsApp_Integration.md - Cloud API - Webhook - Şablon mesajlar -
Menü yapıları

06_Admin_Panel.md - Responsive tasarım - Mobil uyum - Dashboard -
Tablolar - Grafikler - Takvim - Ayarlar

07_AI_Module.md - Doğal dil - AI asistan - Bilgi tabanı - İşletmeye özel
cevaplar

08_Test_Pilot.md - Pilot işletmeler - Test senaryoları - Hata kayıtları

09_SaaS_Deployment.md - Yayın - Ölçekleme - Lisanslama - Abonelik


Project/
│
├── 00_Master_Roadmap.md
├── 01_System_Architecture.md
├── 02_Database_Design.md
├── 03_Backend_API.md
├── 04_n8n_Workflows.md
├── 05_WhatsApp_Integration.md
├── 06_Admin_Panel.md
├── 07_AI_Module.md
├── 08_Test_Pilot.md
├── 09_SaaS_Deployment.md
│
├── PROJECT_MEMORY.md      ← Proje hafızası
├── CHANGELOG.md           ← Sürüm geçmişi
├── BACKLOG.md             ← Yapılacaklar
└── README.md              ← Genel açıklama

## Yönetim Paneli Kuralı

Hiçbir işletme bilgisi kodda sabit olmayacak. Panelden
değiştirilebilir: - Hizmetler - Süre - Fiyat - Personeller - Çalışma
saatleri - Mola - Tatil - Otomatik mesajlar - İşletme bilgileri - Logo -
Adres - Konum - Kampanyalar - AI ayarları

## Mimari İlkesi

WhatsApp -\> Cloud API -\> n8n -\> Backend API -\> PostgreSQL -\> Admin
Panel

n8n = otomasyon Backend = iş kuralları Veritabanı = veri Panel = yönetim

## Her Faz Sonunda

STATUS: PHASE_X_COMPLETE CHANGELOG güncelle. Bir sonraki oturum için şu
formatta komut üret:

"Önce Development Pack ve önceki fazı oku. Tekrar etme. Kaldığın yerden
Phase X+1'e devam et. Tamamlanan maddeleri yeniden üretme. Token
tasarrufu yap."

Önerilen model: - Mimari: Claude Opus - Kodlama: Claude Sonnet

Bu doküman yaşayan bir dokümandır; yeni fikirler mevcut mimariye uygun
şekilde eklenir, mevcut kararlar korunur.
