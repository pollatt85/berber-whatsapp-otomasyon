# 00 — Master Roadmap

## Proje Özeti

**Berber WhatsApp Otomasyon** — çoklu işletme (multi-tenant SaaS) randevu asistanı.
Her berber kendi WhatsApp Business numarasını sisteme bağlar; müşteri kendi kişisel
WhatsApp'ından berberin Business numarasına yazarak randevu alır. Berber tüm işletme
verisini (hizmet, fiyat, personel, çalışma saatleri, mesaj şablonları) admin panelden
yönetir — kodda sabit işletme verisi yoktur.

**Mimari zinciri:** WhatsApp → Meta Cloud API → n8n → Backend API → PostgreSQL → Admin Panel

## Faz Listesi

| Faz | Doküman | Kapsam | Durum |
|-----|---------|--------|-------|
| 1 | 01_System_Architecture.md | Mimari, teknoloji seçimi, multi-tenant tasarım | ✅ TAMAMLANDI |
| 2 | 02_Database_Design.md | ER diyagramı, tablolar, tenant izolasyonu | ✅ TAMAMLANDI |
| 3 | 03_Backend_API.md | API tasarımı, yetkilendirme, takvim algoritması | ✅ TAMAMLANDI |
| 4 | 04_n8n_Workflows.md | Otomasyon akışları, hatırlatma, onay/iptal | ✅ TAMAMLANDI |
| 5 | 05_WhatsApp_Integration.md | Cloud API, webhook, şablon mesajlar, menüler | ✅ TAMAMLANDI |
| 6 | 06_Admin_Panel.md | Responsive panel, dashboard, takvim, ayarlar | ✅ TAMAMLANDI |
| 7 | 07_AI_Module.md | Doğal dil asistanı, işletmeye özel bilgi tabanı | ✅ TAMAMLANDI |
| 8 | 08_Test_Pilot.md | Pilot işletmeler, test senaryoları | ✅ TAMAMLANDI |
| 9 | 09_SaaS_Deployment.md | Yayın, ölçekleme, lisanslama, abonelik | ✅ TAMAMLANDI |

## İlerleme

**9 / 9 faz tamamlandı — %100 (dokümantasyon fazı bitti, kodlama fazına geçilebilir)**

## Netleşmiş Kararlar (değiştirilemez varsayılanlar)

- Multi-tenant SaaS; tenant = berber işletmesi, her tenant kendi WhatsApp Business numarası
- WhatsApp entegrasyonu: **Meta Cloud API (resmi)**
- Webhook'ta `phone_number_id` → tenant eşleştirme; tüm tablolarda `tenant_id` ile satır bazlı izolasyon
- Veritabanı: **PostgreSQL** (gerekçe: 01_System_Architecture.md)
- İşletme verisi asla kodda sabitlenmez; her şey panelden yönetilir
