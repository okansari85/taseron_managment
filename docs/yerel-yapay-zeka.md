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
| Asansör: `ELEVATOR_HAS_ITEMS_PROMPT` | Her sayfa için önce "bu sayfada numaralı madde var mı?"; yalnız "evet" denen sayfadan madde istenir. Maddesiz kapak sayfasında küçük model bir cümleyi 1'den 50'ye numaralandırıyordu (#63). Cevap gelmezse sayfa okunur. |

### Toplu tüp formu (`PkBulkListPrompt`)

- Tüp satırlarını Camelot okur.
- Ajan yalnız rapor bilgilerini ve tablo yapısını okur; tablo başlıkları ayrı ajanla, harfi harfine (`withHeaders`).
- Toplu form olduğuna yönlendirici karar verdiği için `report_category = "ysc"` ve tek sistem (tüp listesi) koddan yazılır.
  Küçük model "yangin_tesisati" yazıp sistem uyduruyordu, tablo hiç okunmuyordu (#10).
- Rapor 3 sayfadan uzunsa ajana ilk 2 sayfa ve son sayfanın son 40 satırı gider (#6 bağlam penceresi).
  Tablo sayfası tam kalır: soru başlıkları satırlara bölündüğü için sayfanın başı yalnız başlık olabiliyor (#7).
- Girdi sayfa sayısından bağımsızdır (1. sayfa + tablo sayfası + son sayfanın sonu). Docling metnine geçmek (uzun tablo
  `sampleTables` ile başına iner) konuşuldu; doğruluk kazancı olmadığı, Docling süresi ve belleği ekleyeceği için yapılmadı.
  Yapılırsa tablo başlıkları ajanı pdfium'da kalmalı: Docling çok satırlı başlığı birleştiriyor, okuyucu sütunu Camelot
  başlığıyla buluyor.

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
     - **Sayfa kayması:** haritada bölünmüş listenin yalnız başlıksız devamı varsa tarif, geriye doğru aynı sütun
       sayılı ilk başlıklı liste tablosundan kurulur (#122). Kimlik etiketi tabloda varsa (boş pompa yuvası) ya da
       geride tek ekipmanlı bilgi tablosu çıkarsa devam tablosu sayılmaz (#18 pano).
     - **Kopyalar:** aynı tablolar + aynı kimlik etiketli ikinci liste atılır; tarifi kurulan listenin tablolarındaki
       tek ekipman kayıtları listenin satırlarıdır, atılır (#141: 14 tek + 2 liste = 22 kayıt).
     - **Alt sütunlar:** ekipmanın değeri alt sütunlara bölünmüşse (PK.239 pompa "P: - | U: ELEKTRİK") her ekipman
       tek ekipman tarifi olur. Lejantın işaretlerini küçük ajan anlamlandırır (P proje, U uygulama); uygulama
       işaretiyle başlayan alt sütunu ve hücreleri kod seçer. Yalnız tek bloklu tabloda.
     - **Yan yana gruplu kontrol listesi:** başlığın altında her sütun bölgesi ayrı satır verilir. İki madde tek
       satırdayken küçük model sağdakini atlıyordu (PK25: "9 İstasyon yeri ✓ | 29 Hidrostatik test U.D." → 29 kaçtı).
5. **Proje Bilgileri:** `PkElectricalAgents::facility` (yangın talimatının 2. ve 6. bölümü).
6. **Bulgular:** `PkFindingsPass` yalnız kusur bölümünü okur. Bulguyu sisteme bağlarken madde kodunun grup harfi
   kullanılır ("6.D.9" → D grubu). Eşleştirme ajanına kural: bulguda numarasıyla geçen cihaz varsa ("YD-73 NOLU DOLAPTA")
   bulgu o cihazın sistemidir (#18 bez hortum).
   - **Kusur bölümü başlıktan ibaretse** bölüm boştur, bütün metne dönülmez (#126: sonuç cümlesi kusur sanılıyordu).
   - **Bulgu → ekipman:** ekipman ajanı her bulgudan sorunlu cihaz numaralarını çıkarır (`affected_equipment`; "YD-3,YD-4",
     "YD118", "X ile Y arası dolap yok" → boş). Yerelde ekipman listesi bunlardan kurulur (`PkReportTableReader`); ortak
     okuyucunun metin araması kullanılmaz, çünkü tek haneli ve tiresiz numaraları kaçırıyor, aralığın uçlarını alıyordu.
     Canlı okuma bu alanı yazmaz, canlıda değişiklik yok. #18: 46 dolap bağlı (önce 40).

Docling tabloları yoksa eski yol çalışır: sistemler adımı + tablo kontrolü.

### Elektrik ailesi (`PkElectricalReportAnalyzer` → `PkElectricalAgents::read`)

- Sistemler bölüm bölüm okunur. Her bölümün katalog sistemini `PkDocumentSections::system` ajanı seçer.
- Her sistem için:
  - karar ajanı (talimatın 3. bölümü);
  - o bölümün tablolarıyla tablo haritası (4. bölüm).
- Ardından ortak tesis bilgisi ajanı `facility` çalışır. Etiket ve değer aynı kayıtta istenir (ayrı listelerde değerler
  hep boş geliyordu, #141); yangın proje kartı için en çok 50 alan.
- Kısa talimatlar için `withoutAgentSections()` 5, 8, 9 ve 11. bölümleri çıkarır.
- Ana talimat Docling değil düz metin (`text_pages`) alır: Docling metni elektrikte gerilemeye yol açtı.
- Havalandırma bulguları iki ayrı ajan:
  - kusur bölümü diğer türlerdeki gibi;
  - sistem tablolarının Camelot satırları (`ventilationRows`, sayfaya taşan devamı dahil) numaralı verilir, ölçüm satırı
    ajanı yalnız uygun olmayan satırın numarasını söyler. Bulgu metni satırın kendisi, sistemi satırın geldiği tablonun
    sistemi (#141: ajan metni kurarken komşu satırın cihaz numarasını yazıyor, aynı istekte kontrol listesini kusur sanıyordu).

### Bölümler: Docling yerleşim tuhaflıkları (`PkDocumentSections`, bütün tesisatlar)

- **Tek hücreli tablo başlıktır:** Docling bazı çerçeveli başlıkları ("5.2. ÖLÇÜM VERİLERİ") tek hücreli tablo veriyor.
- **Sayfa üst bilgisi zinciri koparmaz:** birden çok sayfada aynen tekrar eden ve sistem etiketi almamış başlık (kuruluş
  adı, tesisat adı) önceki sayfadaki sistemin devamını kesmez; altındaki tablo sisteme eklenir. Önceki bir sayfada
  (başlık ya da düz satır olarak) geçmiş başlık, etiketi "sistem" olsa bile tekrardır (#141: "PERİYODİK KONTROL RAPORU"
  bir çalışmada "diğer", birinde "sistem" etiketlendi). İlk kez görülen form başlığı yeni sistemdir.
- Etiketleyiciye: belge / doküman / proje kontrol listesi, katalogda karşılığı varsa sistemdir (#141 Proje Bilgileri).
- "diğer" etiketli bölümdeki tablo önceki sistemin devamıdır (önceden vardı).

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

## 5. Test durumu (NuExtract3, laptop)

İlk tur (24 Eki 2026):

| Tür | Sonuç |
|---|---|
| Tekli ekipman (83 rapor) | Tür / no / tarih 83/83, sonuç 82/83; ortalama ~60 sn. 13 kapı raporundaki fazla bulgu kusur bölümü ajanıyla çözüldü. Asansör ayrıca ele alınıyor. |
| Elektrik | #122, #125, #126, #128, #129 doğru (~60 sn). #141'de sistem eşleme düzeltildi, yeniden test bekliyor. |
| Yangın tesisatı | OKCO PK.239: bulgu 35/35, dolap 20/20. PK25 ve #18 yeni `PkFireAgents` ile yeniden test bekliyor. |

**28 Eki 2026 güncel durum** (gerileme: #18, PK25, PK.239, elektrik önceki temiz turla aynı):

| Rapor | Sonuç |
|---|---|
| #122 topraklama | Sistemler 2/2, ölçüm 35/35 (eski önbellek yüzünden uydurma "Gözle Kontroller" vardı; sayfa kayması düzeltildi) |
| #141 havalandırma | Debi sistemi ve sonucu doğru, 2 "UYGUN DEĞİL" bulgusu bulunuyor (birinde cihaz no yerine sıra no) |
| Asansör #63 | 93 madde, K11 / S10 / M72 (PDF: 94; önce 138-152) |
| PK.239 pompa | Özellikler uygulamadaki değerle (yakıt, güç, debi, basınç), PDF ile aynı |
| Toplu #6, #7, #10 | 468/468, 458/458, 27/27 tüp; uygunsuz, rapor no, tarih doğru |

**29 Eki 2026** (gerileme: elektrik #122, #125, #126, #128, #129 ve PK.239 önceki turla aynı):

| Rapor | Sonuç |
|---|---|
| #126 | Sonuç cümlesi artık kusur değil (uzmanın tek bulgusu bir kriter maddesi, kurala göre bulgu değil) |
| #141 | 4/4 sistem (Proje Bilgileri, Tesis Özellikleri dahil), bulgu 2/2 doğru sistemde ve doğru cihazla, 64-119 sn |
| #18 | Bulgu 18/18, sistem sonucu 7/7 (3 çalıştırmanın 2'sinde; bkz. açık), 46 dolap bulguya bağlı |
| PK25 | 29. madde (U.D.) artık yakalanıyor |

Açık:
- **Küçük model sınır kararlarında çalıştırmadan çalıştırmaya değişiyor:** #18 bez hortum bulgusu bir çalıştırmada yine
  Sprinkler'e gitti; PK25 "Sistemin Genel Kontrolleri" grubu bir çalıştırmada Sabit Boru (kullanıcının onayladığı), birinde
  Sprinkler. Talimat kuralı bunu güvenilir çözmüyor. Kalıcı çözüm: bulgunun sistemini cihaz numaralarından kodla bulmak
  (numaralar tek bir sistemin ekipmanında varsa o sistem); bunun için sistem sonucunun ekipman tablosu okunduktan sonra
  hesaplanması gerekir.
- Kusur ajanı bazen iki satırı tek bulgu yapıyor (#18: YD-79/83/85/86, YD-33…60 bulgusunun altında).
- Ortak okuyucu (`FireSuppressionUnifiedNormalizer`) başlıksız devam sayfasını okumuyor (#141 debi 14 yerine 5). Canlıda da
  böyle; okuyucuya dokunulmadı.

Dersler:
- Talimattaki örnekler test raporunun kendi verisi olabiliyor; küçük model örneği kopyalıyor (#141, #63). Örneği silmek
  #63'te başka sayfayı bozdu, geri alındı: örneklere dokunmadan önce test.
- Docling önbelleği `.tables.json` olmadan kalmışsa akış sessizce eski uzun talimata düşer.

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
