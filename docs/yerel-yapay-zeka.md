# PKTakip — Yerel yapay zeka ile rapor okuma (çok ajanlı akış)

Dal: `yerel-yapay-zeka`. Amaç: belediye kurulumunda (internetsiz) periyodik kontrol raporlarını yerel modelle okumak.
Sonra aynı akış internet sürümüne (OpenAI, küçük model) taşınacak — bkz. "İnternet sürümü".

## 1. Sınır: bu bir geçiş, yeni tasarım değil

Eski sistem (gpt-luna / OpenAI, tek uzun talimat) çalışıyor ve doğru. Talimatlar, JSON sözleşmesi, tablo okuyucu
(`PkReportTableReader`, Camelot) ve her tesisatın özel kuralları aynen kalır. Yalnız üç şey değişti:

1. **Model yerel**: NuExtract3 (laptop) / gpt-oss-20b (deneme).
2. **PDF'i Docling ayırır**: başlıklar, bölümler, tablo konumları. Düz metin yalnız tekli ızgara formlarında (pdfium).
3. **Uzun talimat küçük ajanlara bölünür**: her ajan tek iş yapar ve yalnız kendi bölümünü görür. Kuralları eski talimatın
   ilgili bölümünden alır.

Kurallar:
- Rapor metninden regex / string ile bilgi ayıklanmaz; yapay zeka bulur.
- Ajan tablo satırı okumaz. Hücreleri Camelot okur, ajan yalnız tablonun türünü ve etiketlerinin anlamını söyler.
- Bir ajana birden çok sistem okutulmaz.
- Online sağlayıcıların (OpenAI / Gemini / NVIDIA) davranışı değişmedi. İstemcilere yalnız isteğe bağlı `$schema`
  parametresi eklendi.

## 2. Rapor kuralları (her türde geçerli)

- **Kriter ≠ bulgu.** Kriter (kontrol maddesi, ölçüm satırı) yalnız uygunluğu belirler:
  - bir kriter uygunsuz ise ekipman / sistem uygunsuz olur;
  - bir sistem uygunsuz ise rapor uygunsuz olur.
- **Bulgu:** raporun kusur / uygunsuzluk bölümündeki açıklamalardır.
- **İstisna, asansör:** uygunsuz kriter maddeleri bulgudur ve K / S / M renk sınıfıyla yazılır. Cinsi okunamazsa
  `asansor-cinsi-okunamadi`, müşteri = bina.
- **Ekipman durumu:** kriter sonucu yoksa ve hiçbir bulgu o ekipmanı anmıyorsa "bilinmiyor".
- **Tablo türleri** beştir: bilgi, ekipman, kriter, ölçüm, diğer.
- **Elektrik ailesi:** ekipman eklenmez, ölçüm tabloları okunur, "Tesis Özellikleri" kartı doldurulur. Genel kontrol
  listesi "Tesisat Geneli" sistemine yazılır.
- **Yangın:** "Proje Bilgileri" genel bilgi kartıdır. "Sabit Boru Tesisatı" gerçek bir sistemdir.

## 3. Akışlar

### Ortak başlangıç

1. `PkReportText::read` her raporun metnini hazırlar:
   - `text_pages`: pdfium düz metni;
   - Docling sayfaları;
   - `docling_tables`: tablo no, sayfa, alan, başlık, ilk satır.
2. `scripts/pk_docling.py` Docling çıktısını üretir. Sonuç `pk-docling-cache/{sha}.json` dosyasına önbelleklenir.
   - Biçim: `## ` başlık, `### Tablo N` işareti, `| ` satır.
3. `PkReportClassifier::classify` türü ve tekli / liste ayrımını bulur.
   - Gaz tüpü seçilirse tek soruluk bir doğrulama sorulur: söndürücü mü, gaz silindiri mi?
4. `PkShortPromptRouter` türe göre ilgili akışa yönlendirir.

### Tekli ekipman (`PkSingleEquipmentReader::readPagesAgents`)

Rapor 1 sistem ve 1 ekipman içerir; girdi pdfium metnidir. Docling bu formda bütün sayfayı tek tablo yapıyor.

| Ajan | İşi |
|---|---|
| `PkHeaderPass` | Rapor no, tarih, firma, sonuç (asansörde asansör kuralları eklenir) |
| `PkSingleEquipmentAgent` | Kimlik, özellikler, etiket (katalog seçenekleri), teknik özellikler (katalogdaki her alan zorunlu) |
| `PkFindingsPass::kusurSection` | Kusur bölümünü aynen kopyalar; boşsa bulgu yoktur ve başka çağrı yapılmaz |
| `PkFindingsPass` | Yalnız kusur bölümünden bulgular |
| Asansör: `elevatorResult` / `elevatorFindings` / `elevatorCustomer` | Sonuç (1. sayfa), K/S/M maddeleri (no ile tekilleştirilir), müşteri |

### Toplu tüp formu (`PkBulkListPrompt`)

- Tüp satırlarını Camelot okur.
- Ajan yalnız rapor bilgilerini ve tablo başlıklarını okur.
- Rapor 3 sayfadan uzunsa ajana yalnız ilk 2 sayfa ve son sayfa gönderilir.

### Yangın tesisatı (`PkReportAnalyzer` doğrudan yol → `PkInstallationSystemsPass` → `PkFireAgents`)

1. **Tesisat türü:** `PkReportClassifier::installationType`.
2. **Bölümler:** `PkDocumentSections::texts` raporu bölümlere ayırır: sistem bölümleri (`by_system`), tesis bilgisi,
   bulgular, başlık.
3. **Rapor bilgisi:** `PkHeaderPass`. Girdi `PkHeaderPass::input` ile seçilir:
   - metin 15 bin karakteri aşmıyorsa bütün metin;
   - aşıyorsa başlık ve bulgu bölümleri.
4. **`PkFireAgents::systems`**:
   - **Tablo türü ajanı:** her tablonun ilk satırlarına bakar ve şunu söyler:
     - türü: bilgi / ekipman / kriter / ölçüm / diğer;
     - ekipman tablosunda sistemi.
   - **Kontrol listesi ajanı:** kriter tablolarının Camelot satırlarını okur. Grupları ("A Belge ve Kayıt
     Kontrolleri", …) katalog sistemine eşler ve her grubun sonucunu verir. Gruplardan biri uygunsuzsa sistem uygunsuzdur.
     Sistemler buradan çıkar; katalogdaki her sistem tek tek sorulmaz, çünkü küçük model olmayanlara "var" diyordu.
   - **Tablo haritası (`PkTableMap`):** sistem başına yalnız o sistemin ekipman tablolarına bakar. Hangi tablonun hangi
     ekipmana ait olduğunu, kimlik etiketini ve özellik etiketlerini söyler.
   - `PkTableDefinitions` yapıyı Camelot'tan kodla bulur ve sözleşmedeki `equipment_definitions` kaydını kurar
     (satırlar, sütunlar ya da tek etiket→değer).
     - Docling alanı Camelot tablosuyla IoU üzerinden eşlenir.
     - Satırları mevcut `PkReportTableReader` okur.
5. **Proje Bilgileri:** `PkElectricalAgents::facility` (yangın talimatının 2. ve 6. bölümü).
6. **Bulgular:** `PkFindingsPass` yalnız kusur bölümünü okur. Bulguyu sisteme bağlarken madde kodunun grup harfi
   kullanılır ("6.D.9" → D grubu).

Docling tabloları yoksa eski yol çalışır: sistemler adımı + tablo kontrolü.

### Elektrik ailesi (`PkElectricalReportAnalyzer` → `PkElectricalAgents::read`)

- Sistemler bölüm bölüm okunur. Her bölümün katalog sistemini `PkDocumentSections::system` ajanı seçer.
- Her sistem için:
  - karar ajanı (talimatın 3. bölümü);
  - o bölümün tablolarıyla tablo haritası (4. bölüm).
- Ardından ortak tesis bilgisi ajanı `facility` çalışır.
- Kısa talimatlar için `withoutAgentSections()` 5, 8, 9 ve 11. bölümleri çıkarır.
- Ana talimat Docling değil düz metin (`text_pages`) alır: Docling metni elektrikte gerilemeye yol açtı.

## 4. Yerel model ve istemci

- **İstemci:** `PkLocalReportClient`.
  - OpenAI uyumlu ve akışlı (SSE).
  - Tünelde 502 / 520 / 522 / 524 alınırsa bir kez yeniden dener; bu yalnız yerelde.
  - Şemalı çıktı verir. Diziler `capArrays` ile sınırlanır (metin dizisi 20, nesne dizisi 50), çünkü sınırsız
    bırakıldığında model aynı öğeyi tekrar tekrar yazıyordu.
- **Tekrar deneme:** `PkLocalRetry` yalnız yerelde, boş sonuç gelirse bir kez dener.
- **Ayarlar:** `.env` içindeki `PKTAKIP_LOCAL_*` değişkenleri (bkz. `.env.example`); adres sonunda `/v1` olmalı.
- **Laptop** (RTX 3050 Ti 4 GB, Vulkan derlemesi `C:\Users\zanba\llama`):

```
./bin/llama-server.exe -m models/NuExtract3-Q4_K_M.gguf --device Vulkan1 -ngl 99 -c 16384 -np 1 \
  --host 127.0.0.1 --port 8090 --jinja --chat-template-kwargs '{"enable_thinking":false}'
```

- `-np 1` şarttır, yoksa 16k bağlam slotlara bölünür ve çıktı kesilir.
- 32k bağlam 4 GB'a sığmıyor.

## 4a. Yerelde kullanım (laptop)

1. Masaüstündeki `Yerel YZ baslat.bat` dosyasına çift tıkla. Pencerede "server is listening" yazınca model hazırdır;
   pencere açık kaldıkça çalışır.
2. Uygulamada Ayarlar → Yapay zeka sayfasını aç:
   - Varsayılan istemci: **Yerel sunucu**;
   - Okuma yöntemi: **Çok ajanlı (Docling)**;
   - Yerel sunucu kartında adres boş bırakılırsa `.env`'deki kullanılır (`http://127.0.0.1:8090/v1`);
   - model: `nuextract3`;
   - anahtar gerekmez.
3. Raporu her zamanki gibi yükle. Süre beklentisi:
   - tekli rapor ~45-70 sn;
   - tesisat raporu 2-4 dk.
4. `.env` ayarları:
   - `PKTAKIP_DOCLING_PYTHON=C:/Users/zanba/pk-docling/Scripts/python.exe`: boşsa Docling kapalıdır ve tesisat raporları
     çok ajanlı yola girmez;
   - `PKTAKIP_LOCAL_BASE_URL`;
   - `PKTAKIP_LOCAL_MODEL`.
5. Bellek:
   - model sunucusu ~5 GB RAM tutar;
   - Docling ilk kez gördüğü PDF'te birkaç GB daha kullanır; sonucu `pk-docling-cache` klasörüne yazdığı için aynı PDF
     ikinci kez hızlı okunur;
   - aynı anda tek rapor oku.
6. Eski yola dönmek için okuma yöntemini **Tek talimat** yap, istemciyi Gemini / OpenAI seç.

## 5. Test durumu (NuExtract3, laptop, 24 Eki 2026)

| Tür | Sonuç |
|---|---|
| Tekli ekipman (83 rapor) | Tür / no / tarih 83/83, sonuç 82/83; ortalama ~60 sn. 13 kapı raporundaki fazla bulgu kusur bölümü ajanıyla çözüldü. Asansör ayrıca ele alınıyor. |
| Elektrik | #122, #125, #126, #128, #129 doğru (~60 sn). #141'de sistem eşleme düzeltildi, yeniden test bekliyor. |
| Yangın tesisatı | OKCO PK.239: bulgu 35/35, dolap 20/20. PK25 ve #18 yeni `PkFireAgents` ile yeniden test bekliyor. |

Açık sorunlar ve yeniden test listesi:
- Asansör #63
- Aktaş tüp sayısı
- Toplu form #6, #7, #10
- Tekli model alanına yan tablonun karışması: ekipman ajanına Camelot satırları verilecek.

Test yöntemi:
- Rapor rapor test edilir, paralel değil.
- Sonuçlar uzman / gpt-luna kaydıyla karşılaştırılır; uyuşmazlıkta PDF'in aslına bakılır, çünkü uzman kaydı da eksik
  olabilir.

## 6. İnternet sürümü (sonraki iş)

Karar (24 Eki 2026): belediye sürümü bittikten sonra internet sürümü de bu akışa geçecek. Ajanlar için OpenAI'nin küçük
ve ucuz modeli kullanılacak, gpt-luna'nın tek uzun çağrısı kalkacak. Beklenti: ciddi token ve kredi tasarrufu.

Ön koşullar:
- OpenAI kredisi.
- Docling için sunucu: mevcut VDS (2 çekirdek, 3 GB) yetersiz.

Yapılacaklar:
- Akışı ayar bayrağıyla aç; eski gpt-luna yolu silinmez, canlı müşteriler etkilenmesin.
- OpenAI strict şema uyumunu kontrol et.
- Aynı raporlarda doğruluğu ve maliyeti gpt-luna kayıtlarıyla karşılaştır.
- Yerel tek deneme kuralı online'a taşınmaz.
