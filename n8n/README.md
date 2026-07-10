# n8n Workflow Export'lari

Bu dizin, `04_n8n_Workflows.md`'de tasarlanan orkestrasyonun somut n8n JSON export'larini
icerir (PROJECT_MEMORY.md "Sonraki Oturum Icin Oncelik Sirasi" madde 0, PHASE_13).

## Mimari sapma: Meta artik n8n'i degil, Backend'i cagirir

04's orijinal tasarimi ("Ortak Giris") Meta'nin webhook'unu n8n'in dogrudan aldigini
varsayiyordu. Ancak PHASE_12'de bilincli bir mimari karar alindi: `GET/POST /webhook/whatsapp`
artik **Backend'in** Meta'dan dogrudan aldigi nihai uc (imza dogrulama + ham kayit +
tenant cozumu Backend'de yapiliyor, bkz. PROJECT_MEMORY.md). Bu, n8n'in her workflow'unda
Meta imza dogrulama/tenant cozme mantigini tekrarlamasini gereksiz kiliyor, ama n8n'i
tetikleyecek bir mekanizma eksikti — **bu oturumda kapatildi**:

- `App\Support\N8nNotifier` (yeni): Backend, tenant'i basariyla cozdukten sonra, ham Meta
  payload'ini `{tenant_id, phone_number_id, payload}` seklinde `N8N_INCOMING_WEBHOOK_URL`'e
  POST eder; govde `N8N_SERVICE_SECRET` ile HMAC imzalanir (yon tersine donmus
  `ServiceHmacMiddleware` aynasi). URL bos ise (n8n kurulu degilse) sessizce atlanir — Meta'ya
  giden yanit bloklanmaz, olay zaten `webhook_events`'te durur.
- `ConversationStateRepository::upsert` + `ConversationStateController::update` +
  `PATCH /conversation-state` route (yeni): 04§2'nin varsaydigi "state ilerledikce UPDATE
  edilir" yazma yolu eksikti (yalnizca `GET` vardi) — kapatildi.
- Ikisi de bu oturumda uctan uca test edildi (gecici tenant/customer ile, PHP built-in server +
  gercek Postgres; mock n8n receiver'a HMAC imzali forward dogrulandi, ardindan temizlendi).

**n8n tarafinda gereken tek adim:** `01_incoming_whatsapp_message.json`'daki `Webhook` node'unun
URL'ini Backend'in `.env`'indeki `N8N_INCOMING_WEBHOOK_URL`'e yazin.

## Dosyalar

| Dosya | 04 karsiligi | Tetikleyici |
|---|---|---|
| `01_incoming_whatsapp_message.json` | SS2 (yeni oturum) + SS3 (hizmet/personel/slot) + SS4 (onay/iptal/degistir) | Webhook (Backend'den forward) |
| `02_reminder_scan.json` | SS5 (hatirlatma) | Cron `*/15 * * * *` |
| `03_pending_ttl_scan.json` | SS6 (pending TTL iptali) | Cron `*/5 * * * *` |
| `04_campaign_send.json` | 06_Admin_Panel.md §7 / BACKLOG §A m.26 (kampanya gonderimi, 04'te tasarlanmamisti) | Cron `*/5 * * * *` |

n8n'de bir webhook path'i tek workflow'a bagli olabildigi icin SS2/SS3/SS4, 04'teki gibi ayri
workflow'lar degil, tek workflow icinde bir `Switch` ile dallanan tek fiziksel akis olarak
yazildi (`Determine Route` Code node'u + `Route` Switch node'u).

## Gerekli n8n ortam degiskenleri

n8n instance'inin **Environment Variables** (veya `.env`) kismina:

```
BACKEND_BASE_URL=http://localhost/berber-whatsapp-otomasyon/public
N8N_SERVICE_SECRET=<Backend .env'deki N8N_SERVICE_SECRET ile BIREBIR AYNI deger>
NODE_FUNCTION_ALLOW_BUILTIN=crypto
```

`NODE_FUNCTION_ALLOW_BUILTIN=crypto` **zorunlu** — Code node'lar HMAC imzasi icin
`require('crypto')` kullaniyor; bu izin verilmezse tum Code node'lar hata verir.

`N8N_BLOCK_ENV_ACCESS_IN_NODE=false` **zorunlu** (PHASE_14'te kesfedildi) — bu n8n surumunde
(v2.28.6) Code node'lardan `$env` erisimi **varsayilan olarak engellenir** (JS Task Runner
guvenlik ayari). Bu `false` yapilmazsa `Verify Backend Signature`/`Determine Route` gibi
`$env.N8N_SERVICE_SECRET` okuyan tum Code node'lari "access to env vars denied" hatasiyla
coker.

## n8n v2.28.6 kurulum notlari (PHASE_14, bu makineye ozel)

Bu ortamda `npm install -g n8n` ile kurulum, n8n'in kendi bagimliligi olan
`@n8n/ai-workflow-builder` (AI destekli workflow olusturma ozelligi, bizim workflow'larimizla
ilgisiz) uzerinden gelen **bozuk bir npm bagimlilik agaci** yuzunden `n8n start` ile hicbir
komut calismiyordu (`ERR_PACKAGE_PATH_NOT_EXPORTED: @langchain/core/utils/uuid`) — global npm
kurulumu n8n'in kendi pnpm lockfile'ini kullanmadigindan, hoisted `@langchain/core` surumu
(1.1.41) bazi ic bagimliliklarin (`@langchain/langgraph-checkpoint` vb.) gerektirdigi
`^1.1.48`'i karsilamiyordu. **Duzeltme:** hoisted `@langchain/core` paketi elle `1.2.1`'e
yukseltildi (`npm pack @langchain/core@1.2.1` + ilgili `node_modules/@langchain/core`
klasorunun icerigi degistirildi). Bu, yalnizca bu makinedeki npm kurulumuna ozel bir sorun —
Docker ile kurulumda muhtemelen yasanmaz; sonraki bir kurulumda ayni hatayla karsilasilirsa
ayni yontem uygulanabilir.

**Onemli:** bu n8n surumu (2.x), klasik 1.x self-hosted mimarisinden farkli, hala olgunlasmakta
olan bir "draft/publish" workflow versiyonlama sistemi iceriyor:
- `PATCH /rest/workflows/:id` yalnizca yeni bir **draft** versiyon yazar; canli webhook'a
  yansimasi icin `POST /rest/workflows/:id/activate` ile o versionId'nin **publish+activate**
  edilmesi, ardindan **n8n surecinin yeniden baslatilmasi** gerekir (CLI'nin
  `update:workflow`/`publish:workflow` komutlari da ayni notu basar: "Changes will not take
  effect if n8n is running").
- Tutarsiz bir aktivasyon durumundan (ör. eski/deprecated `update:workflow --active=true` CLI
  komutuyla) sonra restart edilirse, webhook `webhook_entity` tablosuna **yanlis bir path**
  (`<workflowId>/webhook/<path>` gibi, basinda fazladan workflow ID) ile kaydedilebiliyor ve
  dokumante edilen `/webhook/<path>` URL'i 404 donuyor. **Duzeltme:** workflow'u calisirken
  `POST /rest/workflows/:id/activate` ile (dogru versionId'yle) temiz bir sekilde yeniden
  aktive edip tekrar restart etmek path'i duzeltiyor. Production URL normalde sadece
  `http://<n8n-host>/webhook/<path>` olmalidir (workflow ID icermez).

Bu iki sorun da bu spesifik n8n surumune/kurulum yontemine ozel; sonraki bir oturumda n8n'i
guncellerken (`npm update -g n8n` veya yeniden kurulum) tekrar kontrol edilmesi onerilir.
Daha az surpriz isteniyorsa, olgunlasmis 1.x LTS hattina (ör. `npm install -g n8n@1.123.x`)
sabitlemek de bir secenek.

## Konusma durumu (state machine)

`conversation_states.step` degerleri: `idle` -> `awaiting_service_selection` ->
`awaiting_staff_selection` -> `awaiting_slot_selection` -> `awaiting_appointment_action` ->
(`idle`). `confirm_*`/`cancel_*`/`reschedule_*` buton onekleri her adimdan tetiklenebilir
(Switch'te step'ten bagimsiz once kontrol edilir).

## Bilincli basitlestirmeler / bilinen sinirlar

1. **Reschedule = cancel + yeni appointment.** Backend'de yer degistirme yapan bir
   "in-place update" ucu yok (yalnizca `confirm`/`cancel` var). `reschedule_<id>|<service>|<staff>`
   akisi: yeni slot secilince once `POST /appointments` (yeni kayit), sonra
   `PATCH /appointments/{eski_id}/cancel`. Fonksiyonel olarak dogru ama randevu ID'si degisir.
   Gelecekte gercek bir `PATCH /appointments/{id}/reschedule` ucu eklenirse bu iki adim tek
   cagriya indirilebilir (BACKLOG'a not dusuldu).
2. **`GET /availability` gunluk** — 04§3'un "bugun..+7gun" istegi icin Backend tek `date`
   parametresi aliyor; n8n 7 gunu tek tek sorgulayip (`Prepare Availability Query...` -> tek
   `staff_id` icin 7 item) sonuclari `Aggregate & Build Slots Msg`'de birlestiriyor. "Farketmez"
   (herhangi personel) secildiginde ilk uygun personel kullanilir (context.staff_ids[0]) — tum
   personelleri ayni anda tarayan bir carpimsal (staff x gun) sorgu bilerek yapilmadi (cagri
   sayisini sisirmemek icin); ileride gerekirse `prep_avail_staff` node'u tum `staff_ids` icin
   dongu yapacak sekilde genisletilebilir.
3. **Slot secim ve randevu olusturma saat dilimi.** `start_time` = `<tarih>T<saat>:00`,
   tenant timezone offset'i eklenmez (PHP `DateTimeImmutable` sunucu varsayilan saat dilimini
   kullanir). Cogu kurulumda `Europe/Istanbul` sunucu saatiyle ayni oldugu icin pratikte sorun
   cikmaz, ama tam dogruluk icin ileride n8n'in tenant timezone'unu ayrica sorgulayip ISO
   offset eklemesi onerilir.
4. **HMAC imza dogrulama Code node'da `Options.rawBody: true` gerektirir** (`Webhook` node) —
   **PHASE_14'te gercek n8n'e karsi dogrulandi ve duzeltildi.** `rawBody: true` olsa bile
   `$json.body` HER ZAMAN parse edilmis JSON objesidir, asla ham string degildir. Ham govde
   base64 olarak binary alanina konur ve Code node icinde **`$input.item.binary.data.data`**
   ile okunmalidir (`$binary.data.data` DEGIL — `$binary` proxy'si yalnizca `mimeType` gibi
   metadata dondurur, asil base64 veriyi icermez; bu, n8n'in `$binary` global'inin bilinen bir
   davranisidir, bug degil). `Verify Backend Signature` node'u bu sekilde duzeltildi ve gercek
   bir HMAC-imzali istekle byte-byte dogrulandi (bkz. PROJECT_MEMORY.md PHASE_14).
5. **Node parametre semalari (`If`/`Switch`/`httpRequest`) — PHASE_14'te gercek bir n8n
   instance'ina (v2.28.6) karsi calistirilarak dogrulandi**, yalnizca UI'da acilarak degil,
   gercek bir webhook istegiyle uctan uca yurutulerek: `Webhook` → `Verify Backend Signature`
   (Code) → `Signature Valid?` (If, typeVersion 1) → `Extract Message` (Code) → `Has Message?`
   (If, typeVersion 1) → `Sign: Upsert Customer` → `Upsert Customer` (HTTP) →
   `Get Conversation State` → `Determine Route` (Code) → **`Route` (Switch)** →
   `Get Services` → `Build Services List Msg` → `Send Services List` (HTTP) — hepsi gercek
   Backend (PHP built-in server) + gercek Postgres'e karsi basariyla calisti. Tek hata, test
   tenant'inin hic hizmeti olmamasindan kaynaklanan bir Backend 500'u idi (veri eksikligi,
   n8n/workflow sorunu degil). `If` typeVersion 1'in eski `conditions.boolean` semasi bu n8n
   surumunde hala destekleniyor (n8n-nodes-base `If.node.js` `nodeVersions` haritasinda 1 hala
   `IfV1`'e esleniyor). 02/03 workflow'lari `If`/`Switch` icermiyor (yalnizca
   `scheduleTrigger`/`code`/`httpRequest`/`splitOut`), ikisi de aktivasyonda hatasiz yuklendi.
6. **`message_templates.internal_name = 'appointment_reminder'`** varsayimi (`02_reminder_scan.json`)
   — panelden veya `POST /internal/whatsapp/templates/sync` ile bu isimde aktif bir sablon
   tanimlanmis olmali, aksi halde `422 validation_error` doner.

## Import

n8n UI > Workflows > Import from File > her JSON'u ayri ayri yukleyin. Import sonrasi:
1. Ortam degiskenlerini ayarlayin (yukarida).
2. `Webhook` node'unun URL'ini kopyalayip Backend `.env`'inde `N8N_INCOMING_WEBHOOK_URL`'e yazin.
3. Her workflow'u once **test modda** (Execute Workflow) calistirip Code/HTTP node'larini
   tek tek acarak dogrulayin, sonra **Active** yapin.
