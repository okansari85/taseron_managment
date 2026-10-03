<?php

namespace App\Services\Ai\PkTakip;

use RuntimeException;

/**
 * Elektrik ailesi tesisat raporu okuma: her türün kendi talimatı — elektrik iç tesisatı (ZPKR02 ya da firmanın formatı),
 * topraklama tesisatı (ZPKR01 ya da firmanın formatı) ve yangın algılama ve uyarı sistemleri (ZPKR04 ya da firmanın formatı). Yangının talimatı (PkReportAnalyzer) kullanılmaz ve değiştirilmez;
 * aynı yapay zeka istemcileri ve aynı yanıt şablonu kullanılır (tablolar aynı Camelot adımıyla okunur). Rapor tek dosyada
 * birleşik (kitap gibi) ya da bölüm bölüm gelebilir; bölümler numarasından / başlığından değil anlamından tanınır. Yapay
 * zeka raporun başka bir tesisata ait olduğunu da söyler (yanlış yerden yükleme uyarısı). Tek istek; tekrar deneme yok.
 */
class PkElectricalReportAnalyzer
{
    public const GROUNDING = 'topraklama';

    public const DETECTION = 'yangin-algilama';

    public const VENTILATION = 'havalandirma-klima';

    public const LIGHTNING = 'paratoner';

    public const BATTERY = 'akumulator';

    public const TRANSFORMER = 'trafo';

    public function __construct(
        private PkGeminiReportClient $gemini,
        private PkOpenAiReportClient $openai,
        private PkNvidiaReportClient $nvidia
    ) {
    }

    /**
     * $catalog: PkInstallationReportReader::promptCatalog() biçiminde tesisatın türü ve sistemleri. $typeSlug: talimatın türü
     * (elektrik iç tesisatı / topraklama). $otherTypes: [['slug', 'name'], ...] diğer tesisat türleri (yalnızca tür kararı).
     */
    public function analyze(array $pages, array $catalog, bool $ocr = false, string $typeSlug = 'elektrik-ic-tesisati', array $otherTypes = []): array
    {
        $text = ($ocr ? $this->ocrNote() . "\n\n" : '') . $this->buildDocumentText($pages);
        if (trim($text) === '') {
            throw new RuntimeException('PDF metni boş olduğu için rapor okunamadı.');
        }

        // Seçili sağlayıcı (pktakip.ai_provider): Gemini, OpenAI ya da NVIDIA; istem aynı.
        $client = match (PkAiProvider::name()) {
            'openai' => $this->openai,
            'nvidia' => $this->nvidia,
            default => $this->gemini,
        };
        // Türün kendi talimatı: topraklama (ZPKR01), yangın algılama (ZPKR04), havalandırma ve klima (standart format yok; projeye
        // uygunluk), diğerleri elektrik iç tesisatı (ZPKR02).
        [$instruction, $contract] = match ($typeSlug) {
            self::GROUNDING => [$this->groundingPrompt(), $this->groundingContract()],
            self::DETECTION => [$this->detectionPrompt(), $this->detectionContract()],
            self::VENTILATION => [$this->ventilationPrompt(), $this->ventilationContract()],
            self::LIGHTNING => [$this->familyPrompt($this->lightningParts()), $this->familyContract($this->lightningParts())],
            self::BATTERY => [$this->familyPrompt($this->batteryParts()), $this->familyContract($this->batteryParts())],
            self::TRANSFORMER => [$this->familyPrompt($this->transformerParts()), $this->familyContract($this->transformerParts())],
            default => [$this->systemPrompt(), $this->contract()],
        };
        $prompt = $instruction
            . "\n\n" . $this->catalogInstruction($catalog)
            . "\n\n" . $this->otherTypesInstruction($otherTypes)
            . "\n\n" . $contract;

        return $client->extract($prompt, $text, 50000);
    }

    // Diğer tesisat türleri: rapor başka bir tesisata aitse equipment_type.slug bunlardan biri olur (sistemleri yazılmaz).
    private function otherTypesInstruction(array $otherTypes): string
    {
        $lines = implode("\n", array_map(fn ($type) => '- ' . $type['slug'] . ' | ' . $type['name'], $otherTypes));

        return <<<PROMPT
DİĞER TESİSAT TÜRLERİ (yalnızca raporun bu tesisata ait olmadığını belirtmek için; bunların sistemleri yazılmaz):
{$lines}
PROMPT;
    }

    private function ocrNote(): string
    {
        return <<<'PROMPT'
NOT: Bu raporun PDF'i taranmış; aşağıdaki metin OCR (optik karakter tanıma) ile çıkarıldı ve tablolar hücre hücre okundu (Camelot da aynı metni okuyacak).
- İşaret kutuları: "[X]" = işaretli, "[ ]" = işaretsiz.
- OCR kaynaklı küçük harf hataları olabilir (örn. "1" yerine "I", Türkçe karakter karışması); anlamı açıksa düzelterek yorumla, değerden emin değilsen null bırak.
- Kaşe / imza üstüne denk gelen hücreler boş ya da bozuk olabilir; boş hücreyi "uygun" ya da "uygun değil" diye tahmin etme.
PROMPT;
    }

    private function buildDocumentText(array $pages): string
    {
        $parts = [];
        foreach (array_values($pages) as $index => $page) {
            $page = trim((string) $page);
            if ($page === '') {
                continue;
            }
            $parts[] = '--- SAYFA ' . ($index + 1) . " ---\n" . $page;
        }

        return implode("\n\n", $parts);
    }

    // Katalogdaki elektrik tesisatı türü ve sistemleri (adları AYNEN yazılır).
    private function catalogInstruction(array $catalog): string
    {
        $types = implode("\n", array_map(
            fn ($type) => '- ' . $type['slug'] . ' | ' . $type['name'] . "\n" . implode("\n", array_map(
                fn ($system) => '  · ' . (is_array($system) ? $system['name'] : (string) $system),
                (array) $type['systems']
            )),
            $catalog
        ));

        return <<<PROMPT
TESİSAT KATALOĞU (slug | ad, altında sistemleri — system_name bu adlarla AYNEN yazılır):
{$types}
PROMPT;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Sen ELEKTRİK İÇ TESİSATI periyodik kontrol raporları için çalışan bir TEMPLATE DISCOVERY motorusun.

AMAÇ
PDF'nin gerçek yapısını keşfet ve iki şey üret:
1. template.fire_systems.systems[]: rapordaki HER elektrik sisteminin YAPISI (equipment_definitions - ekipman / ölçüm listelerinin rol haritası) ve GENEL UYGUNLUĞU (verdict). Şemadaki ad "fire_systems" olsa da burada elektrik tesisatının sistemleri yazılır.
2. extracted_data: rapor bilgileri, tesis bilgileri, genel sonuç, bulgular / kusurlar, sonuç sembollerinin lejantı ve kontrolü yapan kuruluş.

RAPOR NE OLABİLİR
- Elektrik iç tesisatı periyodik kontrol raporu: Bakanlığın standart formatı ("Elektrik İç Tesisatı Gözle Kontrol ve Fonksiyon Testleri Periyodik Kontrol Raporu") ya da firmanın kendi formatı. Tek dosyada birleşik olabilir; çok panolu tesislerde kitap gibi uzun olabilir (her pano için ayrı kontrol formu).
- Ya da bu tesisatın TEK bir bölümünün ayrı raporu: pano görsel kontrol raporu, termografik (termal kamera) görüntü raporu, kaçak akım rölesi (RCD) test raporu gibi. O zaman raporda yalnızca o bölümün sistemi vardır.

ANLAMINDAN OKU — NUMARAYA / BAŞLIĞA GÜVENME
Bölüm numaraları ve başlıklar firmadan firmaya değişir; bakanlık formatının numaralandırması ya da adlandırması da değişebilir. "10. bölüm", "Sonuç ve kanaat" gibi sabit bir başlık arama; bir bölümün ne olduğunu İÇERİĞİNDEN anla (pano kontrol maddeleri mi, ölçüm tablosu mu, kusur listesi mi, nihai karar cümlesi mi).

TEMEL İLKE — HANGİ DEĞER SENDEN, HANGİSİ CAMELOT'TAN GELİR:
- Rapor uzunluğuyla / ekipman SAYISIYLA BÜYÜMEYEN her şey (rapor bilgileri, tesis bilgileri, sistem sonuçları, sonuç paragrafı, kusurlar) SABİT / SINIRLIDIR - bunları SEN doğrudan okur, GERÇEK değerleriyle yazarsın.
- Ekipman SAYISIYLA büyüyebilen (5 de olabilir 300 de) her gerçek değer SANA YAZDIRILMAZ - pano listesi, kaçak akım rölesi ölçüm tablosu, termal ölçüm noktaları, potansiyel dengeleme ve zemin izolasyonu ölçüm tabloları gibi tekrarlayan listelerde sen sadece YAPIYI (hangi satır / sütun ne anlama geliyor) tarif edersin, gerçek hücre değerlerini Camelot okur. Ekipmanları tek tek üretme; HER equipment_definitions kaydı bir ekipman / ölçüm TİPİNİN yapısını tarif eder.
- İstisna: bir grupta GERÇEKTEN TEK bir örnek varsa (örn. raporda tek bir pano) instance_structure.equipment_axis="none" olur ve o TEK örneğin gerçek değerlerini (identity_value, attributes[].value, verdict) SEN yazarsın.

SABİT ALANLAR (şema gereği)
- extracted_data.report_category: "yangin_tesisati" yaz. Şemadaki seçeneklerde elektrik olmadığı için "çok sistemli tesisat raporu" anlamında kullanılır; bu raporda başka bir anlamı yoktur.
- extracted_data.extraction_mode: "structured".
- extracted_data.equipment_tag: {"label": null, "value": null, "evidence": null}; extracted_data.equipment_specs: [].
- template.template_version: "1.0".

0. RAPORUN KAPSAMI (template.template_type) — ilk karar
Raporun tesisatın tamamının raporu mu, yoksa tek bir bölümünün ayrı raporu mu olduğunu anlamından belirle ve template.template_type'a TAM OLARAK şu iki değerden birini yaz:
- "tesisat_raporu": elektrik iç tesisatının tamamı için nihai bir sonuç / kanaat veren rapor (bakanlığın standart formatı ya da firmanın "elektrik (iç) tesisatı periyodik kontrol raporu"). Genelde tesisata ait genel bilgiler ve birden fazla bölüm (pano kontrolleri, fonksiyon testleri, ölçümler) içerir; tek bölüm içerse bile sonucu tesisatın geneli içindir.
- "sistem_raporu": yalnızca TEK bir bölümün ayrı raporu — pano görsel kontrol raporu, termografik (termal kamera) görüntü raporu, kaçak akım rölesi (RCD) test raporu, potansiyel dengeleme ya da zemin izolasyonu ölçüm raporu gibi; sonucu yalnızca o bölüm içindir.
Emin olamıyorsan "tesisat_raporu" yaz.

1. TESİSAT TÜRÜ (extracted_data.equipment_type)
Katalogdan raporun konusu olan tesisatı seç: {"slug": "<katalogdaki slug>", "evidence": "<dayanak: rapor başlığı / kapsamı, PDF'de yazdığı gibi>"}.
Elektrik iç tesisatı raporu ya da onun bir bölümünün (pano, termal, kaçak akım, fonksiyon testleri, potansiyel dengeleme, zemin izolasyonu) raporu ise katalogdaki elektrik iç tesisatı slug'ını yaz.
Rapor başka bir tesisatın raporuysa (topraklama tesisatı, yıldırımdan korunma / paratoner, trafo, jeneratör, yangın tesisatı gibi) DİĞER TESİSAT TÜRLERİ listesinden o tesisatın slug'ını yaz; listede de yoksa ya da tek bir makinenin raporuysa slug: null yaz (tahmin etme).
Raporun konusu başlığından ve kapsamından anlaşılır: içinde topraklama ölçümü ya da topraklama bilgisi geçmesi raporu topraklama raporu yapmaz.

2. SİSTEMLER (template.fire_systems.systems[].system_name)
Raporun her bölümünü katalogdaki sistemlerden ANLAMCA karşılık gelen adla, katalogda yazdığı gibi AYNEN yaz. Karşılıklar (raporun kendi adı ne olursa olsun, içeriğe bak):
- Panolar: panoların gözle / görsel kontrolü — pano adı / ekipman tanımlaması, panoya giriş, sabitleme, kapak, etiket, mahfaza, iç kapak, uyarı işaretleri, kablo girişleri gibi kontrol maddeleri.
- Termal Ölçümler: termal kamera / termografik görüntü ölçümleri — ölçüm yeri / nokta, en yüksek sıcaklık, ortam sıcaklığı. Pano kontrol formunun içinde termal kamera maddeleri varsa onların sonucu da bu sisteme aittir.
- Fonksiyon Testleri ve Kaçak Akım Röleleri: aşırı akım koruması, sigorta / kesici değerleri, iletken kesitleri, aşırı gerilim koruma (DKD / SPD), kaçak akım rölesi (RCD) testleri (anma akımı, açma akımı, açma süresi) — pano ve linye bazında.
- Potansiyel Dengeleme: ana ve ek (tamamlayıcı) potansiyel dengeleme iletkenleri, süreklilik ölçümleri.
- Zemin İzolasyonu: zemin / duvar yalıtım (izolasyon) direnci ölçümleri.
Raporda bir bölümün katalogda karşılığı yoksa (örn. topraklama direnci ölçümleri) rapordaki adını yaz; katalogdaki bir sisteme zorla bağlama.
SİSTEM NE ZAMAN YAZILIR: bir sistem YALNIZCA raporda o sisteme ait AYRI bir bölüm, form ya da tablo varsa yazılır — örn. panoların tek tek kontrol edildiği pano kontrol formu / pano raporu, kaçak akım rölesi (RCD) test tablosu, termal ölçüm tablosu, potansiyel dengeleme ya da zemin izolasyonu ölçüm tablosu.
Tesisat raporunun GENEL KONTROL LİSTESİ (branşman, enerji odası, kablo şaftı, sayaç ve dağıtım tabloları, aydınlatma, anahtarlar, prizler, buatlar, kompanzasyon, genel, gerilim kontrolleri gibi bölümler ve "hata akımı koruma röleleri uygun mu", "pano termografik değerleri uygun mu", "potansiyel dengeleme barası normal mi" gibi tek tek maddeler) tesisatın KENDİ kontrolleridir: bu maddelerden sistem çıkarma, maddeyi bir sisteme bağlama. Maddede bir sistemin adının geçmesi o sistemin kontrol edildiği anlamına gelmez.
Genel kontrol listesinin tamamı için TEK bir kayıt yaz: system_name "Tesisat Geneli", verdict listenin sonucu, equipment_definitions []. Bu kayıt sistem değildir (katalogda yoktur, sisteme çevrilmez); şema en az bir kayıt istediği için vardır. Raporda ayrı sistem bölümü hiç yoksa fire_systems.systems'ta yalnızca bu kayıt olur. Genel listedeki uygun olmayan maddeler sistemsiz bulgu olur (system_name null).
Tesisatın GENELİNE ait kontrol maddeleri (proje var mı, tek hat şeması, önceki kontrol etiketi gibi) bir sistem değildir: onlar için sistem kaydı açma; uygunsuz olanlar sistemsiz bulgu olur (bölüm 8).
Raporun TESİS ÖZELLİKLERİ / tesis bilgileri bölümü (toprak durumu, hava durumu, yapı cinsi, topraklama sistem tipi, kurulu güç, ana şalter giriş gerilimleri gibi) bir sistem değildir: sistem kaydı açma, alanlarını bölüm 6'ya göre facility_information'a yaz. Bu alanlar bir panonun ya da ölçümün bilgisi de değildir; attributes'a YAZMA.
Aynı sistem birden fazla pano formunda / sayfada tekrar ediyorsa TEK sistem kaydı yaz (her pano için ayrı sistem açma); devam patternlerini section_detection'da belirt.
Her sistem kaydı: system_name, section_heading_patterns (o bölümü tanıyan gerçek başlık metinleri), section_detection (başlangıç, devam ve bitiş patternleri), verdict (bölüm 3), equipment_definitions (bölüm 4).

3. SİSTEM UYGUNLUĞU (verdict) — GERÇEK DEĞER, PATTERN DEĞİL
verdict: {"status": "uygun" | "uygun_degil" | "uygulanamiyor" | null, "raw": "<raporda GERÇEKTEN yazan sonuç ifadesi, yoksa null>", "basis": "explicit" | "derived" | "not_stated", "evidence": "<kararın dayanağı olan kısa alıntı, yoksa null>"}
- basis="explicit": raporda o sistem / bölüm için AÇIK bir sonuç yazıyorsa (örn. bölüm sonunda "UYGUN DEĞİL", "ölçüm sonuçları uygundur"). raw o ifadeyi aynen taşır.
- basis="derived": açık bir bölüm sonucu yoksa, o bölümün kontrol maddelerinden ya da ölçüm sonuçlarından EN AZ BİRİ "uygun değil" ise ya da o sisteme ait bir kusur / bulgu varsa status="uygun_degil"; değerlendirilen tüm maddeler / ölçümler uygun ve o sisteme ait kusur yoksa status="uygun". Kontrol maddelerini ÇIKTIYA YAZMA - sadece bu kararı vermek için oku. evidence'a kararı belirleyen maddeyi / ölçümü / kusuru kısaca yaz.
- basis="not_stated": ne açık bir sonuç ne de değerlendirilebilir bir madde varsa status: null, raw: null.
status SADECE bu 3 değerden biri ya da null olabilir.

4. EKİPMANLAR VE ÖLÇÜM LİSTELERİ (equipment_definitions)
Panolar, kaçak akım röleleri / linyeler, termal ölçüm noktaları ve potansiyel dengeleme / zemin izolasyonu ölçüm noktaları raporda okunur ve ait oldukları sistemin equipment_definitions listesine yazılır. Her equipment_definitions kaydı bir ekipman / ölçüm TİPİNİN yapısını tarif eder (örn. "Pano", "Kaçak Akım Rölesi", "Termal Ölçüm Noktası", "Ölçüm Noktası"); o tipten kaç örnek olursa olsun TEK kayıt.
- equipment_name: tipin adı (örn. "Pano", "Kaçak Akım Rölesi", "Termal Ölçüm Noktası", "Ölçüm Noktası").
- Tekrarlayan liste / tablo (birden fazla pano, röle, ölçüm noktası): equipment_axis "rows" ya da "columns"; gerçek hücreleri Camelot okur, identity_value ve attributes[].value null kalır, verdict {"status": null, "raw": null, "basis": "not_stated", "evidence": null}.
- Her pano için ayrı bir kontrol formu tekrarlanıyorsa (her formun başında pano adı / ekipman tanımlaması, altında kontrol maddeleri): bu da tekrarlayan yapıdır; header_patterns'a formun başındaki gerçek etiketi (örn. pano adı etiketi) yaz, identity_field o etikettir.
- Raporda gerçekten TEK bir pano / tek ölçüm noktası varsa: equipment_axis "none"; identity_value o panonun gerçek adı / no'su, attributes gerçek değerleriyle (konum, pano tipi gibi), verdict o panonun sonucu.
- Bütün değerleri boş ya da "-" olan bloğu (raporda olmayan ekipman) YAZMA.
- Ekipman / ölçüm listesi olmayan sistemde equipment_definitions: [].
- Tablo raporda nasılsa öyle gösterilecek: tablonun BÜTÜN sütunlarını PDF'deki soldan sağa sırasıyla attributes'a yaz (kimlik sütunu ve satırın sonucunu veren Sonuç sütunu hariç). Bir sütunun bütün değerleri "-" ya da boş olsa bile o sütunu yaz (örn. "x0.5/1s", "x1", "x5" gibi test sütunları).
- Tabloda sıra no sütunu varsa identity_field odur; ölçüm noktası / ölçüm yeri / pano adı sütunu attributes'a yazılır.

4a. instance_structure — TEKRAR YAPISI
- identity_field: her örneği ayırt eden satır / sütun etiketi (örn. "Pano Adı", "No", "Ölçüm Yeri", "Linye"). ZORUNLU çapa - parser gerçek tabloyu bu etiketi Camelot verisinde arayarak bulacak.
- identity_value: SADECE equipment_axis="none" iken o TEK örneğin gerçek adı / no'su; aksi halde null.
- identity_hint: {"pattern": null, "validation": "soft"} - gördüğün kodların ortak bir şekli varsa pattern'e yazabilirsin; yalnızca ipucudur, hiçbir gerçek değeri elemez.
- equipment_axis:
  - "rows": her SATIR bir örnek (örn. "No | Ölçüm Yeri | Nokta Adı | Max. Ölçüm | Ortam Sıcaklığı | Sonuç" tablosunda her satır bir ölçüm noktası; ya da her satır bir linye / röle).
  - "columns": her SÜTUN bir örnek (örnekler yan yana, her özellik / kriter kendi satırında; aynı rapor 10'lu / 20'li bloklar halinde tekrarlayabilir). Ayırt etmenin yolu: örnek kodları bir SATIRIN içinde yan yana mı (→ columns) yoksa bir SÜTUNUN içinde alt alta mı (→ rows)?
  - "none": tek örnek, tekrar yok.
- group_width: SADECE "columns" iken bir bloktaki örnek sütunu sayısı; diğerlerinde null.
- header_patterns: yeni bir örnek bloğunun / tablonun başladığını gösteren gerçek etiket metinleri.
- result_columns: yalnızca iki özel durumda doldur, yoksa []:
  - Tek bir sonucu 2-3 sütuna bölen işaret seti (örn. "U | U.D. | N.U." ya da "Uygun | Uygun Değil"): her birini {"header_pattern": "<gerçek etiket>", "kind": "fixed_value", "value": "uygun" | "uygun_degil" | "uygulanamiyor"} olarak ekle.
  - Sonuç YAZMAYAN serbest metin not / açıklama sütunu: {"header_pattern": "<gerçek etiket>", "kind": "note", "value": null}.
  Satırın sonucunu "Uygun / Uygun Değil" diye yazan TEK "Sonuç" sütunu result_columns'a YAZILMAZ (note da değildir) ve attributes'a da eklenmez: parser satırın sonucunu o sütundan kendisi okur.
  Ölçüm değeri sütunları (akım, süre, direnç, sıcaklık) result_columns DEĞİLDİR; onlar attributes'tır.
- ambiguous: tablonun eksenini, kimlik alanını ya da sınırlarını GÜVENLE belirleyemediysen true ve ambiguous_reason'a nedenini yaz - dürüst bir "emin değilim" yanlış bir tahminden daha değerlidir.

4b. attributes — ÖLÇÜM DEĞERLERİ VE SABİT ÖZELLİKLER (kontrol maddesi DEĞİL)
Her özellik / ölçüm için {"field": "<adı>", "source_pattern": "<PDF'de GERÇEKTEN gördüğün etiket metni>", "value": null} yaz (value yalnızca "none" iken gerçek değerle dolar). Örnekler: konum, pano tipi, anma akımı (mA), açma akımı (mA), açma süresi (ms), sigorta / kesici değeri, kesit, en yüksek sıcaklık (°C), ortam sıcaklığı (°C), süreklilik (Ω), yalıtım direnci (kΩ / MΩ). Birimi etiketteki gibi koru.
Tablo sütunlarında field, PDF'deki sütun başlığının AYNISIDIR (kullanıcıya bu başlıkla gösterilir; kendi adını verme, kısaltma ya da çevirme): örn. "Kaçak Akım Rölesi Değeri", "Açma Zamanı (ms)", "Rcd Tipi", "x0.5/1s". Başlık PDF metninde birden fazla satıra bölünmüşse tek satırda birleştir.
Numaralı soru / kontrol maddesi gibi görünen satırları attributes'a EKLEME.

5. RAPOR BİLGİLERİ
template.report_information.fields içine şu key'leri ve PDF'deki gerçek etiketlerini (label_patterns) yaz; extracted_data.report_information içine aynı key'lerle PDF'den okunan GERÇEK değerleri yaz (yoksa value: null, key'i atlama):
- report_no: rapor numarası.
- company_title: raporu isteyen firma (müşteri) unvanı.
- address: periyodik kontrol adresi.
- report_date: rapor tarihi.
- control_date: periyodik kontrolün yapıldığı tarih (kontrol / muayene tarihi, başlangıç tarihi); rapor tarihiyle aynı alan değildir.
- validity_date: bir sonraki periyodik kontrol tarihi (geçerlilik).
- notlar: raporun NOTLAR bölümünün metni, PDF'de yazdığı gibi (birden fazla not varsa her biri ayrı satırda). Bölüm yoksa ya da içinde yalnızca "-" yazıyorsa value: null.
- bina: rapor tesisin tamamı için değil de belirli bir bina / blok / bölüm için düzenlendiyse onun adı, PDF'de yazdığı gibi (örn. "Ekipmanın Bulunduğu Yer: Tesellüm Depo" → "Tesellüm Depo", "A Blok"). Rapor tesisin geneli içinse ya da böyle bir bilgi yoksa null. Müşteri firma adı, adres ya da tek bir panonun adı bina değildir.

6. TESİS BİLGİLERİ (template.facility_or_project_information, extracted_data.facility_information)
Tesise / ekipmana ait bilgi bölümünü (bakanlık formatında "ekipman bilgileri / detay bilgiler / tespit edilen bilgiler", firmanın formatında "tesis özellikleri") bul; section_heading_patterns'a gerçek başlıklarını yaz. Bu bilgiler kullanıcıya "Tesis Özellikleri" kartında raporun kendi etiketleriyle gösterilir: her alanın label_patterns'ına önce PDF'deki etiketi AYNEN yaz (sondaki ":" hariç). Raporda GERÇEKTEN bulunan alanları şu key'lerle yaz (template'e label_patterns, extracted_data'ya gerçek değer; seçmeli alanlarda işaretli olan seçenek):
sebeke_tipi (TT / IT / TN / TN-C / TN-S / TN-C-S), sebeke_gerilimi, enerji_saglayan_kurulus, proje_var_mi, tek_hat_semasi_var_mi, kontrol_nedeni (periyodik / ilk kontrol), topraklayici_tipi, yapi_cinsi, kullanim_amaci, faz_iletkenleri, temel_topraklama_direnci, ana_kesici, ana_rcd_anma_akimi, ana_rcd_test, kapsamli_degisiklik_var_mi, asiri_gerilim_koruma (DKD / SPD), onceki_kontrol_etiketi_var_mi, son_kontrol_tarihi.
Listede olmayan önemli bir tesis bilgisi varsa kendi kısa key'iyle ekleyebilirsin. Raporda olmayan alanı YAZMA; işaretlenmemiş seçeneği seçilmiş sayma.

7. SONUÇ SEMBOLLERİNİN LEJANTI (extracted_data.result_legend)
Raporun sonuç kısaltmalarını açıklayan lejant cümlesini (örn. "U: UYGUN U.D.: UYGUN DEĞİL N.U.: NUMUNEYE UYGULANAMAZ") bul ve {"code", "meaning"} olarak GERÇEKTEN YAZDIĞI GİBİ kopyala; yoksa [].

8. GENEL SONUÇ VE KANAAT
template.overall_result içine yalnızca pattern / konum tarifini yaz. AYRICA raporun nihai kararını (hangi başlıkla geçerse geçsin; "sonuç ve kanaat", "sonuç", "değerlendirme" ya da metnin sonundaki karar cümlesi) SEN oku ve extracted_data.overall_result içine yaz:
- text: nihai karar paragrafının PDF'deki gerçek metni (bulamıyorsan null).
- status: "uygun" (örn. "kullanılmasında sakınca yoktur", "uygundur") | "uygun_degil" (örn. "uygun değildir", "kusurlar giderilmeden kullanılması uygun değildir") | "uygulanamiyor"; net değilse null.
Rapor tesisatın tek bir bölümünün raporuysa (pano, termal, kaçak akım) o raporun kendi kararını yaz.

9. KUSURLAR / BULGULAR (extracted_data.findings)
Kusur açıklamaları, tespitler ve eksiklikler. Her finding: id, system_name, description, source_pages, severity, ambiguous.
NOTLAR bölümündeki açıklamalar (sınır değerin nasıl belirlendiği, raporun kapsamı gibi) bulgu değildir; bölüm 5'teki notlar'a yazılır. Notlarda gerçek bir kusur / eksiklik yazıyorsa o kusur ayrıca bulgu olur.
- system_name: kusurun ait olduğu sistemin adı (bölüm 2'deki katalog adıyla); pano kusuru → Panolar, röle / RCD / sigorta kusuru → Fonksiyon Testleri ve Kaçak Akım Röleleri, sıcaklık / termal → Termal Ölçümler gibi. system_name yalnızca bu raporda sistem olarak yazdığın kayıtlardan biri olabilir ("Tesisat Geneli" hariç); genel kontrol listesindeki maddenin kusuru sistemsizdir (null). Tesisatın GENELİNE ait kusur (proje yok, tek hat şeması yok, etiket yok gibi) bir sisteme ait değildir: system_name null, ambiguous false. Hangi sisteme ait olduğunu GÜVENLE belirleyemediğin kusurda system_name null, ambiguous true.
- severity: rapor kusuru derecelendiriyorsa raporun kendi ifadesiyle yaz: hafif kusur "*" / ağır kusur "**" işaretliyse "Hafif kusur" / "Ağır kusur"; C1 / C2 / C3 sınıfı yazıyorsa "C1" / "C2" / "C3". Rapor derece vermiyorsa null; kendinden derece uydurma.
- description: kusurun PDF'deki metni; kusurun raporda kendi madde kodu / numarası varsa başına yaz. Kusurları gruplayan başlıklar kusur değildir. Kusur hangi panoda / linyede ise (pano adı, linye) metinde aynen koru.
- Aynı kusuru tekrarlama; U / UD hücrelerinden tek başına kusur üretme. source_pages gerçek PDF sayfalarıdır.

10. ÇIKARIM SINIRI
equipment_definitions YAPI tarifidir. Ekipman SAYISIYLA BÜYÜYEBİLEN gerçek değerler (identity_value, attributes[].value) equipment_axis="rows" / "columns" iken HER ZAMAN null kalır - Camelot okur. TEK istisna equipment_axis="none".
Senin yazdığın gerçek veri: findings + report_information + facility_information + overall_result + result_legend + inspection_body + equipment_type + systems[].verdict + ("none" olan equipment_definitions kayıtlarının gerçek değerleri).

11. PERİYODİK KONTROLÜ YAPAN KURULUŞ (extracted_data.inspection_body)
Raporu düzenleyen / periyodik kontrolü yapan kuruluşu (antet, altbilgi ya da imza / onay bölümü) GERÇEK değerleriyle yaz: name (tam unvan), address, phone, email, website, tax_info, accreditation (akreditasyon / yetki bilgisi metinde varsa) ve personnel (kontrol eden / onaylayan; role, name, profession, chamber_registry_no, diploma, authorization_no). Yalnızca etiketler olup değerler yoksa o kişiyi ekleme; hiç kişi yoksa []. Raporu İSTEYEN firma (müşteri) bu değildir. PDF'de olmayan bilgiyi uydurma; null bırak.

findings_structure: system_assignment {"required": true, "source": [], "fallback": null}; deduplication {"enabled": true, "duplicate_finding_rule": "same_finding_same_system"}; finding_fields [].
PROMPT;
    }

    private function contract(): string
    {
        return <<<'PROMPT'
ÇIKTI ŞEKLİ (örnek değerler YALNIZCA şekli anlatır; rapordaki gerçek bölümleri ve değerleri yaz):
{
  "template": {
    "template_type": "tesisat_raporu",
    "template_version": "1.0",
    "report_information": {"fields": [{"key": "report_no", "label_patterns": ["Rapor Numarası"]}, {"key": "control_date", "label_patterns": ["Periyodik Kontrol Başlangıç Tarihi"]}, {"key": "validity_date", "label_patterns": ["Bir Sonraki Periyodik Kontrol Tarihi"]}]},
    "facility_or_project_information": {"section_heading_patterns": ["EKİPMAN BİLGİLERİ"], "fields": [{"key": "sebeke_tipi", "label_patterns": ["Şebeke tipi"]}, {"key": "proje_var_mi", "label_patterns": ["Tesise ait proje var mı?"]}]},
    "fire_systems": {
      "systems": [
        {
          "system_name": "Panolar",
          "section_heading_patterns": ["PANO GÖRSEL KONTROL"],
          "section_detection": {"start_heading_patterns": ["Pano Adı"], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun_degil", "raw": null, "basis": "derived", "evidence": "Kontrol edilen tali ve ana dağıtım panoları uygun mudur?: U.D."},
          "equipment_definitions": [
            {
              "equipment_name": "Pano",
              "instance_structure": {"identity_field": "Pano Adı", "identity_value": "Dağıtım Panosu", "identity_hint": {"pattern": null, "validation": "soft"}, "equipment_axis": "none", "group_width": null, "header_patterns": [], "result_columns": [], "ambiguous": false, "ambiguous_reason": null},
              "attributes": [{"field": "Konum", "source_pattern": "Bulunduğu Yer", "value": "Zemin kat"}],
              "verdict": {"status": "uygun_degil", "raw": null, "basis": "derived", "evidence": "Pano kapak bağlantısı: U.D."}
            }
          ]
        },
        {
          "system_name": "Fonksiyon Testleri ve Kaçak Akım Röleleri",
          "section_heading_patterns": [],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun", "raw": null, "basis": "derived", "evidence": "Tüm RCD açma süreleri sınır içinde"},
          "equipment_definitions": [
            {
              "equipment_name": "Kaçak Akım Rölesi",
              "instance_structure": {"identity_field": "Sıra No", "identity_value": null, "identity_hint": {"pattern": null, "validation": "soft"}, "equipment_axis": "rows", "group_width": null, "header_patterns": ["Sıra No", "Linye", "Açma Akımı"], "result_columns": [], "ambiguous": false, "ambiguous_reason": null},
              "attributes": [{"field": "Linye", "source_pattern": "Linye", "value": null}, {"field": "IΔn (mA)", "source_pattern": "IΔn (mA)", "value": null}, {"field": "Açma Akımı (mA)", "source_pattern": "Açma Akımı (mA)", "value": null}, {"field": "Açma Süresi (ms)", "source_pattern": "Açma Süresi (ms)", "value": null}, {"field": "x5", "source_pattern": "x5", "value": null}],
              "verdict": {"status": null, "raw": null, "basis": "not_stated", "evidence": null}
            }
          ]
        },
        {
          "system_name": "Tesisat Geneli",
          "section_heading_patterns": ["KONTROLLER"],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun_degil", "raw": null, "basis": "derived", "evidence": "Buatlar: U.D."},
          "equipment_definitions": []
        },
        {
          "system_name": "Termal Ölçümler",
          "section_heading_patterns": [],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun", "raw": "UYGUN", "basis": "explicit", "evidence": "Sonuç: UYGUN"},
          "equipment_definitions": [
            {
              "equipment_name": "Termal Ölçüm Noktası",
              "instance_structure": {"identity_field": "NO", "identity_value": null, "identity_hint": {"pattern": null, "validation": "soft"}, "equipment_axis": "rows", "group_width": null, "header_patterns": ["NO", "ÖLÇÜM YERİ"], "result_columns": [], "ambiguous": false, "ambiguous_reason": null},
              "attributes": [{"field": "ÖLÇÜM YERİ", "source_pattern": "ÖLÇÜM YERİ", "value": null}, {"field": "NOKTA ADI", "source_pattern": "NOKTA ADI", "value": null}, {"field": "MAX. ÖLÇÜM", "source_pattern": "MAX. ÖLÇÜM", "value": null}, {"field": "ORTAM SICAKLIK", "source_pattern": "ORTAM SICAKLIK", "value": null}],
              "verdict": {"status": null, "raw": null, "basis": "not_stated", "evidence": null}
            }
          ]
        }
      ]
    },
    "overall_result": {"section_heading_patterns": [], "overall_text": {"label_patterns": [], "value_location_patterns": [], "text_boundary_patterns": []}, "overall_status": {"label_patterns": [], "status_patterns": [], "value_location_patterns": []}, "camelot_extraction": {"section_patterns": [], "text_patterns": [], "status_patterns": [], "status_extraction": "dynamic"}},
    "findings_structure": {"system_assignment": {"required": true, "source": [], "fallback": null}, "deduplication": {"enabled": true, "duplicate_finding_rule": "same_finding_same_system"}, "finding_fields": []}
  },
  "extracted_data": {
    "report_category": "yangin_tesisati",
    "extraction_mode": "structured",
    "findings": [
      {"id": "finding-1", "system_name": "Panolar", "description": "Ana dağıtım panosunda iç kapak yok.", "source_pages": [3], "severity": "Ağır kusur", "ambiguous": false},
      {"id": "finding-2", "system_name": null, "description": "Elektrik iç tesisat projesi yok.", "source_pages": [5], "severity": null, "ambiguous": false}
    ],
    "report_information": [{"key": "report_no", "value": "..."}, {"key": "control_date", "value": "12.07.2025"}, {"key": "validity_date", "value": "12.07.2026"}, {"key": "notlar", "value": null}, {"key": "bina", "value": null}],
    "facility_information": [{"key": "sebeke_tipi", "value": "TN-S"}, {"key": "proje_var_mi", "value": "Yok"}],
    "overall_result": {"text": "...", "status": "uygun_degil"},
    "result_legend": [{"code": "U", "meaning": "Uygun"}, {"code": "U.D.", "meaning": "Uygun Değil"}],
    "inspection_body": {"name": "...", "address": null, "phone": null, "email": null, "website": null, "tax_info": null, "accreditation": null, "personnel": []},
    "equipment_type": {"slug": "elektrik-ic-tesisati", "evidence": "ELEKTRİK İÇ TESİSATI GÖZLE KONTROL VE FONKSİYON TESTLERİ PERİYODİK KONTROL RAPORU"},
    "equipment_tag": {"label": null, "value": null, "evidence": null},
    "equipment_specs": []
  }
}

SADECE geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.
PROMPT;
    }

    // Topraklama tesisatı talimatı (Bakanlığın ZPKR01 formatı ya da firmanın kendi formatı); şema ve kurallar elektrikle aynı.
    private function groundingPrompt(): string
    {
        return <<<'PROMPT'
Sen ALÇAK GERİLİM TOPRAKLAMA TESİSATI periyodik kontrol raporları için çalışan bir TEMPLATE DISCOVERY motorusun.

AMAÇ
PDF'nin gerçek yapısını keşfet ve iki şey üret:
1. template.fire_systems.systems[]: rapordaki HER topraklama sisteminin YAPISI (equipment_definitions - ölçüm listelerinin rol haritası) ve GENEL UYGUNLUĞU (verdict). Şemadaki ad "fire_systems" olsa da burada topraklama tesisatının sistemleri yazılır.
2. extracted_data: rapor bilgileri, tesis bilgileri, genel sonuç, bulgular / kusurlar, sonuç sembollerinin ve uygunluk notlarının lejantı ve kontrolü yapan kuruluş.

RAPOR NE OLABİLİR
- Topraklama tesisatı periyodik kontrol raporu: Bakanlığın standart formatı ("Alçak Gerilim Topraklama Tesisatı Periyodik Kontrol Raporu") ya da firmanın kendi formatı ("topraklama tesisatı periyodik kontrol raporu", "topraklama ölçüm raporu" gibi). Raporun kapsadığı pano / ekipman tanımlamasını tesis bilgilerine (pano_ekipman_tanimlamasi) yaz.
- Ya da bu tesisatın TEK bir bölümünün ayrı raporu (örn. yalnızca ölçüm tablosu ya da yalnızca RCD selektivite kontrolü).

ANLAMINDAN OKU — NUMARAYA / BAŞLIĞA GÜVENME
Bölüm numaraları ve başlıklar firmadan firmaya değişir; bakanlık formatının numaralandırması ya da adlandırması da değişebilir. Sabit bir başlık arama; bir bölümün ne olduğunu İÇERİĞİNDEN anla (ölçüm tablosu mu, RCD seçicilik tablosu mu, gözle kontrol maddeleri mi, kusur listesi mi, nihai karar cümlesi mi).

TEMEL İLKE — HANGİ DEĞER SENDEN, HANGİSİ CAMELOT'TAN GELİR:
- Rapor uzunluğuyla / ölçüm noktası SAYISIYLA BÜYÜMEYEN her şey (rapor bilgileri, tesis bilgileri, sistem sonuçları, sonuç paragrafı, kusurlar) SABİT / SINIRLIDIR - bunları SEN doğrudan okur, GERÇEK değerleriyle yazarsın.
- Ölçüm noktası SAYISIYLA büyüyebilen (5 de olabilir 300 de) her gerçek değer SANA YAZDIRILMAZ - topraklama ölçüm tablosu ve RCD selektivite tablosu gibi tekrarlayan listelerde sen sadece YAPIYI (hangi satır / sütun ne anlama geliyor) tarif edersin, gerçek hücre değerlerini Camelot okur. Noktaları tek tek üretme; HER equipment_definitions kaydı bir ölçüm TİPİNİN yapısını tarif eder.
- İstisna: bir grupta GERÇEKTEN TEK bir örnek varsa instance_structure.equipment_axis="none" olur ve o TEK örneğin gerçek değerlerini (identity_value, attributes[].value, verdict) SEN yazarsın.

SABİT ALANLAR (şema gereği)
- extracted_data.report_category: "yangin_tesisati" yaz. Şemadaki seçeneklerde topraklama olmadığı için "tesisat raporu" anlamında kullanılır; bu raporda başka bir anlamı yoktur.
- extracted_data.extraction_mode: "structured".
- extracted_data.equipment_tag: {"label": null, "value": null, "evidence": null}; extracted_data.equipment_specs: [].
- template.template_version: "1.0".

0. RAPORUN KAPSAMI (template.template_type) — ilk karar
template.template_type'a TAM OLARAK şu iki değerden birini yaz:
- "tesisat_raporu": topraklama tesisatının genel raporu — tesisat için nihai bir sonuç / kanaat verir (bakanlığın standart formatı ya da firmanın "topraklama tesisatı periyodik kontrol raporu").
- "sistem_raporu": yalnızca TEK bir bölümün ayrı raporu (örn. yalnızca ölçüm tablosu ya da yalnızca RCD selektivite kontrolü); sonucu yalnızca o bölüm içindir.
Kararı raporun başlığı ve sonuç bölümü belirler: başlık topraklama tesisatının periyodik kontrol raporuysa ve sonuç bölümü tesisat için karar veriyorsa "tesisat_raporu" yaz. Raporda birden fazla bölüm varsa (örn. kontrol listesi ve ölçüm tablosu) "sistem_raporu" yazma.
Notlarda geçen "rapor yalnızca ölçüm yapılan kısımlar için geçerlidir / tüm tesisi kapsamamaktadır" gibi açıklamalar ölçümün kapsamını anlatır, raporun türünü değil; raporu sistem raporu YAPMAZ.
Emin olamıyorsan "tesisat_raporu" yaz.

1. TESİSAT TÜRÜ (extracted_data.equipment_type)
Katalogdan raporun konusu olan tesisatı seç: {"slug": "<katalogdaki slug>", "evidence": "<dayanak: rapor başlığı / kapsamı, PDF'de yazdığı gibi>"}.
Topraklama tesisatı raporu ise katalogdaki topraklama slug'ını yaz.
Rapor başka bir tesisatın raporuysa (elektrik iç tesisatı gözle kontrol ve fonksiyon testleri, yıldırımdan korunma / paratoner, trafo, yangın tesisatı gibi) DİĞER TESİSAT TÜRLERİ listesinden o tesisatın slug'ını yaz; listede de yoksa ya da tek bir makinenin raporuysa slug: null yaz (tahmin etme).
Raporun konusu başlığından ve kapsamından anlaşılır: elektrik iç tesisatı raporunun içinde topraklama bilgisi ya da ölçümü geçmesi onu topraklama raporu yapmaz.

2. SİSTEMLER (template.fire_systems.systems[].system_name)
Raporun her bölümünü katalogdaki sistemlerden ANLAMCA karşılık gelen adla, katalogda yazdığı gibi AYNEN yaz. Karşılıklar (raporun kendi adı ne olursa olsun, içeriğe bak):
- Topraklama Ölçümleri: son tüketim noktalarında dolaylı dokunmaya karşı koruma yeterliliği — ölçüm noktası başına çevrim empedansı (Zx) ya da topraklama direnci (Rx) ölçümü, sınır değer (Zs / RA), koruma elemanı (In, açma eğrisi tipi, açma akımı Ia), toprak kısa devre akımı (Ik), ölçüm noktasındaki RCD testi (IΔ, TΔ). Eski formatlarda "topraklama ölçüm tablosu".
- RCD Selektivite Kontrolü: artık akım anahtarlarının (RCD) seçicilik kontrolü — son tüketim noktasını besleyen pano ile ondan önceki panonun RCD'leri (tip, In, IΔn, gecikme, test açma zamanı).
- Gözle Kontroller: topraklama tesisatının gözle kontrolü — topraklama ve koruma iletkenleri, bağlantılar, eşpotansiyel bara, nötr ve koruma iletkeni bağlantıları, koruma iletken kesitleri, korozyon / kopma, ölçüm noktalarının ölçülebilirliği gibi kontrol maddeleri.
Raporda bir bölümün katalogda karşılığı yoksa rapordaki adını yaz; katalogdaki bir sisteme zorla bağlama.
SİSTEM NE ZAMAN YAZILIR: Topraklama Ölçümleri ve RCD Selektivite Kontrolü YALNIZCA raporda o ölçüm tablosu / bölümü varsa yazılır; Gözle Kontroller topraklama tesisatının gözle kontrol maddeleri (kontrol listesi) varsa yazılır. Bir maddede ölçümün ya da RCD'nin adının geçmesi o sistemin ölçüldüğü anlamına gelmez.
Raporda bu sistemlerden hiçbiri yoksa (şema en az bir kayıt istediği için) TEK bir kayıt yaz: system_name "Tesisat Geneli", verdict raporun sonucu, equipment_definitions []. Bu kayıt sistem değildir (katalogda yoktur, sisteme çevrilmez).
Tesisatın GENELİNE ait kontrol maddeleri (topraklama projesi var mı, projeye uygun mu gibi) bir sistem değildir: onlar için sistem kaydı açma; uygunsuz olanlar sistemsiz bulgu olur (bölüm 9).
Raporun TESİS ÖZELLİKLERİ / tesis bilgileri bölümü (toprak durumu, topraklama sistem tipi, ölçüm metodu, hava durumu, yapı cinsi gibi) bir sistem değildir: sistem kaydı açma, alanlarını bölüm 6'ya göre facility_information'a yaz. Bu alanlar bir ölçüm noktasının bilgisi de değildir; attributes'a YAZMA.
Aynı sistem birden fazla formda / sayfada tekrar ediyorsa TEK sistem kaydı yaz; devam patternlerini section_detection'da belirt.
Her sistem kaydı: system_name, section_heading_patterns, section_detection (başlangıç, devam ve bitiş patternleri), verdict (bölüm 3), equipment_definitions (bölüm 4).

3. SİSTEM UYGUNLUĞU (verdict) — GERÇEK DEĞER, PATTERN DEĞİL
verdict: {"status": "uygun" | "uygun_degil" | "uygulanamiyor" | null, "raw": "<raporda GERÇEKTEN yazan sonuç ifadesi, yoksa null>", "basis": "explicit" | "derived" | "not_stated", "evidence": "<kararın dayanağı olan kısa alıntı, yoksa null>"}
- basis="explicit": raporda o bölüm için AÇIK bir sonuç yazıyorsa. raw o ifadeyi aynen taşır.
- basis="derived": açık bir bölüm sonucu yoksa, o bölümün ölçüm sonuçlarından ya da kontrol maddelerinden EN AZ BİRİ "uygun değil" ise (ölçülen değer sınır değeri aşıyor, uygunluk notu uygun değil diyor) ya da o sisteme ait bir kusur varsa status="uygun_degil"; hepsi uygun ve o sisteme ait kusur yoksa status="uygun". Maddeleri ÇIKTIYA YAZMA - sadece bu kararı vermek için oku. evidence'a kararı belirleyen ölçümü / maddeyi / kusuru kısaca yaz.
- basis="not_stated": ne açık bir sonuç ne de değerlendirilebilir bir madde varsa status: null, raw: null.
status SADECE bu 3 değerden biri ya da null olabilir.

4. ÖLÇÜM LİSTELERİ (equipment_definitions)
Ölçüm noktaları ve RCD selektivite satırları raporda okunur ve ait oldukları sistemin equipment_definitions listesine yazılır; her kayıt bir TİPİN yapısını tarif eder, o tipten kaç örnek olursa olsun TEK kayıt.
- Topraklama Ölçümleri → equipment_name "Ölçüm Noktası". identity_field: sıra no / no etiketi. attributes (raporda GERÇEKTEN olanlar, PDF'deki etiketleriyle): ölçüm noktası (etiket / kod / yer), In (A), açma eğrisi tipi, açma akımı Ia (A), toprak kısa devre akımı Ik (A), RCD tipi, IΔn (mA), ölçülen değer Zx / Rx (Ω), sınır değer Zs / RA (Ω), RCD açma akımı IΔ (mA), RCD açma zamanı TΔ (ms). Sonuç sütununda "Uygun / Uygun Değil" yazıyorsa onu attributes'a ekleme (parser sonucu kendisi okur); yalnızca bir uygunluk notu kodu yazıyorsa (örn. "Not-2") onu attributes'a "Uygunluk notu" olarak ekle.
- RCD Selektivite Kontrolü → equipment_name "RCD Selektivite". identity_field: no etiketi. attributes: önceki pano adı, önceki panonun RCD etiket bilgileri (tip, In, IΔn, gecikme), son tüketim noktasını besleyen pano adı, kullanılan RCD (tip, IΔn), test açma zamanı TΔ (ms).
- Gözle Kontroller ve ölçüm listesi olmayan sistemlerde equipment_definitions: [].
- Tekrarlayan tablo (birden fazla nokta / satır): equipment_axis "rows" ya da "columns"; gerçek hücreleri Camelot okur, identity_value ve attributes[].value null kalır, verdict {"status": null, "raw": null, "basis": "not_stated", "evidence": null}.
- Raporda gerçekten TEK bir ölçüm noktası varsa: equipment_axis "none"; identity_value, attributes gerçek değerleriyle, verdict o noktanın sonucu.
- Bütün değerleri boş ya da "-" olan satırı / bloğu (raporda olmayan nokta) YAZMA.
- Tablo raporda nasılsa öyle gösterilecek: yukarıdaki liste yalnızca rehberdir; tablonun BÜTÜN sütunlarını PDF'deki soldan sağa sırasıyla attributes'a yaz (kimlik sütunu ve satırın sonucunu veren Sonuç sütunu hariç). Bir sütunun bütün değerleri "-" ya da boş olsa bile o sütunu yaz.

4a. instance_structure — TEKRAR YAPISI
- identity_field: her örneği ayırt eden satır / sütun etiketi (örn. "Sıra No", "No"). ZORUNLU çapa - parser gerçek tabloyu bu etiketi Camelot verisinde arayarak bulacak.
- identity_value: SADECE equipment_axis="none" iken o TEK örneğin gerçek no'su; aksi halde null.
- identity_hint: {"pattern": null, "validation": "soft"}.
- equipment_axis: "rows" (her SATIR bir örnek; topraklama ölçüm tablolarında yaygın), "columns" (her SÜTUN bir örnek), "none" (tek örnek).
- group_width: SADECE "columns" iken bir bloktaki örnek sütunu sayısı; diğerlerinde null.
- header_patterns: tablonun başladığını gösteren gerçek etiket metinleri (çok satırlı başlıkta her satırın etiketini ayrı yaz).
- result_columns: yalnızca tek bir sonucu 2-3 sütuna bölen işaret setinde (örn. "U | U.D. | N.U.") {"header_pattern", "kind": "fixed_value", "value"} ya da sonuç YAZMAYAN serbest metin not sütununda {"header_pattern", "kind": "note", "value": null}; yoksa []. Satırın sonucunu "Uygun / Uygun Değil" diye yazan TEK "Sonuç" sütunu result_columns'a YAZILMAZ (note da değildir): parser satırın sonucunu o sütundan kendisi okur. Ölçüm değeri sütunları (Ω, A, mA, ms) result_columns DEĞİLDİR; onlar attributes'tır.
- ambiguous: tablonun eksenini, kimlik alanını ya da sınırlarını GÜVENLE belirleyemediysen true ve ambiguous_reason'a nedenini yaz.

4b. attributes: {"field": "<adı>", "source_pattern": "<PDF'de GERÇEKTEN gördüğün etiket metni>", "value": null} (value yalnızca "none" iken gerçek değerle dolar). Birimi etiketteki gibi koru. Numaralı kontrol maddesi gibi görünen satırları attributes'a EKLEME.
Tablo sütunlarında field, PDF'deki sütun başlığının AYNISIDIR (kullanıcıya bu başlıkla gösterilir; kendi adını verme, kısaltma ya da çevirme): örn. "Ölçüm Noktası", "In A", "Kaçak Akım Rölesi Tipi", "Ölçülen Değer Ω". Başlık PDF metninde birden fazla satıra bölünmüşse tek satırda birleştir. Tek istisna yukarıdaki "Uygunluk notu".

5. RAPOR BİLGİLERİ
template.report_information.fields içine şu key'leri ve PDF'deki gerçek etiketlerini yaz; extracted_data.report_information içine aynı key'lerle PDF'den okunan GERÇEK değerleri yaz (yoksa value: null, key'i atlama):
report_no, company_title (raporu isteyen firma / müşteri), address (periyodik kontrol adresi), report_date, control_date (kontrolün yapıldığı tarih; rapor tarihiyle aynı alan değildir), validity_date (bir sonraki periyodik kontrol tarihi), notlar (raporun NOTLAR bölümünün metni, PDF'de yazdığı gibi; birden fazla not varsa her biri ayrı satırda; bölüm yoksa ya da içinde yalnızca "-" yazıyorsa null), bina (rapor tesisin tamamı için değil de belirli bir bina / blok / bölüm için düzenlendiyse onun adı, PDF'de yazdığı gibi; tesis geneli içinse ya da bilgi yoksa null; müşteri firma adı, adres ya da tek bir panonun adı bina değildir. Raporun kapsadığı yer / ekipman tanımlaması — örn. "Ekipman Seri No / Kod: Tesellüm Depo", "Ekipmanın Bulunduğu Yer" — bir bina / bölüm adıysa onu bina olarak da yaz; "Genel", "Tesis geneli" gibi ifadeler bina değildir).

6. TESİS BİLGİLERİ (template.facility_or_project_information, extracted_data.facility_information)
Tesise / ekipmana ait bilgi bölümünü (firmanın formatında "tesisin özellikleri") bul; section_heading_patterns'a gerçek başlıklarını yaz. Bu bilgiler kullanıcıya "Tesis Özellikleri" kartında raporun kendi etiketleriyle gösterilir: her alanın label_patterns'ına önce PDF'deki etiketi AYNEN yaz (sondaki ":" hariç). Notlardaki cümleleri (raporun kapsamı, sınır değer açıklaması gibi) tesis bilgisine yazma; onlar notlar'a gider. Raporda GERÇEKTEN bulunan alanları şu key'lerle yaz (seçmeli alanlarda işaretli olan seçenek):
sebeke_tipi (TT / IT / TN / TN-C / TN-S / TN-C-S), sebeke_gerilimi, enerji_saglayan_kurulus, proje_var_mi, tek_hat_semasi_var_mi, kontrol_nedeni (periyodik / ilk kontrol), topraklayici_tipi (ring / yüzeysel / temel / derin / belirlenemedi), yapi_cinsi, kullanim_amaci, dolayli_dokunma_koruma_onlemi, olcum_metodu (çevrim empedansı / 3 uçlu / klamp), hava_durumu, zemin_nem_durumu (toprak durumu: kuru / ıslak / nemli), kapsamli_degisiklik_var_mi, onceki_kontrol_etiketi_var_mi, pano_ekipman_tanimlamasi (raporun kapsadığı pano / ekipman), son_kontrol_tarihi.
Listede olmayan önemli bir tesis bilgisi varsa kendi kısa key'iyle ekleyebilirsin. Raporda olmayan alanı YAZMA; işaretlenmemiş seçeneği seçilmiş sayma.

7. LEJANT (extracted_data.result_legend)
Raporun sonuç kısaltmalarını ve uygunluk notlarını açıklayan metni GERÇEKTEN YAZDIĞI GİBİ {"code", "meaning"} olarak kopyala (örn. {"code": "U.D.", "meaning": "Uygun Değil"}, {"code": "Not-2", "meaning": "Güvenlik şartı sağlanamadığından uygun değildir. (Ağır kusur)"}); yoksa [].

8. GENEL SONUÇ VE KANAAT
template.overall_result içine yalnızca pattern / konum tarifini yaz. AYRICA raporun nihai kararını (hangi başlıkla geçerse geçsin; "sonuç ve kanaat", "sonuç" ya da metnin sonundaki karar cümlesi) SEN oku ve extracted_data.overall_result içine yaz:
- text: nihai karar paragrafının PDF'deki gerçek metni (bulamıyorsan null).
- status: "uygun" (örn. "kullanımı uygundur") | "uygun_degil" (örn. "uygun değildir", "kusurlar giderilmeden kullanılması uygun değildir") | "uygulanamiyor"; net değilse null.

9. KUSURLAR / BULGULAR (extracted_data.findings)
Kusur açıklamaları, tespitler, eksiklikler ve notlardaki uygunsuzluklar. Her finding: id, system_name, description, source_pages, severity, ambiguous.
NOTLAR bölümündeki açıklamalar (sınır değerin nasıl belirlendiği, raporun kapsamı gibi) bulgu değildir; bölüm 5'teki notlar'a yazılır.
- system_name: kusurun ait olduğu sistemin adı (bölüm 2'deki katalog adıyla); ölçüm noktası / koruma elemanı / RCD testi kusuru → Topraklama Ölçümleri, RCD seçicilik kusuru → RCD Selektivite Kontrolü, iletken / bağlantı / bara / kesit / korozyon kusuru → Gözle Kontroller. Tesisatın GENELİNE ait kusur (topraklama projesi yok gibi) bir sisteme ait değildir: system_name null, ambiguous false. Sistemini GÜVENLE belirleyemediğin kusurda system_name null, ambiguous true.
- severity: rapor kusuru derecelendiriyorsa raporun kendi ifadesiyle yaz: hafif kusur "*" / ağır kusur "**" işaretliyse ya da metinde "(Ağır kusur)" yazıyorsa "Hafif kusur" / "Ağır kusur". Rapor derece vermiyorsa null; kendinden derece uydurma.
- description: kusurun PDF'deki metni; kusurun kendi madde kodu / numarası varsa başına yaz. Kusur hangi ölçüm noktasında / panoda ise metinde aynen koru. Aynı kusuru tekrarlama; U / UD hücrelerinden tek başına kusur üretme. source_pages gerçek PDF sayfalarıdır.

10. ÇIKARIM SINIRI
equipment_definitions YAPI tarifidir. Ölçüm noktası SAYISIYLA BÜYÜYEBİLEN gerçek değerler (identity_value, attributes[].value) equipment_axis="rows" / "columns" iken HER ZAMAN null kalır - Camelot okur. TEK istisna equipment_axis="none".

11. PERİYODİK KONTROLÜ YAPAN KURULUŞ (extracted_data.inspection_body)
Raporu düzenleyen / periyodik kontrolü yapan kuruluşu GERÇEK değerleriyle yaz: name, address, phone, email, website, tax_info, accreditation ve personnel (kontrol eden / onaylayan; role, name, profession, chamber_registry_no, diploma, authorization_no). Yalnızca etiketler olup değerler yoksa o kişiyi ekleme; hiç kişi yoksa []. Raporu İSTEYEN firma (müşteri) bu değildir. PDF'de olmayan bilgiyi uydurma; null bırak.

findings_structure: system_assignment {"required": true, "source": [], "fallback": null}; deduplication {"enabled": true, "duplicate_finding_rule": "same_finding_same_system"}; finding_fields [].
PROMPT;
    }

    private function groundingContract(): string
    {
        return <<<'PROMPT'
ÇIKTI ŞEKLİ (örnek değerler YALNIZCA şekli anlatır; rapordaki gerçek bölümleri ve değerleri yaz):
{
  "template": {
    "template_type": "tesisat_raporu",
    "template_version": "1.0",
    "report_information": {"fields": [{"key": "report_no", "label_patterns": ["Rapor Numarası"]}, {"key": "control_date", "label_patterns": ["Periyodik Kontrol Başlangıç Tarihi"]}, {"key": "validity_date", "label_patterns": ["Bir Sonraki Periyodik Kontrol Tarihi"]}]},
    "facility_or_project_information": {"section_heading_patterns": ["EKİPMAN BİLGİLERİ"], "fields": [{"key": "sebeke_tipi", "label_patterns": ["Şebeke tipi"]}, {"key": "olcum_metodu", "label_patterns": ["Ölçüm ve doğrulama metodu"]}]},
    "fire_systems": {
      "systems": [
        {
          "system_name": "Topraklama Ölçümleri",
          "section_heading_patterns": ["SON TÜKETİM NOKTALARINDA DOLAYLI DOKUNMAYA KARŞI KORUMA YETERLİLİĞİ KONTROLÜ"],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun_degil", "raw": null, "basis": "derived", "evidence": "1 Dağıtım Panosu: ölçülen 0.84 Ω > sınır 0.14 Ω, UYGUN DEĞİL"},
          "equipment_definitions": [
            {
              "equipment_name": "Ölçüm Noktası",
              "instance_structure": {"identity_field": "Sıra No", "identity_value": null, "identity_hint": {"pattern": null, "validation": "soft"}, "equipment_axis": "rows", "group_width": null, "header_patterns": ["Sıra No", "Ölçüm Noktası"], "result_columns": [], "ambiguous": false, "ambiguous_reason": null},
              "attributes": [{"field": "Ölçüm Noktası", "source_pattern": "Ölçüm Noktası", "value": null}, {"field": "In A", "source_pattern": "In A", "value": null}, {"field": "Açma Eğrisi Tipi", "source_pattern": "Açma Eğrisi Tipi", "value": null}, {"field": "Kaçak Akım Rölesi Tipi", "source_pattern": "Kaçak Akım Rölesi Tipi", "value": null}, {"field": "Ölçülen Değer Ω", "source_pattern": "Ölçülen Değer Ω", "value": null}, {"field": "Sınır Değer Ω", "source_pattern": "Sınır Değer Ω", "value": null}],
              "verdict": {"status": null, "raw": null, "basis": "not_stated", "evidence": null}
            }
          ]
        },
        {
          "system_name": "Gözle Kontroller",
          "section_heading_patterns": ["KONTROLLER"],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun", "raw": null, "basis": "derived", "evidence": "Koruma iletken kesitleri uygun mu?: U"},
          "equipment_definitions": []
        }
      ]
    },
    "overall_result": {"section_heading_patterns": [], "overall_text": {"label_patterns": [], "value_location_patterns": [], "text_boundary_patterns": []}, "overall_status": {"label_patterns": [], "status_patterns": [], "value_location_patterns": []}, "camelot_extraction": {"section_patterns": [], "text_patterns": [], "status_patterns": [], "status_extraction": "dynamic"}},
    "findings_structure": {"system_assignment": {"required": true, "source": [], "fallback": null}, "deduplication": {"enabled": true, "duplicate_finding_rule": "same_finding_same_system"}, "finding_fields": []}
  },
  "extracted_data": {
    "report_category": "yangin_tesisati",
    "extraction_mode": "structured",
    "findings": [
      {"id": "finding-1", "system_name": "Topraklama Ölçümleri", "description": "Dağıtım panosu ölçüm noktasında ölçülen değer sınır değerin üzerinde.", "source_pages": [1], "severity": "Ağır kusur", "ambiguous": false},
      {"id": "finding-2", "system_name": null, "description": "Topraklama tesisat projesi yok.", "source_pages": [1], "severity": null, "ambiguous": false}
    ],
    "report_information": [{"key": "report_no", "value": "..."}, {"key": "control_date", "value": "12.07.2025"}, {"key": "validity_date", "value": "12.07.2026"}, {"key": "notlar", "value": "..."}, {"key": "bina", "value": null}],
    "facility_information": [{"key": "sebeke_tipi", "value": "TN-S"}, {"key": "olcum_metodu", "value": "Çevrim empedansı"}, {"key": "zemin_nem_durumu", "value": "Kuru"}],
    "overall_result": {"text": "...", "status": "uygun_degil"},
    "result_legend": [{"code": "U", "meaning": "Uygun"}, {"code": "U.D.", "meaning": "Uygun Değil"}],
    "inspection_body": {"name": "...", "address": null, "phone": null, "email": null, "website": null, "tax_info": null, "accreditation": null, "personnel": []},
    "equipment_type": {"slug": "topraklama", "evidence": "TOPRAKLAMA TESİSATI PERİYODİK KONTROL RAPORU"},
    "equipment_tag": {"label": null, "value": null, "evidence": null},
    "equipment_specs": []
  }
}

SADECE geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.
PROMPT;
    }

    // Yangın algılama ve uyarı sistemleri talimatı (Bakanlığın ZPKR04 formatı ya da firmanın kendi formatı); şema ve kurallar
    // elektrik ailesiyle aynı. Yangın tesisatının (söndürme) talimatı (PkReportAnalyzer) kullanılmaz ve değişmez.
    private function detectionPrompt(): string
    {
        return <<<'PROMPT'
Sen YANGIN ALGILAMA VE UYARI SİSTEMLERİ periyodik kontrol raporları için çalışan bir TEMPLATE DISCOVERY motorusun.

AMAÇ
PDF'nin gerçek yapısını keşfet ve iki şey üret:
1. template.fire_systems.systems[]: rapordaki HER algılama / uyarı sisteminin YAPISI (equipment_definitions - cihaz ve test listelerinin rol haritası) ve GENEL UYGUNLUĞU (verdict).
2. extracted_data: rapor bilgileri, bina / tesis bilgileri, genel sonuç, bulgular / kusurlar, sonuç sembollerinin lejantı ve kontrolü yapan kuruluş.

RAPOR NE OLABİLİR
- Yangın algılama ve uyarı sistemleri periyodik kontrol raporu: Bakanlığın standart formatı ("Yangın Algılama ve Uyarı Sistemleri Periyodik Kontrol Raporu", ZPKR04) ya da firmanın kendi formatı ("yangın algılama ve ihbar sistemi", "yangın alarm sistemi periyodik kontrol raporu" gibi). Tesisin tamamı için ya da tek bir bina / blok için düzenlenebilir.
- Ya da bu sistemin TEK bir bölümünün ayrı raporu (örn. yalnızca gaz algılama sistemi raporu, yalnızca acil anons sistemi raporu, yalnızca dedektör test listesi).

ANLAMINDAN OKU — NUMARAYA / BAŞLIĞA GÜVENME
Bölüm numaraları ve başlıklar firmadan firmaya değişir; bakanlık formatının numaralandırması ya da adlandırması da değişebilir. Sabit bir başlık arama; bir bölümün ne olduğunu İÇERİĞİNDEN anla (santral bilgileri mi, dedektör / buton / siren test tablosu mu, gözle kontrol maddeleri mi, belge kontrolleri mi, kusur listesi mi, nihai karar cümlesi mi).

TEMEL İLKE — HANGİ DEĞER SENDEN, HANGİSİ CAMELOT'TAN GELİR:
- Rapor uzunluğuyla / cihaz SAYISIYLA BÜYÜMEYEN her şey (rapor bilgileri, bina bilgileri, sistem sonuçları, sonuç paragrafı, kusurlar) SABİT / SINIRLIDIR - bunları SEN doğrudan okur, GERÇEK değerleriyle yazarsın.
- Cihaz SAYISIYLA büyüyebilen (5 de olabilir 500 de) her gerçek değer SANA YAZDIRILMAZ - dedektör / buton / siren listeleri, zon / adres listeleri, test değerleri tabloları gibi tekrarlayan listelerde sen sadece YAPIYI (hangi satır / sütun ne anlama geliyor) tarif edersin, gerçek hücre değerlerini Camelot okur. Cihazları tek tek üretme; HER equipment_definitions kaydı bir cihaz / test TİPİNİN yapısını tarif eder.
- İstisna: bir grupta GERÇEKTEN TEK bir örnek varsa (örn. tek santral) instance_structure.equipment_axis="none" olur ve o TEK örneğin gerçek değerlerini (identity_value, attributes[].value, verdict) SEN yazarsın.

SABİT ALANLAR (şema gereği)
- extracted_data.report_category: "yangin_tesisati" yaz. Şemada bu rapor için ayrı seçenek olmadığı için "tesisat raporu" anlamında kullanılır; bu raporda başka bir anlamı yoktur.
- extracted_data.extraction_mode: "structured".
- extracted_data.equipment_tag: {"label": null, "value": null, "evidence": null}; extracted_data.equipment_specs: [].
- template.template_version: "1.0".

0. RAPORUN KAPSAMI (template.template_type) — ilk karar
template.template_type'a TAM OLARAK şu iki değerden birini yaz:
- "tesisat_raporu": yangın algılama ve uyarı sisteminin genel raporu — sistem için nihai bir sonuç / kanaat verir (bakanlığın standart formatı ya da firmanın "yangın algılama ve uyarı sistemi periyodik kontrol raporu"). Tek bir bina için düzenlenmiş olsa da o binanın genel raporudur.
- "sistem_raporu": yalnızca TEK bir bölümün ayrı raporu (örn. yalnızca gaz algılama, yalnızca acil anons); sonucu yalnızca o bölüm içindir.
Kararı raporun başlığı ve sonuç bölümü belirler: başlık yangın algılama ve uyarı sisteminin periyodik kontrol raporuysa ve sonuç bölümü sistem için karar veriyorsa "tesisat_raporu" yaz. Raporda birden fazla bölüm varsa "sistem_raporu" yazma. Notlarda raporun kapsamını anlatan cümleler raporu sistem raporu YAPMAZ.
Emin olamıyorsan "tesisat_raporu" yaz.

1. TESİSAT TÜRÜ (extracted_data.equipment_type)
Katalogdan raporun konusu olan tesisatı seç: {"slug": "<katalogdaki slug>", "evidence": "<dayanak: rapor başlığı / kapsamı, PDF'de yazdığı gibi>"}.
Yangın algılama ve uyarı sistemi raporu ise katalogdaki yangın algılama slug'ını yaz.
Rapor başka bir tesisatın raporuysa (yangın tesisatı / söndürme sistemleri — pompa, dolap, hidrant, sprinkler —, elektrik iç tesisatı, topraklama, duman tahliye / basınçlandırma gibi) DİĞER TESİSAT TÜRLERİ listesinden o tesisatın slug'ını yaz; listede de yoksa ya da tek bir makinenin raporuysa slug: null yaz (tahmin etme).
Raporun konusu başlığından ve kapsamından anlaşılır: yangın tesisatı raporunun içinde alarm / algılama bağlantısı geçmesi onu algılama raporu yapmaz.

2. SİSTEMLER (template.fire_systems.systems[].system_name)
Raporun her bölümünü katalogdaki sistemlerden ANLAMCA karşılık gelen adla, katalogda yazdığı gibi AYNEN yaz. Karşılıklar (raporun kendi adı ne olursa olsun, içeriğe bak):
- Yangın Alarm Santrali (Kontrol Paneli): yangın alarm / kontrol paneli ve tekrarlayıcı paneller — marka / model, adresli / konvansiyonel, zon / loop sayısı, alarm / arıza / izolasyon göstergeleri, olay kaydı, panel fonksiyon testleri.
- Dedektörler (Duman, Isı, Alev): otomatik algılama cihazları — optik duman, ısı, alev, çoklu sensör, ışın tipi, hava emmeli dedektörler ve testleri.
- Yangın İhbar Butonları: elle ihbar butonları ve testleri.
- Sesli ve Işıklı Uyarı Cihazları (Siren, Flaşör): siren, flaşör, flaşörlü siren ve testleri.
- Acil Anons / Sesli Tahliye Sistemi: acil anons / sesli tahliye (EVAC) sistemi.
- Gaz Algılama Sistemi: gaz dedektörleri ve gaz algılama paneli.
- Güç Kaynağı ve Aküler: şebeke ve yedek güç kaynağı, aküler, akü gerilimi / kapasitesi / şarj testleri.
- Söndürme Sistemi Kontrolü: otomatik söndürme sisteminin algılama sistemiyle bağlantısı ve tetiklenmesi (gazlı söndürme kontrol paneli, sprinkler akış anahtarı / vana izleme bağlantısı).
- Yangın Senaryosu ve Kontrol Fonksiyonları: yangın senaryosu testleri ve kontrol çıkışları — asansörlerin çağrılması, havalandırma / damper, basınçlandırma, kapı tutucular, enerji kesme gibi.
- Belge ve Kayıt Kontrolleri: belge kontrolleri bölümü (proje, kullanım kılavuzu, bakım kayıtları, önceki raporlar gibi).
- Proje Bilgileri: sistemin projeye uygunluğu.
Raporda bir bölümün katalogda karşılığı yoksa rapordaki adını yaz; katalogdaki bir sisteme zorla bağlama.
SİSTEM NE ZAMAN YAZILIR: bir sistem YALNIZCA raporda o sisteme ait AYRI bir bölüm, form ya da tablo varsa yazılır — örn. santral bilgi / test bölümü, dedektör / buton / siren test tablosu, akü ölçümleri, senaryo test listesi, belge kontrolleri bölümü. Raporun GENEL KONTROL LİSTESİNDEKİ tek tek maddeler ("butonlar erişilebilir mi", "dedektörler fiziksel olarak sağlam mı", "santral çalışır durumda mı" gibi) sistemin KENDİ kontrolleridir: bu maddelerden sistem çıkarma, maddeyi bir sisteme bağlama. Maddede bir cihazın adının geçmesi o sistemin ayrıca kontrol edildiği anlamına gelmez.
Genel kontrol listesinin tamamı için TEK bir kayıt yaz: system_name "Tesisat Geneli", verdict listenin sonucu, equipment_definitions []. Bu kayıt sistem değildir (katalogda yoktur, sisteme çevrilmez); şema en az bir kayıt istediği için vardır. Raporda ayrı sistem bölümü hiç yoksa fire_systems.systems'ta yalnızca bu kayıt olur. Genel listedeki uygun olmayan maddeler sistemsiz bulgu olur (system_name null).
Raporun BİNA / TESİS BİLGİLERİ bölümü (yapı türü, tehlike sınıfı, kullanım sınıfı, kullanım alanı, bina yüksekliği, kat sayısı, bölüm sayısı gibi) ve sistemin genel tanımı (algılama otomatik / elle, uyarı türü, adresli mi) bir sistem değildir: sistem kaydı açma, alanlarını bölüm 6'ya göre facility_information'a yaz. Bu alanlar bir cihazın bilgisi de değildir; attributes'a YAZMA.
Aynı sistem birden fazla formda / sayfada tekrar ediyorsa (örn. her bina / kat için ayrı dedektör listesi) TEK sistem kaydı yaz; devam patternlerini section_detection'da belirt.
Her sistem kaydı: system_name, section_heading_patterns, section_detection (başlangıç, devam ve bitiş patternleri), verdict (bölüm 3), equipment_definitions (bölüm 4).

3. SİSTEM UYGUNLUĞU (verdict) — GERÇEK DEĞER, PATTERN DEĞİL
verdict: {"status": "uygun" | "uygun_degil" | "uygulanamiyor" | null, "raw": "<raporda GERÇEKTEN yazan sonuç ifadesi, yoksa null>", "basis": "explicit" | "derived" | "not_stated", "evidence": "<kararın dayanağı olan kısa alıntı, yoksa null>"}
- basis="explicit": raporda o bölüm için AÇIK bir sonuç yazıyorsa. raw o ifadeyi aynen taşır.
- basis="derived": açık bir bölüm sonucu yoksa, o bölümün test sonuçlarından ya da kontrol maddelerinden EN AZ BİRİ "uygun değil" ise (cihaz çalışmıyor, test başarısız, akü değeri yetersiz) ya da o sisteme ait bir kusur varsa status="uygun_degil"; hepsi uygun ve o sisteme ait kusur yoksa status="uygun". Maddeleri ÇIKTIYA YAZMA - sadece bu kararı vermek için oku. evidence'a kararı belirleyen testi / maddeyi / kusuru kısaca yaz.
- basis="not_stated": ne açık bir sonuç ne de değerlendirilebilir bir madde varsa status: null, raw: null.
status SADECE bu 3 değerden biri ya da null olabilir.

4. CİHAZ VE TEST LİSTELERİ (equipment_definitions)
Cihaz listeleri ve test tabloları raporda okunur ve ait oldukları sistemin equipment_definitions listesine yazılır; her kayıt bir TİPİN yapısını tarif eder, o tipten kaç örnek olursa olsun TEK kayıt.
- Örnek tipler: "Dedektör" (zon / loop / adres, tip, konum, test sonucu), "İhbar Butonu", "Siren / Flaşör", "Akü" (gerilim, kapasite), "Test Değerleri" (cihaz türü başına adet / test edilen / uygun gibi sayım tablosu), "Senaryo Testi" (senaryo / çıkış, beklenen işlev, sonuç), "Santral" (tek santral: equipment_axis "none", marka, model, zon / loop sayısı gerçek değerleriyle).
- Belge ve kontrol listesi olan sistemlerde (Belge ve Kayıt Kontrolleri, Proje Bilgileri) equipment_definitions: [].
- Tekrarlayan tablo (birden fazla cihaz / satır): equipment_axis "rows" ya da "columns"; gerçek hücreleri Camelot okur, identity_value ve attributes[].value null kalır, verdict {"status": null, "raw": null, "basis": "not_stated", "evidence": null}.
- Raporda gerçekten TEK bir örnek varsa: equipment_axis "none"; identity_value, attributes gerçek değerleriyle, verdict o örneğin sonucu.
- Bütün değerleri boş ya da "-" olan satırı / bloğu (raporda olmayan cihaz) YAZMA.
- Tablo raporda nasılsa öyle gösterilecek: tablonun BÜTÜN sütunlarını PDF'deki soldan sağa sırasıyla attributes'a yaz (kimlik sütunu ve satırın sonucunu veren Sonuç sütunu hariç). Bir sütunun bütün değerleri "-" ya da boş olsa bile o sütunu yaz.
- Tabloda sıra no sütunu varsa identity_field odur; yoksa adres / zon / cihaz no sütunu.

4a. instance_structure — TEKRAR YAPISI
- identity_field: her örneği ayırt eden satır / sütun etiketi (örn. "Sıra No", "Adres", "Zon"). ZORUNLU çapa - parser gerçek tabloyu bu etiketi Camelot verisinde arayarak bulacak.
- identity_value: SADECE equipment_axis="none" iken o TEK örneğin gerçek no'su / adı; aksi halde null.
- identity_hint: {"pattern": null, "validation": "soft"}.
- equipment_axis: "rows" (her SATIR bir örnek), "columns" (her SÜTUN bir örnek), "none" (tek örnek).
- group_width: SADECE "columns" iken bir bloktaki örnek sütunu sayısı; diğerlerinde null.
- header_patterns: tablonun başladığını gösteren gerçek etiket metinleri (çok satırlı başlıkta her satırın etiketini ayrı yaz).
- result_columns: yalnızca tek bir sonucu 2-3 sütuna bölen işaret setinde (örn. "U | U.D. | N.U." ya da "Evet | Hayır") {"header_pattern", "kind": "fixed_value", "value"} ya da sonuç YAZMAYAN serbest metin not / açıklama sütununda {"header_pattern", "kind": "note", "value": null}; yoksa []. Satırın sonucunu "Uygun / Uygun Değil" diye yazan TEK "Sonuç" sütunu result_columns'a YAZILMAZ (note da değildir): parser satırın sonucunu o sütundan kendisi okur. Değer sütunları (adet, gerilim, süre gibi) result_columns DEĞİLDİR; onlar attributes'tır.
- ambiguous: tablonun eksenini, kimlik alanını ya da sınırlarını GÜVENLE belirleyemediysen true ve ambiguous_reason'a nedenini yaz.

4b. attributes: {"field": "<adı>", "source_pattern": "<PDF'de GERÇEKTEN gördüğün etiket metni>", "value": null} (value yalnızca "none" iken gerçek değerle dolar). Birimi etiketteki gibi koru. Numaralı kontrol maddesi gibi görünen satırları attributes'a EKLEME.
Tablo sütunlarında field, PDF'deki sütun başlığının AYNISIDIR (kullanıcıya bu başlıkla gösterilir; kendi adını verme, kısaltma ya da çevirme). Başlık PDF metninde birden fazla satıra bölünmüşse tek satırda birleştir.

5. RAPOR BİLGİLERİ
template.report_information.fields içine şu key'leri ve PDF'deki gerçek etiketlerini yaz; extracted_data.report_information içine aynı key'lerle PDF'den okunan GERÇEK değerleri yaz (yoksa value: null, key'i atlama):
report_no, company_title (raporu isteyen firma / müşteri), address (periyodik kontrol adresi), report_date, control_date (kontrolün yapıldığı tarih; rapor tarihiyle aynı alan değildir), validity_date (bir sonraki periyodik kontrol tarihi), notlar (raporun NOTLAR bölümünün metni, PDF'de yazdığı gibi; birden fazla not varsa her biri ayrı satırda; bölüm yoksa ya da içinde yalnızca "-" yazıyorsa null), bina (rapor tesisin tamamı için değil de belirli bir bina / blok / bölüm için düzenlendiyse onun adı, PDF'de yazdığı gibi; tesis geneli içinse ya da bilgi yoksa null; müşteri firma adı, adres ya da tek bir cihazın yeri bina değildir. Raporun kapsadığı yer / "kontrol edilen bölüm" / "ekipmanın bulunduğu yer" tek bir bina / blok adıysa onu bina olarak yaz; birden fazla bina sayılıyorsa ya da "Genel", "Tesis geneli" yazıyorsa null).

6. BİNA / TESİS BİLGİLERİ (template.facility_or_project_information, extracted_data.facility_information)
Bina / tesis bilgileri ve sistem tanımı bölümünü bul; section_heading_patterns'a gerçek başlıklarını yaz. Bu bilgiler kullanıcıya "Tesis Özellikleri" kartında raporun kendi etiketleriyle gösterilir: her alanın label_patterns'ına önce PDF'deki etiketi AYNEN yaz (sondaki ":" hariç). Notlardaki cümleleri tesis bilgisine yazma; onlar notlar'a gider. Raporda GERÇEKTEN bulunan alanları şu key'lerle yaz (seçmeli alanlarda işaretli olan seçenek):
yapi_turu, tehlike_sinifi, kullanim_sinifi, toplam_kullanim_alani, bina_yuksekligi, yapi_yuksekligi, kat_sayisi, bolum_sayisi, algilama_sistemi (otomatik / elle), uyari_sistemi (görsel / sesli / ışıklı ve sesli / anons), adresli_mi, kontrol_paneli (santral marka / model, tek satırda), zon_loop_sayisi, kontrol_nedeni (periyodik / ilk kontrol), proje_var_mi, son_kontrol_tarihi.
Listede olmayan önemli bir bina / sistem bilgisi varsa kendi kısa key'iyle ekleyebilirsin. Raporda olmayan alanı YAZMA; işaretlenmemiş seçeneği seçilmiş sayma.

7. LEJANT (extracted_data.result_legend)
Raporun sonuç kısaltmalarını açıklayan metni GERÇEKTEN YAZDIĞI GİBİ {"code", "meaning"} olarak kopyala (örn. {"code": "U.D.", "meaning": "Uygun Değil"}); yoksa [].

8. GENEL SONUÇ VE KANAAT
template.overall_result içine yalnızca pattern / konum tarifini yaz. AYRICA raporun nihai kararını (hangi başlıkla geçerse geçsin; "sonuç ve kanaat", "sonuç", "değerlendirme" ya da metnin sonundaki karar cümlesi) SEN oku ve extracted_data.overall_result içine yaz:
- text: nihai karar paragrafının PDF'deki gerçek metni (bulamıyorsan null).
- status: "uygun" (örn. "kullanımı uygundur") | "uygun_degil" (örn. "uygun değildir", "kusurlar giderilmeden kullanılması uygun değildir") | "uygulanamiyor"; net değilse null.

9. KUSURLAR / BULGULAR (extracted_data.findings)
Kusur açıklamaları, tespitler ve eksiklikler. Her finding: id, system_name, description, source_pages, severity, ambiguous.
NOTLAR bölümündeki açıklamalar (raporun kapsamı, bilgi notları gibi) bulgu değildir; bölüm 5'teki notlar'a yazılır. Notlarda gerçek bir kusur / eksiklik yazıyorsa o kusur ayrıca bulgu olur.
- system_name: kusurun ait olduğu sistemin adı (bölüm 2'deki katalog adıyla); dedektör kusuru → Dedektörler (Duman, Isı, Alev), buton kusuru → Yangın İhbar Butonları, siren / flaşör kusuru → Sesli ve Işıklı Uyarı Cihazları (Siren, Flaşör), santral / panel arızası → Yangın Alarm Santrali (Kontrol Paneli), akü kusuru → Güç Kaynağı ve Aküler gibi. system_name yalnızca bu raporda sistem olarak yazdığın kayıtlardan biri olabilir ("Tesisat Geneli" hariç); genel kontrol listesindeki maddenin kusuru sistemsizdir (null). Sistemin GENELİNE ait kusur (proje yok, bakım kaydı yok gibi) bir sisteme ait değildir: system_name null, ambiguous false. Sistemini GÜVENLE belirleyemediğin kusurda system_name null, ambiguous true.
- severity: rapor kusuru derecelendiriyorsa raporun kendi ifadesiyle yaz (hafif kusur "*" / ağır kusur "**" işaretliyse ya da metinde yazıyorsa "Hafif kusur" / "Ağır kusur"). Rapor derece vermiyorsa null; kendinden derece uydurma.
- description: kusurun PDF'deki metni; kusurun kendi madde kodu / numarası varsa başına yaz. Kusur hangi bina / kat / zon / adreste ise metinde aynen koru. Aynı kusuru tekrarlama; U / UD hücrelerinden tek başına kusur üretme. source_pages gerçek PDF sayfalarıdır.

10. ÇIKARIM SINIRI
equipment_definitions YAPI tarifidir. Cihaz SAYISIYLA BÜYÜYEBİLEN gerçek değerler (identity_value, attributes[].value) equipment_axis="rows" / "columns" iken HER ZAMAN null kalır - Camelot okur. TEK istisna equipment_axis="none".

11. PERİYODİK KONTROLÜ YAPAN KURULUŞ (extracted_data.inspection_body)
Raporu düzenleyen / periyodik kontrolü yapan kuruluşu GERÇEK değerleriyle yaz: name, address, phone, email, website, tax_info, accreditation ve personnel (kontrol eden / onaylayan; role, name, profession, chamber_registry_no, diploma, authorization_no). Yalnızca etiketler olup değerler yoksa o kişiyi ekleme; hiç kişi yoksa []. Raporu İSTEYEN firma (müşteri) bu değildir. PDF'de olmayan bilgiyi uydurma; null bırak.

findings_structure: system_assignment {"required": true, "source": [], "fallback": null}; deduplication {"enabled": true, "duplicate_finding_rule": "same_finding_same_system"}; finding_fields [].
PROMPT;
    }

    private function detectionContract(): string
    {
        return <<<'PROMPT'
ÇIKTI ŞEKLİ (örnek değerler YALNIZCA şekli anlatır; rapordaki gerçek bölümleri ve değerleri yaz):
{
  "template": {
    "template_type": "tesisat_raporu",
    "template_version": "1.0",
    "report_information": {"fields": [{"key": "report_no", "label_patterns": ["Rapor No"]}, {"key": "control_date", "label_patterns": ["Muayene Tarihi"]}, {"key": "validity_date", "label_patterns": ["Gelecek Muayene Tarihi"]}]},
    "facility_or_project_information": {"section_heading_patterns": ["BİNA BİLGİLERİ"], "fields": [{"key": "tehlike_sinifi", "label_patterns": ["Tehlike Sınıfı"]}, {"key": "kat_sayisi", "label_patterns": ["Kat Sayısı"]}]},
    "fire_systems": {
      "systems": [
        {
          "system_name": "Tesisat Geneli",
          "section_heading_patterns": ["GÖZLE KONTROLLER"],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun_degil", "raw": null, "basis": "derived", "evidence": "Butonların önü kapalı: U.D."},
          "equipment_definitions": []
        },
        {
          "system_name": "Yangın Alarm Santrali (Kontrol Paneli)",
          "section_heading_patterns": ["KONTROL PANELİ"],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun", "raw": null, "basis": "derived", "evidence": "Panel fonksiyon testleri: U"},
          "equipment_definitions": [
            {
              "equipment_name": "Santral",
              "instance_structure": {"identity_field": "Marka / Model", "identity_value": "...", "identity_hint": {"pattern": null, "validation": "soft"}, "equipment_axis": "none", "group_width": null, "header_patterns": [], "result_columns": [], "ambiguous": false, "ambiguous_reason": null},
              "attributes": [{"field": "Loop Sayısı", "source_pattern": "Loop Sayısı", "value": "2"}],
              "verdict": {"status": "uygun", "raw": null, "basis": "derived", "evidence": "Panel fonksiyon testleri: U"}
            }
          ]
        },
        {
          "system_name": "Dedektörler (Duman, Isı, Alev)",
          "section_heading_patterns": ["TEST DEĞERLERİ"],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun_degil", "raw": null, "basis": "derived", "evidence": "2 dedektör testte çalışmadı"},
          "equipment_definitions": [
            {
              "equipment_name": "Dedektör",
              "instance_structure": {"identity_field": "Sıra No", "identity_value": null, "identity_hint": {"pattern": null, "validation": "soft"}, "equipment_axis": "rows", "group_width": null, "header_patterns": ["Sıra No", "Adres", "Dedektör Tipi"], "result_columns": [], "ambiguous": false, "ambiguous_reason": null},
              "attributes": [{"field": "Adres", "source_pattern": "Adres", "value": null}, {"field": "Dedektör Tipi", "source_pattern": "Dedektör Tipi", "value": null}, {"field": "Konum", "source_pattern": "Konum", "value": null}],
              "verdict": {"status": null, "raw": null, "basis": "not_stated", "evidence": null}
            }
          ]
        }
      ]
    },
    "overall_result": {"section_heading_patterns": [], "overall_text": {"label_patterns": [], "value_location_patterns": [], "text_boundary_patterns": []}, "overall_status": {"label_patterns": [], "status_patterns": [], "value_location_patterns": []}, "camelot_extraction": {"section_patterns": [], "text_patterns": [], "status_patterns": [], "status_extraction": "dynamic"}},
    "findings_structure": {"system_assignment": {"required": true, "source": [], "fallback": null}, "deduplication": {"enabled": true, "duplicate_finding_rule": "same_finding_same_system"}, "finding_fields": []}
  },
  "extracted_data": {
    "report_category": "yangin_tesisati",
    "extraction_mode": "structured",
    "findings": [
      {"id": "finding-1", "system_name": "Dedektörler (Duman, Isı, Alev)", "description": "B Blok 2. kat 14 ve 15 adresli dedektörler testte alarm vermedi.", "source_pages": [2], "severity": "Ağır kusur", "ambiguous": false},
      {"id": "finding-2", "system_name": null, "description": "Zemin kat butonlarının önü kapalı.", "source_pages": [1], "severity": null, "ambiguous": false}
    ],
    "report_information": [{"key": "report_no", "value": "..."}, {"key": "control_date", "value": "12.07.2025"}, {"key": "validity_date", "value": "12.07.2026"}, {"key": "notlar", "value": null}, {"key": "bina", "value": null}],
    "facility_information": [{"key": "tehlike_sinifi", "value": "Orta"}, {"key": "kat_sayisi", "value": "4"}, {"key": "adresli_mi", "value": "Adresli"}],
    "overall_result": {"text": "...", "status": "uygun_degil"},
    "result_legend": [{"code": "U", "meaning": "Uygun"}, {"code": "U.D.", "meaning": "Uygun Değil"}],
    "inspection_body": {"name": "...", "address": null, "phone": null, "email": null, "website": null, "tax_info": null, "accreditation": null, "personnel": []},
    "equipment_type": {"slug": "yangin-algilama", "evidence": "YANGIN ALGILAMA VE UYARI SİSTEMLERİ PERİYODİK KONTROL RAPORU"},
    "equipment_tag": {"label": null, "value": null, "evidence": null},
    "equipment_specs": []
  }
}

SADECE geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.
PROMPT;
    }

    // Havalandırma ve klima tesisatı talimatı: Bakanlığın standart formatı yok (kriter projesine uygunluk; İşyeri Bina ve
    // Eklentileri Yönetmeliği, İş Ekipmanları Yönetmeliği Ek-III, ölçümler TS EN 12599); firma formatları. Şema ve kurallar elektrik
    // ailesiyle aynı.
    private function ventilationPrompt(): string
    {
        return <<<'PROMPT'
Sen HAVALANDIRMA VE KLİMA TESİSATI periyodik kontrol raporları için çalışan bir TEMPLATE DISCOVERY motorusun.

AMAÇ
PDF'nin gerçek yapısını keşfet ve iki şey üret:
1. template.fire_systems.systems[]: rapordaki HER havalandırma / klima sisteminin YAPISI (equipment_definitions - cihaz ve ölçüm listelerinin rol haritası) ve GENEL UYGUNLUĞU (verdict). Şemadaki ad "fire_systems" olsa da burada havalandırma ve klima tesisatının sistemleri yazılır.
2. extracted_data: rapor bilgileri, tesis bilgileri, genel sonuç, bulgular / kusurlar, sonuç sembollerinin lejantı ve kontrolü yapan kuruluş.

RAPOR NE OLABİLİR
- Havalandırma (ve klima) tesisatı periyodik kontrol raporu: Bakanlığın standart bir formatı yoktur; firmanın kendi formatıdır ("havalandırma tesisatı periyodik kontrol raporu", "havalandırma ve klima tesisatı periyodik kontrol raporu", "mekanik havalandırma kontrol raporu" gibi). Kriter tesisatın projesine uygunluğudur; genelde gözle kontroller, klima santrali / fan kontrolleri ve ölçüm noktası başına hava hızı / debi ölçümleri içerir. Tesisin tamamı için ya da tek bir bina / bölüm için düzenlenebilir.
- Ya da bu tesisatın TEK bir bölümünün ayrı raporu (örn. yalnızca debi ölçüm raporu, yalnızca mutfak davlumbazı raporu, yalnızca klima santrali raporu).

ANLAMINDAN OKU — NUMARAYA / BAŞLIĞA GÜVENME
Bölüm numaraları ve başlıklar firmadan firmaya değişir. Sabit bir başlık arama; bir bölümün ne olduğunu İÇERİĞİNDEN anla (santral kontrolleri mi, fan listesi mi, debi ölçüm tablosu mu, gözle kontrol maddeleri mi, kusur listesi mi, nihai karar cümlesi mi).

TEMEL İLKE — HANGİ DEĞER SENDEN, HANGİSİ CAMELOT'TAN GELİR:
- Rapor uzunluğuyla / ölçüm noktası SAYISIYLA BÜYÜMEYEN her şey (rapor bilgileri, tesis bilgileri, sistem sonuçları, sonuç paragrafı, kusurlar) SABİT / SINIRLIDIR - bunları SEN doğrudan okur, GERÇEK değerleriyle yazarsın.
- Ölçüm noktası / cihaz SAYISIYLA büyüyebilen (5 de olabilir 300 de) her gerçek değer SANA YAZDIRILMAZ - debi / hava hızı ölçüm tabloları, fan / santral / split ünite listeleri gibi tekrarlayan listelerde sen sadece YAPIYI (hangi satır / sütun ne anlama geliyor) tarif edersin, gerçek hücre değerlerini Camelot okur. Noktaları tek tek üretme; HER equipment_definitions kaydı bir ölçüm / cihaz TİPİNİN yapısını tarif eder.
- İstisna: bir grupta GERÇEKTEN TEK bir örnek varsa (örn. tek klima santrali) instance_structure.equipment_axis="none" olur ve o TEK örneğin gerçek değerlerini (identity_value, attributes[].value, verdict) SEN yazarsın.

SABİT ALANLAR (şema gereği)
- extracted_data.report_category: "yangin_tesisati" yaz. Şemada bu rapor için ayrı seçenek olmadığı için "tesisat raporu" anlamında kullanılır; bu raporda başka bir anlamı yoktur.
- extracted_data.extraction_mode: "structured".
- extracted_data.equipment_tag: {"label": null, "value": null, "evidence": null}; extracted_data.equipment_specs: [].
- template.template_version: "1.0".

0. RAPORUN KAPSAMI (template.template_type) — ilk karar
template.template_type'a TAM OLARAK şu iki değerden birini yaz:
- "tesisat_raporu": havalandırma (ve klima) tesisatının genel raporu — tesisat için nihai bir sonuç / kanaat verir. Tek bir bina için düzenlenmiş olsa da o binanın genel raporudur.
- "sistem_raporu": yalnızca TEK bir bölümün ayrı raporu (örn. yalnızca debi ölçümü, yalnızca davlumbaz); sonucu yalnızca o bölüm içindir.
Kararı raporun başlığı ve sonuç bölümü belirler: başlık havalandırma (ve klima) tesisatının periyodik kontrol raporuysa ve sonuç bölümü tesisat için karar veriyorsa "tesisat_raporu" yaz. Raporda birden fazla bölüm varsa "sistem_raporu" yazma. Notlarda raporun kapsamını anlatan cümleler raporu sistem raporu YAPMAZ.
Emin olamıyorsan "tesisat_raporu" yaz.

1. TESİSAT TÜRÜ (extracted_data.equipment_type)
Katalogdan raporun konusu olan tesisatı seç: {"slug": "<katalogdaki slug>", "evidence": "<dayanak: rapor başlığı / kapsamı, PDF'de yazdığı gibi>"}.
Havalandırma ve / veya klima tesisatı raporu ise katalogdaki havalandırma ve klima slug'ını yaz.
Rapor başka bir tesisatın raporuysa (duman tahliye / merdiven basınçlandırma ve yangın havalandırması → yangın tesisatı; doğalgaz / boru tesisatı; elektrik iç tesisatı; kazan / basınçlı kap gibi) DİĞER TESİSAT TÜRLERİ listesinden o tesisatın slug'ını yaz; listede de yoksa ya da tek bir makinenin raporuysa slug: null yaz (tahmin etme).
Raporun konusu başlığından ve kapsamından anlaşılır: başka bir tesisatın raporunda havalandırmadan söz edilmesi onu havalandırma raporu yapmaz.

2. SİSTEMLER (template.fire_systems.systems[].system_name)
Raporun her bölümünü katalogdaki sistemlerden ANLAMCA karşılık gelen adla, katalogda yazdığı gibi AYNEN yaz. Karşılıklar (raporun kendi adı ne olursa olsun, içeriğe bak):
- Klima Santralleri: klima santrali / AHU / hava hazırlama ünitesi / taze hava santrali / ısı geri kazanım cihazı — fan hücresi, ısıtma / soğutma bataryaları, nemlendirici, ısı geri kazanım, drenaj, kontrol ve bakım kapakları.
- Fanlar ve Aspiratörler: vantilatörler, aspiratörler, egzoz / çatı fanları, kanal tipi fanlar, otopark jet fanları — motor, kayış-kasnak, rulman, titreşim, çekilen akım.
- Hava Kanalları ve Menfezler: hava kanalları, izolasyon, flanş / conta, damperler, menfezler / difüzörler, esnek bağlantılar — tıkanıklık, delik / ezilme, toz birikimi, sızdırmazlık.
- Filtreler: filtre sınıfı, doluluk / kirlilik, fark basıncı, sızdırmazlık, değişim kayıtları.
- Debi ve Hava Hızı Ölçümleri: ölçüm noktası / mahal / menfez başına hava hızı, debi, sıcaklık, nem, fark basıncı ölçümleri ve projedeki değerle karşılaştırma.
- Lokal Havalandırma ve Davlumbazlar: mutfak davlumbazı, proses / kaynak / boya emişleri, lokal aspirasyon noktaları.
- Split ve VRF Klimalar: split, multi-split, VRF iç ve dış üniteleri.
- Proje Bilgileri: tesisatın projesi ve projeye uygunluğu bölümü (proje var mı, tesisat projeye uygun mu).
Raporda bir bölümün katalogda karşılığı yoksa rapordaki adını yaz; katalogdaki bir sisteme zorla bağlama.
SİSTEM NE ZAMAN YAZILIR: bir sistem YALNIZCA raporda o sisteme ait AYRI bir bölüm, form ya da tablo varsa yazılır — örn. klima santrali kontrol bölümü, fan listesi, debi ölçüm tablosu, davlumbaz bölümü, split ünite listesi, proje uygunluk bölümü. Raporun GENEL KONTROL LİSTESİNDEKİ tek tek maddeler ("kanallarda tıkanıklık var mı", "fan kayışları yıpranmış mı", "filtreler tıkalı mı" gibi) tesisatın KENDİ kontrolleridir: bu maddelerden sistem çıkarma, maddeyi bir sisteme bağlama. Maddede bir sistemin adının geçmesi o sistemin ayrıca kontrol edildiği anlamına gelmez.
Genel kontrol listesinin tamamı için TEK bir kayıt yaz: system_name "Tesisat Geneli", verdict listenin sonucu, equipment_definitions []. Bu kayıt sistem değildir (katalogda yoktur, sisteme çevrilmez); şema en az bir kayıt istediği için vardır. Raporda ayrı sistem bölümü hiç yoksa fire_systems.systems'ta yalnızca bu kayıt olur. Genel listedeki uygun olmayan maddeler sistemsiz bulgu olur (system_name null).
Raporun TESİS BİLGİLERİ bölümü (kullanım amacı, havalandırma tipi, mahal / bölüm, alan, kişi sayısı, ortam / dış hava koşulları gibi) bir sistem değildir: sistem kaydı açma, alanlarını bölüm 6'ya göre facility_information'a yaz. Bu alanlar bir cihazın ya da ölçüm noktasının bilgisi de değildir; attributes'a YAZMA.
Aynı sistem birden fazla formda / sayfada tekrar ediyorsa (örn. her kat için ayrı ölçüm tablosu) TEK sistem kaydı yaz; devam patternlerini section_detection'da belirt.
Her sistem kaydı: system_name, section_heading_patterns, section_detection (başlangıç, devam ve bitiş patternleri), verdict (bölüm 3), equipment_definitions (bölüm 4).

3. SİSTEM UYGUNLUĞU (verdict) — GERÇEK DEĞER, PATTERN DEĞİL
verdict: {"status": "uygun" | "uygun_degil" | "uygulanamiyor" | null, "raw": "<raporda GERÇEKTEN yazan sonuç ifadesi, yoksa null>", "basis": "explicit" | "derived" | "not_stated", "evidence": "<kararın dayanağı olan kısa alıntı, yoksa null>"}
- basis="explicit": raporda o bölüm için AÇIK bir sonuç yazıyorsa. raw o ifadeyi aynen taşır.
- basis="derived": açık bir bölüm sonucu yoksa, o bölümün ölçüm sonuçlarından ya da kontrol maddelerinden EN AZ BİRİ "uygun değil" ise (ölçülen debi projedeki değerin altında, fan çalışmıyor, filtre tıkalı) ya da o sisteme ait bir kusur varsa status="uygun_degil"; hepsi uygun ve o sisteme ait kusur yoksa status="uygun". Maddeleri ÇIKTIYA YAZMA - sadece bu kararı vermek için oku. evidence'a kararı belirleyen ölçümü / maddeyi / kusuru kısaca yaz.
- basis="not_stated": ne açık bir sonuç ne de değerlendirilebilir bir madde varsa status: null, raw: null.
status SADECE bu 3 değerden biri ya da null olabilir.

4. CİHAZ VE ÖLÇÜM LİSTELERİ (equipment_definitions)
Ölçüm tabloları ve cihaz listeleri raporda okunur ve ait oldukları sistemin equipment_definitions listesine yazılır; her kayıt bir TİPİN yapısını tarif eder, o tipten kaç örnek olursa olsun TEK kayıt.
- Örnek tipler: "Ölçüm Noktası" (mahal / menfez, hava hızı, menfez alanı, debi, sıcaklık, nem, projedeki debi), "Fan" (no / yer, tip, motor gücü, çekilen akım), "Klima Santrali" (tek santral: equipment_axis "none"; marka, model, kapasite gerçek değerleriyle), "Split Ünite" (iç / dış ünite, yer, kapasite), "Davlumbaz".
- Kontrol listesi olan sistemlerde (Proje Bilgileri gibi) equipment_definitions: [].
- Tekrarlayan tablo (birden fazla nokta / cihaz): equipment_axis "rows" ya da "columns"; gerçek hücreleri Camelot okur, identity_value ve attributes[].value null kalır, verdict {"status": null, "raw": null, "basis": "not_stated", "evidence": null}.
- Raporda gerçekten TEK bir örnek varsa: equipment_axis "none"; identity_value, attributes gerçek değerleriyle, verdict o örneğin sonucu.
- Bütün değerleri boş ya da "-" olan satırı / bloğu (raporda olmayan nokta) YAZMA.
- Tablo raporda nasılsa öyle gösterilecek: tablonun BÜTÜN sütunlarını PDF'deki soldan sağa sırasıyla attributes'a yaz (kimlik sütunu ve satırın sonucunu veren Sonuç sütunu hariç). Bir sütunun bütün değerleri "-" ya da boş olsa bile o sütunu yaz.
- Tabloda sıra no sütunu varsa identity_field odur; ölçüm noktası / mahal / cihaz adı sütunu attributes'a yazılır.

4a. instance_structure — TEKRAR YAPISI
- identity_field: her örneği ayırt eden satır / sütun etiketi (örn. "Sıra No", "No"). ZORUNLU çapa - parser gerçek tabloyu bu etiketi Camelot verisinde arayarak bulacak.
- identity_value: SADECE equipment_axis="none" iken o TEK örneğin gerçek no'su / adı; aksi halde null.
- identity_hint: {"pattern": null, "validation": "soft"}.
- equipment_axis: "rows" (her SATIR bir örnek; ölçüm tablolarında yaygın), "columns" (her SÜTUN bir örnek), "none" (tek örnek).
- group_width: SADECE "columns" iken bir bloktaki örnek sütunu sayısı; diğerlerinde null.
- header_patterns: tablonun başladığını gösteren gerçek etiket metinleri (çok satırlı başlıkta her satırın etiketini ayrı yaz).
- result_columns: yalnızca tek bir sonucu 2-3 sütuna bölen işaret setinde (örn. "U | U.D. | N.U." ya da "Evet | Hayır") {"header_pattern", "kind": "fixed_value", "value"} ya da sonuç YAZMAYAN serbest metin not / açıklama sütununda {"header_pattern", "kind": "note", "value": null}; yoksa []. Satırın sonucunu "Uygun / Uygun Değil" diye yazan TEK "Sonuç" sütunu result_columns'a YAZILMAZ (note da değildir): parser satırın sonucunu o sütundan kendisi okur. Ölçüm değeri sütunları (m/s, m³/h, °C, %, Pa) result_columns DEĞİLDİR; onlar attributes'tır.
- ambiguous: tablonun eksenini, kimlik alanını ya da sınırlarını GÜVENLE belirleyemediysen true ve ambiguous_reason'a nedenini yaz.

4b. attributes: {"field": "<adı>", "source_pattern": "<PDF'de GERÇEKTEN gördüğün etiket metni>", "value": null} (value yalnızca "none" iken gerçek değerle dolar). Birimi etiketteki gibi koru. Numaralı kontrol maddesi gibi görünen satırları attributes'a EKLEME.
Tablo sütunlarında field, PDF'deki sütun başlığının AYNISIDIR (kullanıcıya bu başlıkla gösterilir; kendi adını verme, kısaltma ya da çevirme). Başlık PDF metninde birden fazla satıra bölünmüşse tek satırda birleştir.

5. RAPOR BİLGİLERİ
template.report_information.fields içine şu key'leri ve PDF'deki gerçek etiketlerini yaz; extracted_data.report_information içine aynı key'lerle PDF'den okunan GERÇEK değerleri yaz (yoksa value: null, key'i atlama):
report_no, company_title (raporu isteyen firma / müşteri), address (periyodik kontrol adresi), report_date, control_date (kontrolün yapıldığı tarih; rapor tarihiyle aynı alan değildir), validity_date (bir sonraki periyodik kontrol tarihi), notlar (raporun NOTLAR bölümünün metni, PDF'de yazdığı gibi; birden fazla not varsa her biri ayrı satırda; bölüm yoksa ya da içinde yalnızca "-" yazıyorsa null), bina (rapor tesisin tamamı için değil de belirli bir bina / blok / bölüm için düzenlendiyse onun adı, PDF'de yazdığı gibi; tesis geneli içinse ya da bilgi yoksa null; müşteri firma adı, adres ya da tek bir cihazın / mahallin yeri bina değildir. Raporun kapsadığı yer / "ekipmanın bulunduğu yer" tek bir bina / blok adıysa onu bina olarak yaz; "Genel", "Tesis geneli" yazıyorsa null).

6. TESİS BİLGİLERİ (template.facility_or_project_information, extracted_data.facility_information)
Tesis / bina bilgileri bölümünü bul; section_heading_patterns'a gerçek başlıklarını yaz. Bu bilgiler kullanıcıya "Tesis Özellikleri" kartında raporun kendi etiketleriyle gösterilir: her alanın label_patterns'ına önce PDF'deki etiketi AYNEN yaz (sondaki ":" hariç). Notlardaki cümleleri tesis bilgisine yazma; onlar notlar'a gider. Raporda GERÇEKTEN bulunan alanları şu key'lerle yaz (seçmeli alanlarda işaretli olan seçenek):
kullanim_amaci, havalandirma_tipi (doğal / mekanik / genel / lokal), mahal_bolum, toplam_alan, hacim, kisi_sayisi, ortam_sicakligi, ortam_nemi, dis_hava_kosullari, proje_var_mi, kontrol_nedeni (periyodik / ilk kontrol), son_kontrol_tarihi.
Listede olmayan önemli bir tesis bilgisi varsa kendi kısa key'iyle ekleyebilirsin. Raporda olmayan alanı YAZMA; işaretlenmemiş seçeneği seçilmiş sayma.

7. LEJANT (extracted_data.result_legend)
Raporun sonuç kısaltmalarını açıklayan metni GERÇEKTEN YAZDIĞI GİBİ {"code", "meaning"} olarak kopyala (örn. {"code": "U.D.", "meaning": "Uygun Değil"}); yoksa [].

8. GENEL SONUÇ VE KANAAT
template.overall_result içine yalnızca pattern / konum tarifini yaz. AYRICA raporun nihai kararını (hangi başlıkla geçerse geçsin; "sonuç ve kanaat", "sonuç", "değerlendirme" ya da metnin sonundaki karar cümlesi) SEN oku ve extracted_data.overall_result içine yaz:
- text: nihai karar paragrafının PDF'deki gerçek metni (bulamıyorsan null).
- status: "uygun" (örn. "kullanımı uygundur") | "uygun_degil" (örn. "uygun değildir", "eksikler giderilmeden kullanılması uygun değildir") | "uygulanamiyor"; net değilse null.

9. KUSURLAR / BULGULAR (extracted_data.findings)
Kusur açıklamaları, tespitler ve eksiklikler. Her finding: id, system_name, description, source_pages, severity, ambiguous.
NOTLAR bölümündeki açıklamalar (raporun kapsamı, ölçüm koşulları gibi) bulgu değildir; bölüm 5'teki notlar'a yazılır. Notlarda gerçek bir kusur / eksiklik yazıyorsa o kusur ayrıca bulgu olur.
- system_name: kusurun ait olduğu sistemin adı (bölüm 2'deki katalog adıyla); santral kusuru → Klima Santralleri, fan / motor / kayış kusuru → Fanlar ve Aspiratörler, kanal / menfez / damper kusuru → Hava Kanalları ve Menfezler, filtre kusuru → Filtreler, düşük debi → Debi ve Hava Hızı Ölçümleri gibi. system_name yalnızca bu raporda sistem olarak yazdığın kayıtlardan biri olabilir ("Tesisat Geneli" hariç); genel kontrol listesindeki maddenin kusuru sistemsizdir (null). Tesisatın GENELİNE ait kusur (proje yok, bakım kaydı yok gibi) bir sisteme ait değildir: system_name null, ambiguous false. Sistemini GÜVENLE belirleyemediğin kusurda system_name null, ambiguous true.
- severity: rapor kusuru derecelendiriyorsa raporun kendi ifadesiyle yaz (hafif / ağır kusur gibi). Rapor derece vermiyorsa null; kendinden derece uydurma.
- description: kusurun PDF'deki metni; kusurun kendi madde kodu / numarası varsa başına yaz. Kusur hangi mahal / cihazda ise metinde aynen koru. Aynı kusuru tekrarlama; U / UD hücrelerinden tek başına kusur üretme. source_pages gerçek PDF sayfalarıdır.

10. ÇIKARIM SINIRI
equipment_definitions YAPI tarifidir. Ölçüm noktası / cihaz SAYISIYLA BÜYÜYEBİLEN gerçek değerler (identity_value, attributes[].value) equipment_axis="rows" / "columns" iken HER ZAMAN null kalır - Camelot okur. TEK istisna equipment_axis="none".

11. PERİYODİK KONTROLÜ YAPAN KURULUŞ (extracted_data.inspection_body)
Raporu düzenleyen / periyodik kontrolü yapan kuruluşu GERÇEK değerleriyle yaz: name, address, phone, email, website, tax_info, accreditation ve personnel (kontrol eden / onaylayan; role, name, profession, chamber_registry_no, diploma, authorization_no). Yalnızca etiketler olup değerler yoksa o kişiyi ekleme; hiç kişi yoksa []. Raporu İSTEYEN firma (müşteri) bu değildir. PDF'de olmayan bilgiyi uydurma; null bırak.

findings_structure: system_assignment {"required": true, "source": [], "fallback": null}; deduplication {"enabled": true, "duplicate_finding_rule": "same_finding_same_system"}; finding_fields [].
PROMPT;
    }

    private function ventilationContract(): string
    {
        return <<<'PROMPT'
ÇIKTI ŞEKLİ (örnek değerler YALNIZCA şekli anlatır; rapordaki gerçek bölümleri ve değerleri yaz):
{
  "template": {
    "template_type": "tesisat_raporu",
    "template_version": "1.0",
    "report_information": {"fields": [{"key": "report_no", "label_patterns": ["Rapor No"]}, {"key": "control_date", "label_patterns": ["Muayene Tarihi"]}, {"key": "validity_date", "label_patterns": ["Gelecek Muayene Tarihi"]}]},
    "facility_or_project_information": {"section_heading_patterns": ["GENEL BİLGİLER"], "fields": [{"key": "kullanim_amaci", "label_patterns": ["Tesisin Kullanım Amacı"]}, {"key": "havalandirma_tipi", "label_patterns": ["Havalandırma Tipi"]}]},
    "fire_systems": {
      "systems": [
        {
          "system_name": "Tesisat Geneli",
          "section_heading_patterns": ["KONTROLLER"],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun_degil", "raw": null, "basis": "derived", "evidence": "Kanallarda toz birikimi: U.D."},
          "equipment_definitions": []
        },
        {
          "system_name": "Debi ve Hava Hızı Ölçümleri",
          "section_heading_patterns": ["DEBİ ÖLÇÜM SONUÇLARI"],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "verdict": {"status": "uygun_degil", "raw": null, "basis": "derived", "evidence": "Yemekhane emiş debisi projedeki değerin altında"},
          "equipment_definitions": [
            {
              "equipment_name": "Ölçüm Noktası",
              "instance_structure": {"identity_field": "Sıra No", "identity_value": null, "identity_hint": {"pattern": null, "validation": "soft"}, "equipment_axis": "rows", "group_width": null, "header_patterns": ["Sıra No", "Ölçüm Noktası"], "result_columns": [], "ambiguous": false, "ambiguous_reason": null},
              "attributes": [{"field": "Ölçüm Noktası", "source_pattern": "Ölçüm Noktası", "value": null}, {"field": "Hava Hızı (m/s)", "source_pattern": "Hava Hızı (m/s)", "value": null}, {"field": "Menfez Alanı (m²)", "source_pattern": "Menfez Alanı (m²)", "value": null}, {"field": "Debi (m³/h)", "source_pattern": "Debi (m³/h)", "value": null}],
              "verdict": {"status": null, "raw": null, "basis": "not_stated", "evidence": null}
            }
          ]
        }
      ]
    },
    "overall_result": {"section_heading_patterns": [], "overall_text": {"label_patterns": [], "value_location_patterns": [], "text_boundary_patterns": []}, "overall_status": {"label_patterns": [], "status_patterns": [], "value_location_patterns": []}, "camelot_extraction": {"section_patterns": [], "text_patterns": [], "status_patterns": [], "status_extraction": "dynamic"}},
    "findings_structure": {"system_assignment": {"required": true, "source": [], "fallback": null}, "deduplication": {"enabled": true, "duplicate_finding_rule": "same_finding_same_system"}, "finding_fields": []}
  },
  "extracted_data": {
    "report_category": "yangin_tesisati",
    "extraction_mode": "structured",
    "findings": [
      {"id": "finding-1", "system_name": "Debi ve Hava Hızı Ölçümleri", "description": "Yemekhane emiş debisi projede öngörülen değerin altındadır.", "source_pages": [2], "severity": null, "ambiguous": false},
      {"id": "finding-2", "system_name": null, "description": "Kanallarda toz birikimi var; kanal temizliği yapılmalıdır.", "source_pages": [1], "severity": null, "ambiguous": false}
    ],
    "report_information": [{"key": "report_no", "value": "..."}, {"key": "control_date", "value": "12.07.2025"}, {"key": "validity_date", "value": "12.07.2026"}, {"key": "notlar", "value": null}, {"key": "bina", "value": null}],
    "facility_information": [{"key": "kullanim_amaci", "value": "Ofis"}, {"key": "havalandirma_tipi", "value": "Mekanik"}],
    "overall_result": {"text": "...", "status": "uygun_degil"},
    "result_legend": [{"code": "U", "meaning": "Uygun"}, {"code": "U.D.", "meaning": "Uygun Değil"}],
    "inspection_body": {"name": "...", "address": null, "phone": null, "email": null, "website": null, "tax_info": null, "accreditation": null, "personnel": []},
    "equipment_type": {"slug": "havalandirma-klima", "evidence": "HAVALANDIRMA TESİSATI PERİYODİK KONTROL RAPORU"},
    "equipment_tag": {"label": null, "value": null, "evidence": null},
    "equipment_specs": []
  }
}

SADECE geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.
PROMPT;
    }

    /**
     * Elektrik ailesinin sonradan eklenen türleri (paratoner, akümülatör, trafo merkezi) için ortak talimat: kurallar havalandırma
     * / algılama talimatıyla aynı; türe özgü kısımlar ($p) — konu, rapor türleri, sistem karşılıkları, liste tipleri, tesis bilgisi
     * key'leri, kusur eşleştirmesi; isteğe bağlı scope_rule (kapsam kararına türe özgü ek, yalnızca trafo merkezi).
     */
    private function familyPrompt(array $p): string
    {
        return "Sen {$p['subject']} periyodik kontrol raporları için çalışan bir TEMPLATE DISCOVERY motorusun.\n\n"
            . "AMAÇ\nPDF'nin gerçek yapısını keşfet ve iki şey üret:\n"
            . "1. template.fire_systems.systems[]: rapordaki HER sistemin YAPISI (equipment_definitions - cihaz ve ölçüm listelerinin rol haritası) ve GENEL UYGUNLUĞU (verdict). Şemadaki ad \"fire_systems\" olsa da burada bu tesisatın sistemleri yazılır.\n"
            . "2. extracted_data: rapor bilgileri, tesis bilgileri, genel sonuç, bulgular / kusurlar, sonuç sembollerinin lejantı ve kontrolü yapan kuruluş.\n\n"
            . "RAPOR NE OLABİLİR\n{$p['report_kinds']}\n\n"
            . <<<'PROMPT'
ANLAMINDAN OKU — NUMARAYA / BAŞLIĞA GÜVENME
Bölüm numaraları ve başlıklar firmadan firmaya değişir; bakanlık formatının numaralandırması ya da adlandırması da değişebilir. Sabit bir başlık arama; bir bölümün ne olduğunu İÇERİĞİNDEN anla (kontrol maddeleri mi, ölçüm tablosu mu, cihaz listesi mi, kusur listesi mi, nihai karar cümlesi mi).

TEMEL İLKE — HANGİ DEĞER SENDEN, HANGİSİ CAMELOT'TAN GELİR:
- Rapor uzunluğuyla / cihaz ya da ölçüm noktası SAYISIYLA BÜYÜMEYEN her şey (rapor bilgileri, tesis bilgileri, sistem sonuçları, sonuç paragrafı, kusurlar) SABİT / SINIRLIDIR - bunları SEN doğrudan okur, GERÇEK değerleriyle yazarsın.
- Cihaz / ölçüm noktası SAYISIYLA büyüyebilen her gerçek değer SANA YAZDIRILMAZ - tekrarlayan listelerde (ve her cihaz için tekrarlanan formlarda) sen sadece YAPIYI tarif edersin, gerçek değerleri Camelot okur. Örnekleri tek tek üretme; HER equipment_definitions kaydı bir tipin yapısını tarif eder.
- İstisna: bir grupta GERÇEKTEN TEK bir örnek varsa instance_structure.equipment_axis="none" olur ve o TEK örneğin gerçek değerlerini (identity_value, attributes[].value, verdict) SEN yazarsın.

SABİT ALANLAR (şema gereği)
- extracted_data.report_category: "yangin_tesisati" yaz. Şemada bu rapor için ayrı seçenek olmadığı için "tesisat raporu" anlamında kullanılır; bu raporda başka bir anlamı yoktur.
- extracted_data.extraction_mode: "structured".
- extracted_data.equipment_tag: {"label": null, "value": null, "evidence": null}; extracted_data.equipment_specs: [].
- template.template_version: "1.0".

0. RAPORUN KAPSAMI (template.template_type) — ilk karar
template.template_type'a TAM OLARAK şu iki değerden birini yaz:
- "tesisat_raporu": tesisatın genel raporu — tesisat için nihai bir sonuç / kanaat verir. Tek bir bina / tek bir tesisat birimi için düzenlenmiş olsa da onun genel raporudur.
- "sistem_raporu": yalnızca TEK bir bölümün ayrı raporu (örn. yalnızca bir ölçüm raporu); sonucu yalnızca o bölüm içindir.
Kararı raporun başlığı ve sonuç bölümü belirler: başlık bu tesisatın periyodik kontrol raporuysa ve sonuç bölümü tesisat için karar veriyorsa "tesisat_raporu" yaz. Raporda birden fazla bölüm varsa "sistem_raporu" yazma. Notlarda raporun kapsamını anlatan cümleler raporu sistem raporu YAPMAZ.
Emin olamıyorsan "tesisat_raporu" yaz.

PROMPT
            . ($p['scope_rule'] ?? '')
            . "1. TESİSAT TÜRÜ (extracted_data.equipment_type)\n"
            . "Katalogdan raporun konusu olan tesisatı seç: {\"slug\": \"<katalogdaki slug>\", \"evidence\": \"<dayanak: rapor başlığı / kapsamı, PDF'de yazdığı gibi>\"}.\n"
            . "{$p['type_rule']}\n"
            . "Rapor başka bir tesisatın raporuysa DİĞER TESİSAT TÜRLERİ listesinden o tesisatın slug'ını yaz; listede de yoksa ya da tek bir makinenin raporuysa slug: null yaz (tahmin etme). Raporun konusu başlığından ve kapsamından anlaşılır: başka bir tesisatın raporunda bu tesisattan söz edilmesi onu bu tesisatın raporu yapmaz.\n\n"
            . "2. SİSTEMLER (template.fire_systems.systems[].system_name)\n"
            . "Raporun her bölümünü katalogdaki sistemlerden ANLAMCA karşılık gelen adla, katalogda yazdığı gibi AYNEN yaz. Karşılıklar (raporun kendi adı ne olursa olsun, içeriğe bak):\n{$p['systems']}\n"
            . "Raporda bir bölümün katalogda karşılığı yoksa rapordaki adını yaz; katalogdaki bir sisteme zorla bağlama.\n"
            . "SİSTEM NE ZAMAN YAZILIR: bir sistem YALNIZCA raporda o sisteme ait AYRI bir bölüm, kontrol grubu, form ya da tablo varsa yazılır — {$p['when_system']}. Raporun GENEL KONTROL LİSTESİNDEKİ tek tek maddeler tesisatın KENDİ kontrolleridir: bu maddelerden sistem çıkarma, maddeyi bir sisteme bağlama. Maddede bir sistemin adının geçmesi o sistemin ayrıca kontrol edildiği anlamına gelmez.\n"
            . "Genel kontrol listesinin tamamı için TEK bir kayıt yaz: system_name \"Tesisat Geneli\", verdict listenin sonucu, equipment_definitions []. Bu kayıt sistem değildir (katalogda yoktur, sisteme çevrilmez); şema en az bir kayıt istediği için vardır. Raporda ayrı sistem bölümü hiç yoksa fire_systems.systems'ta yalnızca bu kayıt olur. Genel listedeki uygun olmayan maddeler sistemsiz bulgu olur (system_name null).\n"
            . "Raporun TESİS BİLGİLERİ bölümü bir sistem değildir: sistem kaydı açma, alanlarını bölüm 6'ya göre facility_information'a yaz. Bu alanlar bir cihazın ya da ölçüm noktasının bilgisi de değildir; attributes'a YAZMA.\n"
            . "Aynı sistem birden fazla formda / sayfada tekrar ediyorsa (örn. her cihaz için ayrı sayfa) TEK sistem kaydı yaz; devam patternlerini section_detection'da belirt.\n"
            . "Her sistem kaydı: system_name, section_heading_patterns, section_detection (başlangıç, devam ve bitiş patternleri), verdict (bölüm 3), equipment_definitions (bölüm 4).\n\n"
            . <<<'PROMPT'
3. SİSTEM UYGUNLUĞU (verdict) — GERÇEK DEĞER, PATTERN DEĞİL
verdict: {"status": "uygun" | "uygun_degil" | "uygulanamiyor" | null, "raw": "<raporda GERÇEKTEN yazan sonuç ifadesi, yoksa null>", "basis": "explicit" | "derived" | "not_stated", "evidence": "<kararın dayanağı olan kısa alıntı, yoksa null>"}
- basis="explicit": raporda o bölüm için AÇIK bir sonuç yazıyorsa. raw o ifadeyi aynen taşır.
- basis="derived": açık bir bölüm sonucu yoksa, o bölümün ölçüm sonuçlarından ya da kontrol maddelerinden EN AZ BİRİ "uygun değil" ise ya da o sisteme ait bir kusur varsa status="uygun_degil"; değerlendirilen hepsi uygun ve o sisteme ait kusur yoksa status="uygun". Bütün maddeleri "uygulaması yok" olan bölümde status="uygulanamiyor". Maddeleri ÇIKTIYA YAZMA - sadece bu kararı vermek için oku. evidence'a kararı belirleyen ölçümü / maddeyi / kusuru kısaca yaz.
- basis="not_stated": ne açık bir sonuç ne de değerlendirilebilir bir madde varsa status: null, raw: null.
status SADECE bu 3 değerden biri ya da null olabilir.

PROMPT
            . "4. CİHAZ VE ÖLÇÜM LİSTELERİ (equipment_definitions)\n"
            . "Cihaz listeleri ve ölçüm tabloları raporda okunur ve ait oldukları sistemin equipment_definitions listesine yazılır; her kayıt bir TİPİN yapısını tarif eder, o tipten kaç örnek olursa olsun TEK kayıt.\n"
            . "- Örnek tipler: {$p['table_types']}\n"
            . <<<'PROMPT'
- Kontrol listesi olan sistemlerde equipment_definitions: [].
- Tekrarlayan tablo (birden fazla nokta / cihaz): equipment_axis "rows" ya da "columns"; gerçek hücreleri Camelot okur, identity_value ve attributes[].value null kalır, verdict {"status": null, "raw": null, "basis": "not_stated", "evidence": null}.
- Her cihaz için ayrı bir form / sayfa tekrarlanıyorsa (her formun başında cihazın adı / seri no'su, altında bilgileri): bu da tekrarlayan yapıdır; header_patterns'a formun başındaki gerçek etiketi yaz, identity_field o etikettir.
- Raporda gerçekten TEK bir örnek varsa: equipment_axis "none"; identity_value, attributes gerçek değerleriyle, verdict o örneğin sonucu.
- Bütün değerleri boş ya da "-" olan satırı / bloğu (raporda olmayan cihaz) YAZMA.
- Tablo raporda nasılsa öyle gösterilecek: tablonun BÜTÜN sütunlarını PDF'deki soldan sağa sırasıyla attributes'a yaz (kimlik sütunu ve satırın sonucunu veren Sonuç sütunu hariç). Bir sütunun bütün değerleri "-" ya da boş olsa bile o sütunu yaz.
- Tabloda sıra no sütunu varsa identity_field odur.

4a. instance_structure — TEKRAR YAPISI
- identity_field: her örneği ayırt eden satır / sütun etiketi (örn. "Sıra No", "No", "Seri No"). ZORUNLU çapa - parser gerçek tabloyu bu etiketi Camelot verisinde arayarak bulacak.
- identity_value: SADECE equipment_axis="none" iken o TEK örneğin gerçek no'su / adı; aksi halde null.
- identity_hint: {"pattern": null, "validation": "soft"}.
- equipment_axis: "rows" (her SATIR bir örnek), "columns" (her SÜTUN bir örnek), "none" (tek örnek).
- group_width: SADECE "columns" iken bir bloktaki örnek sütunu sayısı; diğerlerinde null.
- header_patterns: tablonun başladığını gösteren gerçek etiket metinleri (çok satırlı başlıkta her satırın etiketini ayrı yaz).
- result_columns: yalnızca tek bir sonucu 2-3 sütuna bölen işaret setinde (örn. "U | U.D. | U.Y.") {"header_pattern", "kind": "fixed_value", "value"} ya da sonuç YAZMAYAN serbest metin not sütununda {"header_pattern", "kind": "note", "value": null}; yoksa []. Satırın sonucunu "Uygun / Uygun Değil" diye yazan TEK "Sonuç" sütunu result_columns'a YAZILMAZ (note da değildir): parser satırın sonucunu o sütundan kendisi okur. Ölçüm değeri sütunları result_columns DEĞİLDİR; onlar attributes'tır.
- ambiguous: tablonun eksenini, kimlik alanını ya da sınırlarını GÜVENLE belirleyemediysen true ve ambiguous_reason'a nedenini yaz.

4b. attributes: {"field": "<adı>", "source_pattern": "<PDF'de GERÇEKTEN gördüğün etiket metni>", "value": null} (value yalnızca "none" iken gerçek değerle dolar). Birimi etiketteki gibi koru. Numaralı kontrol maddesi gibi görünen satırları attributes'a EKLEME.
Tablo sütunlarında field, PDF'deki sütun başlığının AYNISIDIR (kullanıcıya bu başlıkla gösterilir; kendi adını verme, kısaltma ya da çevirme). Başlık PDF metninde birden fazla satıra bölünmüşse tek satırda birleştir.

5. RAPOR BİLGİLERİ
template.report_information.fields içine şu key'leri ve PDF'deki gerçek etiketlerini yaz; extracted_data.report_information içine aynı key'lerle PDF'den okunan GERÇEK değerleri yaz (yoksa value: null, key'i atlama):
report_no, company_title (raporu isteyen firma / müşteri), address (periyodik kontrol adresi), report_date, control_date (kontrolün yapıldığı tarih; rapor tarihiyle aynı alan değildir), validity_date (bir sonraki periyodik kontrol tarihi), notlar (raporun NOTLAR bölümünün metni, PDF'de yazdığı gibi; birden fazla not varsa her biri ayrı satırda; bölüm yoksa ya da içinde yalnızca "-" yazıyorsa null), bina (rapor tesisin tamamı için değil de belirli bir bina / blok / bölüm için düzenlendiyse onun adı, PDF'de yazdığı gibi; tesis geneli içinse ya da bilgi yoksa null; müşteri firma adı ya da adres bina değildir.
PROMPT
            . " {$p['bina_rule']}).\n\n"
            . "6. TESİS BİLGİLERİ (template.facility_or_project_information, extracted_data.facility_information)\n"
            . "Tesis bilgileri bölümünü bul; section_heading_patterns'a gerçek başlıklarını yaz. Bu bilgiler kullanıcıya \"Tesis Özellikleri\" kartında raporun kendi etiketleriyle gösterilir: her alanın label_patterns'ına önce PDF'deki etiketi AYNEN yaz (sondaki \":\" hariç). Notlardaki cümleleri tesis bilgisine yazma; onlar notlar'a gider. Raporda GERÇEKTEN bulunan alanları şu key'lerle yaz (seçmeli alanlarda işaretli olan seçenek):\n{$p['facility_keys']}\n"
            . "Listede olmayan önemli bir tesis bilgisi varsa kendi kısa key'iyle ekleyebilirsin. Raporda olmayan alanı YAZMA; işaretlenmemiş seçeneği seçilmiş sayma.\n\n"
            . <<<'PROMPT'
7. LEJANT (extracted_data.result_legend)
Raporun sonuç kısaltmalarını açıklayan metni GERÇEKTEN YAZDIĞI GİBİ {"code", "meaning"} olarak kopyala (örn. {"code": "U.D.", "meaning": "Uygun Değil"}, {"code": "U.Y.", "meaning": "Uygulaması Yok"}); yoksa [].

8. GENEL SONUÇ VE KANAAT
template.overall_result içine yalnızca pattern / konum tarifini yaz. AYRICA raporun nihai kararını (hangi başlıkla geçerse geçsin; "sonuç ve kanaat", "sonuç" ya da metnin sonundaki karar cümlesi) SEN oku ve extracted_data.overall_result içine yaz:
- text: nihai karar paragrafının PDF'deki gerçek metni (bulamıyorsan null).
- status: "uygun" (örn. "kullanılması uygundur") | "uygun_degil" (örn. "uygun değildir", "eksiklikler giderilene kadar kullanımı uygun değildir") | "uygulanamiyor"; net değilse null.
Formda hem "uygundur" hem "uygun değildir" cümlesi hazır metin olarak birlikte basılıysa: kusur / eksiklik bölümü boşsa ve uygun olmayan madde yoksa "uygun", kusur ya da uygun olmayan madde varsa "uygun_degil" yaz; text'e geçerli olan cümleyi yaz.

9. KUSURLAR / BULGULAR (extracted_data.findings)
Kusur açıklamaları, tespitler ve eksiklikler. Her finding: id, system_name, description, source_pages, severity, ambiguous.
NOTLAR bölümündeki açıklamalar (raporun kapsamı, bilgi notları gibi) bulgu değildir; bölüm 5'teki notlar'a yazılır. Notlarda gerçek bir kusur / eksiklik yazıyorsa o kusur ayrıca bulgu olur.
PROMPT
            . "\n- system_name: kusurun ait olduğu sistemin adı (bölüm 2'deki katalog adıyla); {$p['finding_map']}. system_name yalnızca bu raporda sistem olarak yazdığın kayıtlardan biri olabilir (\"Tesisat Geneli\" hariç); genel kontrol listesindeki maddenin kusuru sistemsizdir (null). Tesisatın GENELİNE ait kusur (proje yok, bakım kaydı yok gibi) bir sisteme ait değildir: system_name null, ambiguous false. Sistemini GÜVENLE belirleyemediğin kusurda system_name null, ambiguous true.\n"
            . <<<'PROMPT'
- severity: rapor kusuru derecelendiriyorsa raporun kendi ifadesiyle yaz (hafif / ağır kusur gibi). Rapor derece vermiyorsa null; kendinden derece uydurma.
- description: kusurun PDF'deki metni; kusurun kendi madde kodu / numarası varsa başına yaz. Kusur hangi cihazda / yerde ise metinde aynen koru. Aynı kusuru tekrarlama; U / UD hücrelerinden tek başına kusur üretme. source_pages gerçek PDF sayfalarıdır.

10. ÇIKARIM SINIRI
equipment_definitions YAPI tarifidir. Cihaz / ölçüm noktası SAYISIYLA BÜYÜYEBİLEN gerçek değerler (identity_value, attributes[].value) equipment_axis="rows" / "columns" iken HER ZAMAN null kalır - Camelot okur. TEK istisna equipment_axis="none".

11. PERİYODİK KONTROLÜ YAPAN KURULUŞ (extracted_data.inspection_body)
Raporu düzenleyen / periyodik kontrolü yapan kuruluşu GERÇEK değerleriyle yaz: name, address, phone, email, website, tax_info, accreditation ve personnel (kontrol eden / onaylayan; role, name, profession, chamber_registry_no, diploma, authorization_no). Yalnızca etiketler olup değerler yoksa o kişiyi ekleme; hiç kişi yoksa []. Raporu İSTEYEN firma (müşteri) bu değildir. PDF'de olmayan bilgiyi uydurma; null bırak.

findings_structure: system_assignment {"required": true, "source": [], "fallback": null}; deduplication {"enabled": true, "duplicate_finding_rule": "same_finding_same_system"}; finding_fields [].
PROMPT;
    }

    // Ortak talimatın örnek çıktısı: türün örnek sistemi, listesi ve tesis bilgileriyle.
    private function familyContract(array $p): string
    {
        $example = json_encode($p['example'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return "ÇIKTI ŞEKLİ (örnek değerler YALNIZCA şekli anlatır; rapordaki gerçek bölümleri ve değerleri yaz):\n{$example}\n\nSADECE geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.";
    }

    // Örnek çıktının şablonu: sistemler, kusurlar, tesis bilgileri ve tür türe göre; geri kalanı sabit.
    private function familyExample(array $systems, array $findings, array $facilityFields, array $facilityValues, string $slug, string $evidence): array
    {
        $empty = ['start_heading_patterns' => [], 'continuation_patterns' => [], 'end_detection_patterns' => []];

        return [
            'template' => [
                'template_type' => 'tesisat_raporu',
                'template_version' => '1.0',
                'report_information' => ['fields' => [['key' => 'report_no', 'label_patterns' => ['Rapor No']], ['key' => 'control_date', 'label_patterns' => ['Muayene Tarihi']], ['key' => 'validity_date', 'label_patterns' => ['Gelecek Muayene Tarihi']]]],
                'facility_or_project_information' => ['section_heading_patterns' => ['TESİS BİLGİLERİ'], 'fields' => $facilityFields],
                'fire_systems' => ['systems' => array_map(fn (array $system) => $system + ['section_detection' => $empty], $systems)],
                'overall_result' => ['section_heading_patterns' => [], 'overall_text' => ['label_patterns' => [], 'value_location_patterns' => [], 'text_boundary_patterns' => []], 'overall_status' => ['label_patterns' => [], 'status_patterns' => [], 'value_location_patterns' => []], 'camelot_extraction' => ['section_patterns' => [], 'text_patterns' => [], 'status_patterns' => [], 'status_extraction' => 'dynamic']],
                'findings_structure' => ['system_assignment' => ['required' => true, 'source' => [], 'fallback' => null], 'deduplication' => ['enabled' => true, 'duplicate_finding_rule' => 'same_finding_same_system'], 'finding_fields' => []],
            ],
            'extracted_data' => [
                'report_category' => 'yangin_tesisati',
                'extraction_mode' => 'structured',
                'findings' => $findings,
                'report_information' => [['key' => 'report_no', 'value' => '...'], ['key' => 'control_date', 'value' => '23.01.2025'], ['key' => 'validity_date', 'value' => '23.01.2026'], ['key' => 'notlar', 'value' => null], ['key' => 'bina', 'value' => null]],
                'facility_information' => $facilityValues,
                'overall_result' => ['text' => '...', 'status' => 'uygun'],
                'result_legend' => [['code' => 'U', 'meaning' => 'Uygun'], ['code' => 'U.D', 'meaning' => 'Uygun Değil'], ['code' => 'U.Y', 'meaning' => 'Uygulaması Yok']],
                'inspection_body' => ['name' => '...', 'address' => null, 'phone' => null, 'email' => null, 'website' => null, 'tax_info' => null, 'accreditation' => null, 'personnel' => []],
                'equipment_type' => ['slug' => $slug, 'evidence' => $evidence],
                'equipment_tag' => ['label' => null, 'value' => null, 'evidence' => null],
                'equipment_specs' => [],
            ],
        ];
    }

    // Paratoner / yıldırımdan korunma (Bakanlığın ZPKR03 formatı ya da firmanın formatı).
    private function lightningParts(): array
    {
        $verdict = fn (?string $status, string $evidence) => ['status' => $status, 'raw' => null, 'basis' => 'derived', 'evidence' => $evidence];

        return [
            'subject' => 'YILDIRIMDAN KORUNMA (PARATONER) TESİSATI',
            'report_kinds' => '- Yıldırımdan korunma tesisatı periyodik kontrol raporu: Bakanlığın standart formatı ("Yıldırımdan Korunma Tesisatı Periyodik Kontrol Raporu", ZPKR03) ya da firmanın kendi formatı (TS EN 62305-3). Rapor her yıldırımdan korunma tesisatı için (aktif paratoner, Franklin çubuğu, Faraday kafesi) ayrı düzenlenebilir; raporun kapsadığı paratoner / tesisat "Ekipman Seri No / Kod" gibi bir alanda yazar (örn. "5 / EPS Bina Üzeri").' . "\n"
                . '- Genelde tesis bilgileri, kontrol grupları (genel kontroller, topraklama malzemeleri, test klemensi, indirme iletkeni, yıldırım sayacı, çatı / paratoner direği, koruma borusu, yakalama çubuğu, Faraday kafesi yakalama uçları), topraklama ölçüm değeri, topraklama direnci adresleme tablosu ve dolaylı korunma (parafudr, potansiyel dengeleme) kontrollerini içerir.',
            'type_rule' => 'Yıldırımdan korunma / paratoner tesisatı raporu ise katalogdaki paratoner slug\'ını yaz. Elektrik iç tesisatı, topraklama tesisatı (alçak gerilim topraklama ölçümü) gibi raporlar bu tesisatın raporu değildir.',
            'systems' => "- Yakalama Sistemi: aktif paratoner / paratoner başlığı, çatı / paratoner direği, yakalama çubukları, Faraday kafesi yakalama uçları ve kafes aralıkları.\n"
                . "- İndirme İletkenleri: indirme iletkenleri ve sabitleme kroşeleri, koruma borusu, test klemensi, yıldırım sayacı.\n"
                . "- Topraklama ve Ölçümler: topraklama elektrotları / malzemeleri, paratoner topraklama ölçüm değeri ve sınır değeri, topraklama direnci adresleme (nokta başına ölçüm) tablosu.\n"
                . "- Parafudr (Dolaylı Koruma): B / C / D sınıfı (tip 1 / 2 / 3) parafudrların bağlantı ve yerleşim kontrolleri.\n"
                . "- Potansiyel Dengeleme: ana eş potansiyel dengeleme barası ve metal tesisatların (su, kalorifer, doğalgaz boruları, telefon / enerji / veri hatları) bağlantıları.",
            'when_system' => 'örn. çatı / paratoner direği kontrol grubu, indirme iletkeni kontrol grubu, topraklama ölçüm değeri / adresleme tablosu, parafudr kontrol bölümü, potansiyel dengeleme kontrol bölümü. Raporun "genel kontroller" grubu (önceki rapor, projeye uygunluk gibi maddeler) Tesisat Geneli kaydına aittir',
            'table_types' => '"Ölçüm Noktası" (topraklama direnci adresleme tablosu: nokta no, ölçülen Ω), "Topraklama Ölçümü" (tek ölçüm: equipment_axis "none"; ölçüm değeri Ω, sınır değer Ω gerçek değerleriyle), "Faraday Kafesi" (koruma düzeyi, referans / ölçülen kafes aralığı, referans / ölçülen iniş iletken aralığı).',
            'bina_rule' => 'Raporun kapsadığı paratoner / tesisat tanımı ("Ekipman Seri No / Kod: 5 / EPS Bina Üzeri", "paratoner adresi" gibi) bir bina adı içeriyorsa o binanın adını bina olarak yaz (örn. "EPS Bina"); "Genel", "Tesis geneli" yazıyorsa null',
            'facility_keys' => 'hava_durumu, zemin_nem_durumu (toprak durumu), sebeke_tipi (topraklama şebeke tipi), yildirimdan_korunma_tipi (aktif paratoner / Franklin / Faraday kafesi), test_rogari_var_mi, yapi_cinsi, kontrol_nedeni (periyodik / ilk kontrol), proje_var_mi, es_potansiyel_bara_var_mi, topraklayici_tipi (topraklayıcı tesis şekli: derin / ring / temel), enerji_saglayan_kurulus, kullanim_amaci, paratoner_adresi, koruma_duzeyi (I-IV).',
            'finding_map' => 'paratoner direği / başlık / yakalama ucu kusuru → Yakalama Sistemi, iniş iletkeni / kroşe / test klemensi / koruma borusu / yıldırım sayacı kusuru → İndirme İletkenleri, topraklama değeri / elektrot kusuru → Topraklama ve Ölçümler, parafudr kusuru → Parafudr (Dolaylı Koruma), eş potansiyel bara bağlantı kusuru → Potansiyel Dengeleme gibi',
            'example' => $this->familyExample(
                [
                    ['system_name' => 'Tesisat Geneli', 'section_heading_patterns' => ['GENEL KONTROLLER'], 'verdict' => $verdict('uygun', 'A.1-A.4: U'), 'equipment_definitions' => []],
                    ['system_name' => 'İndirme İletkenleri', 'section_heading_patterns' => ['İndirme İletkeni Kontrollü'], 'verdict' => $verdict('uygun', 'D.1-D.5: U'), 'equipment_definitions' => []],
                    ['system_name' => 'Topraklama ve Ölçümler', 'section_heading_patterns' => ['PARATONER TOPRAKLAMA ÖLÇÜM DEĞERİ'], 'verdict' => $verdict('uygun', 'Ölçülen 1,12 Ω < sınır 10 Ω'), 'equipment_definitions' => [[
                        'equipment_name' => 'Topraklama Ölçümü',
                        'instance_structure' => ['identity_field' => 'Topraklama Ölçüm Değeri (Ω)', 'identity_value' => 'Paratoner topraklaması', 'identity_hint' => ['pattern' => null, 'validation' => 'soft'], 'equipment_axis' => 'none', 'group_width' => null, 'header_patterns' => [], 'result_columns' => [], 'ambiguous' => false, 'ambiguous_reason' => null],
                        'attributes' => [['field' => 'Topraklama Ölçüm Değeri (Ω)', 'source_pattern' => 'Topraklama Ölçüm Değeri (Ω)', 'value' => '1,12'], ['field' => 'Topraklama Sınır Değeri (Ω)', 'source_pattern' => 'Topraklama Sınır Değeri (Ω)', 'value' => '10,00']],
                        'verdict' => $verdict('uygun', 'Ölçülen 1,12 Ω < sınır 10 Ω'),
                    ]]],
                    ['system_name' => 'Parafudr (Dolaylı Koruma)', 'section_heading_patterns' => ['PARAFUDR SİSTEMİ KONTROLÜ'], 'verdict' => ['status' => 'uygulanamiyor', 'raw' => 'U.Y', 'basis' => 'derived', 'evidence' => 'A.1-A.3: U.Y'], 'equipment_definitions' => []],
                ],
                [['id' => 'finding-1', 'system_name' => 'İndirme İletkenleri', 'description' => 'D.5. İndirme iletkeni kroşe aralıkları 1 m üzerindedir.', 'source_pages' => [1], 'severity' => null, 'ambiguous' => false]],
                [['key' => 'yildirimdan_korunma_tipi', 'label_patterns' => ['Yıldırımdan korunma tesisat tipi']], ['key' => 'test_rogari_var_mi', 'label_patterns' => ['Test rogarı var mı?']]],
                [['key' => 'yildirimdan_korunma_tipi', 'value' => 'Aktif'], ['key' => 'test_rogari_var_mi', 'value' => 'Var']],
                'paratoner',
                'YILDIRIMDAN KORUNMA PERİYODİK KONTROL RAPORU'
            ),
        ];
    }

    // Akümülatör / UPS (Bakanlığın standart formatı yok; firma formatları — çoğu her akü grubu / redresör için ayrı sayfa).
    private function batteryParts(): array
    {
        $verdict = fn (?string $status, string $evidence) => ['status' => $status, 'raw' => null, 'basis' => 'derived', 'evidence' => $evidence];

        return [
            'subject' => 'AKÜMÜLATÖR (AKÜ / UPS) TESİSATI',
            'report_kinds' => '- Akümülatör tesisatı periyodik kontrol raporu: Bakanlığın standart bir formatı yoktur; firmanın kendi formatıdır ("akümülatör tesisatı periyodik kontrol raporu", "UPS ve akü periyodik kontrol raporu" gibi). İş Ekipmanları Yönetmeliği Ek-III Tablo-3\'e göre yılda bir yapılır.' . "\n"
                . '- Rapor tek bir dosyada birden fazla akü grubunu / redresörü / UPS\'i içerebilir: çoğu zaman HER akü grubu için ayrı bir sayfa tekrarlanır (sayfanın başında akü grubunun bulunduğu bölüm / yer, ekipman adı (redresör / UPS), marka, seri no, üretim tarihi, akü gerilimi, akü kapasitesi; altında kontrol maddeleri ve o akü grubunun sonucu). Bu sayfaların hepsi TEK raporun parçalarıdır.',
            'type_rule' => 'Akümülatör / akü / UPS tesisatı raporu ise katalogdaki akümülatör slug\'ını yaz. Forklift / transpalet gibi bir aracın akü kontrolü, jeneratör ya da elektrik iç tesisatı raporu bu tesisatın raporu değildir.',
            'systems' => "- Akü Grupları ve Redresörler: akü grupları ve onları besleyen redresörler / şarj cihazları — her akü grubunun bölümü / yeri, ekipman adı, marka, seri no, üretim tarihi, akü gerilimi (V), akü kapasitesi (Ah) ve o akü grubunun sonucu. Her akü grubu için tekrarlanan sayfalar bu sistemin listesidir.\n"
                . "- UPS Sistemleri: kesintisiz güç kaynakları (UPS) ve aküleri — ayrı bir UPS bölümü / listesi varsa.\n"
                . "- Akü Odası ve Şarj Alanı: akü odası / şarj istasyonu — havalandırma, aside dayanıklı zemin, uyarı levhaları, göz duşu / acil yıkama, aydınlatma, yangın önlemleri — ayrı bir bölüm varsa.\n"
                . "- Akü Ölçümleri: akü / hücre gerilimi, iç direnç, kapasite (deşarj) testi gibi ölçüm tabloları.",
            'when_system' => 'örn. akü grubu / redresör bilgi sayfaları, UPS listesi, akü odası kontrol bölümü, akü ölçüm tablosu. Her akü grubu sayfasında tekrarlanan kontrol maddeleri (kablo kesiti, oksitlenme, asit sızıntısı, kişisel koruyucu donanım gibi) Tesisat Geneli kaydına aittir; bir maddenin bir sayfada uygun olmaması o akü grubunun sonucunu da uygun değil yapar',
            'table_types' => '"Akü Grubu" (her akü grubu için tekrarlanan sayfa / satır: identity_field seri no etiketi, attributes: bölüm / yer, ekipman adı, marka, üretim tarihi, akü gerilim değeri (V), akü kapasitesi (Ah)), "UPS" (yer, marka, model, güç (kVA), akü sayısı), "Akü Ölçümü" (akü / hücre no, gerilim, iç direnç).',
            'bina_rule' => 'Sayfalardaki "Bölüm" alanı akü grubunun bulunduğu yerdir, raporun binası değildir; rapor birden fazla bölümdeki akü gruplarını kapsıyorsa bina null. Rapor yalnızca tek bir binanın akülerini kapsıyorsa o binanın adını yaz',
            'facility_keys' => 'kullanim_amaci, aku_tipi (kuru / sulu / jel / lityum), aku_odasi_var_mi, havalandirma_tipi (doğal / mekanik), kontrol_nedeni (periyodik / ilk kontrol), son_kontrol_tarihi.',
            'finding_map' => 'akü grubu / redresör / şarj cihazı kusuru → Akü Grupları ve Redresörler, UPS kusuru → UPS Sistemleri, akü odası / havalandırma / zemin / levha kusuru → Akü Odası ve Şarj Alanı, ölçüm değeri kusuru → Akü Ölçümleri gibi',
            'example' => $this->familyExample(
                [
                    ['system_name' => 'Tesisat Geneli', 'section_heading_patterns' => ['ÖLÇÜM VE SONUÇLARI'], 'verdict' => $verdict('uygun', '1.1-1.21: U'), 'equipment_definitions' => []],
                    ['system_name' => 'Akü Grupları ve Redresörler', 'section_heading_patterns' => ['AKÜ BİLGİLERİ'], 'verdict' => $verdict('uygun', 'Akü gruplarının sonuçları uygun'), 'equipment_definitions' => [[
                        'equipment_name' => 'Akü Grubu',
                        'instance_structure' => ['identity_field' => 'Seri No', 'identity_value' => null, 'identity_hint' => ['pattern' => null, 'validation' => 'soft'], 'equipment_axis' => 'rows', 'group_width' => null, 'header_patterns' => ['AKÜ BİLGİLERİ', 'Ekipman Adı'], 'result_columns' => [], 'ambiguous' => false, 'ambiguous_reason' => null],
                        'attributes' => [['field' => 'Bölüm', 'source_pattern' => 'Bölüm', 'value' => null], ['field' => 'Ekipman Adı', 'source_pattern' => 'Ekipman Adı', 'value' => null], ['field' => 'Marka', 'source_pattern' => 'Marka', 'value' => null], ['field' => 'Üretim Tarihi', 'source_pattern' => 'Üretim Tarihi', 'value' => null], ['field' => 'Akü Gerilim Değeri (V)', 'source_pattern' => 'Akü Gerilim Değeri (V)', 'value' => null], ['field' => 'Akü Kapasitesi (Ah)', 'source_pattern' => 'Akü Kapasitesi (Ah)', 'value' => null]],
                        'verdict' => ['status' => null, 'raw' => null, 'basis' => 'not_stated', 'evidence' => null],
                    ]]],
                ],
                [['id' => 'finding-1', 'system_name' => null, 'description' => '1.14 Akü yüzeylerinde asit birikmesi var.', 'source_pages' => [2], 'severity' => null, 'ambiguous' => false]],
                [['key' => 'aku_tipi', 'label_patterns' => ['Akü Tipi']]],
                [['key' => 'aku_tipi', 'value' => 'Kuru']],
                'akumulator',
                'AKÜMÜLATÖR TESİSATI PERİYODİK KONTROL RAPORU'
            ),
        ];
    }

    // Trafo merkezi (Bakanlığın ZPKR05 formatı ya da firmanın formatı). Rapor her ekipman (trafo, kesici, hücre) için ayrı
    // gelebilir: ekipman raporu sistem raporu olur, ekipman tek örnek olarak yazılır (raporları birbirinden ayrılsın diye).
    private function transformerParts(): array
    {
        $verdict = fn (?string $status, string $evidence) => ['status' => $status, 'raw' => null, 'basis' => 'derived', 'evidence' => $evidence];
        $single = fn (string $field, ?string $value) => ['identity_field' => $field, 'identity_value' => $value, 'identity_hint' => ['pattern' => null, 'validation' => 'soft'], 'equipment_axis' => 'none', 'group_width' => null, 'header_patterns' => [], 'result_columns' => [], 'ambiguous' => false, 'ambiguous_reason' => null];

        return [
            'subject' => 'TRAFO MERKEZİ (1-36 kV TRANSFORMATÖR, YG HÜCRELERİ VE TOPRAKLAMA)',
            'report_kinds' => '- Trafo merkezi / transformatör periyodik kontrol raporu: Bakanlığın formatı ("Transformatör 1-36 kV (YG) Gözle Kontrol ve Topraklama Tesisatı Periyodik Kontrol Raporu", ZPKR05) ya da firmanın kendi formatı ("trafo periyodik kontrol raporu", "trafo topraklama tesisatı periyodik kontrol raporu", "YG tesisleri işletme sorumluluğu periyodik kontrol formu" gibi). Bakanlığın kriterlerine göre rapor her ekipman (trafo, kesici, hücre) için ayrı düzenlenebilir; aynı trafo merkezi için birden fazla rapor gelebilir.' . "\n"
                . '- Genelde tesis / trafo bilgileri, gözle kontrol listesi (uyarı levhaları, kilitler, kesici, ayırıcı, koruma röleleri, akım / gerilim trafoları, bara bağlantıları, trafo gövdesi, yağ seviyesi, sıcaklık göstergeleri, Buchholz rölesi, basınç tahliye, soğutma, trafo odası aydınlatması ve havalandırması, izole halı / sehpa / eldiven / istanka, yangın söndürme cihazları), topraklama ölçümleri (işletme ve koruma topraklaması ayrık ya da birleşik; toprak kısa devre akımı, açma süresi, dokunma ve topraklama gerilimleri) ve kusur açıklamalarını içerir.',
            'scope_rule' => "TRAFO MERKEZİNDE EKİPMAN RAPORU (bu tesisata özel; yukarıdaki kapsam kuralından önce gelir)\n"
                . "Rapor yalnızca TEK bir ekipmanın raporuysa — tek bir transformatör, tek bir kesici ya da tek bir hücre; gözle kontrol, topraklama ölçümü gibi birden fazla bölüm içerse de — template_type \"sistem_raporu\" olur (\"birden fazla bölüm varsa sistem_raporu yazma\" kuralı bu durumda uygulanmaz). Raporun hangi ekipmana ait olduğu genelde başlıkta ya da \"Ekipman Seri No / Kod\", \"Trafo No\", \"Hücre No\" gibi bir alanda yazar. Rapor trafo merkezinin tamamını (bütün trafoları / hücreleri ya da merkezin genelini) kapsıyorsa \"tesisat_raporu\" yaz.\n"
                . "Ekipman raporunda:\n"
                . "- Ekipmanın sistemini (transformatör → Transformatörler, hücre → YG (OG) Hücreleri, kesici / ayırıcı → Kesici ve Ayırıcılar) fire_systems.systems listesinde İLK kayıt olarak yaz. Raporun gözle kontrol listesi o ekipmanın kontrolüdür: \"Tesisat Geneli\" kaydı yazma; listenin sonucu bu sistemin verdict'i, listedeki uygun olmayan maddeler bu sistemin bulgusudur.\n"
                . "- Ekipmanı bu sistemin equipment_definitions listesine TEK örnek olarak yaz: equipment_axis \"none\"; identity_field ekipmanı tanımlayan etiket (PDF'deki gibi); identity_value ekipmanın PDF'deki no'su / kodu / adı (örn. \"TR-1\"), kısaltmadan ve değiştirmeden — aynı ekipmanın sonraki raporları bununla eşleşir; attributes ekipmanın etiket bilgileri (güç, gerilim, tip, marka, seri no gibi) gerçek değerleriyle; verdict ekipmanın sonucu. Ekipmanın etiket bilgileri tesis bilgisi değildir; facility_information'a yazma.\n"
                . "- Raporun diğer bölümleri (topraklama ölçümleri, trafo odası, güvenlik ekipmanları gibi) kendi sistemleriyle ayrıca yazılır.\n\n",
            'type_rule' => 'Trafo merkezi / transformatör / YG (OG) hücre ya da kesici / trafo topraklama raporu ise katalogdaki trafo slug\'ını yaz. Alçak gerilim topraklama tesisatı, elektrik iç tesisatı (panolar), paratoner ya da jeneratör raporu bu tesisatın raporu değildir.',
            'systems' => "- Transformatörler: transformatörlerin kendisi — gövde ve tank, buşingler, yağ seviyesi ve sızıntı, sıcaklık göstergeleri, Buchholz rölesi, basınç tahliye / hermetik koruma, soğutma sistemi, silikajel, yağ delinme testi, YG kablo ve bara montajı; trafo bilgileri (güç, gerilim, tip) listesi.\n"
                . "- YG (OG) Hücreleri: giriş / çıkış / ölçü / trafo koruma hücreleri — hücre kapıları ve mekanik kilitleme, konum göstergeleri, bara bağlantıları ve güvenlik mesafeleri, kablo başlıkları, topraklama ayırıcısı.\n"
                . "- Kesici ve Ayırıcılar: YG kesicileri, yük ayırıcıları / ayırıcılar ve manevra kolları / kilitleme tertibatı, YG sigortaları, AG çıkış (termik-manyetik) kesicisi.\n"
                . "- Koruma ve Ölçü: koruma röleleri ve ayarları, akım trafoları, gerilim trafoları, sayaç / ölçü bölümü ve mühürleri.\n"
                . "- Enerji Girişi: branşman hattı, ENH direkleri ve izolatörleri, parafudrlar ve topraklamaya bağlantısı.\n"
                . "- Trafo Merkezi Topraklaması: işletme topraklaması, koruma topraklaması (ayrık ya da birleşik), yıldız noktası ve tank topraklaması; topraklama ölçüm / test tabloları.\n"
                . "- Trafo Odası / Bina: trafo merkezi binası / odası — kapıların kilitlenmesi ve dışa açılması, havalandırma ve panjur tel kafesleri, aydınlatma ve acil aydınlatma, yanıcı malzeme, bina çatlak / nem durumu, yangın algılama ve söndürme, kumanda aküsü ve redresörü.\n"
                . "- Güvenlik Ekipmanları ve İşaretler: izole halı, izole sehpa, YG eldiveni, manevra istankası, gerilim dedektörü, baret / gözlük / emniyet kemeri, ilk yardım malzemesi, ölüm tehlikesi / uyarı levhaları, tek hat şeması ve işletme talimatı, plastik zincir / bariyer.",
            'when_system' => 'örn. transformatör bilgi / kontrol bölümü, hücre kontrol formu, kesici kontrol formu, topraklama ölçüm / test tablosu, trafo odası kontrol bölümü, güvenlik ekipmanları listesi. Trafo merkezinin tamamını kapsayan raporda tek bir karışık gözle kontrol listesi varsa o liste Tesisat Geneli kaydına aittir',
            'table_types' => '"Transformatör" (trafo no / adı, güç (kVA), gerilim, tip (yağlı / kuru), bağlantı grubu, marka, seri no, imal yılı gibi etiket bilgileri), "Hücre" (hücre no / adı, hücre tipi, kesici / ayırıcı), "Topraklama Ölçümü" (topraklama türü (işletme / koruma / birleşik), ölçülen direnç (Ω), toprak kısa devre akımı, açma süresi, dokunma gerilimi, topraklama gerilimi; tek ölçüm ise equipment_axis "none"), "Koruma Rölesi" (röle, ayar değerleri).',
            'bina_rule' => 'Tesiste birden fazla trafo merkezi varsa ve rapor bunlardan YALNIZCA BİRİ için düzenlendiyse o merkezin adını / no\'sunu bina olarak yaz (örn. "TM-2", "B Blok Trafo Merkezi"); trafonun, hücrenin ya da kesicinin adı / no\'su bina değildir; merkez adı yazmıyorsa null',
            'facility_keys' => 'tm_no (trafo merkezi no), abone_no, enerji_saglayan_kurulus (dağıtım şirketi), sebeke_gerilimi, sebeke_tipi (TT / TN / IT), trafo_merkezi_tipi (bina / direk / köşk), trafo_gucu (kVA), trafo_tipi (yağlı / kuru), trafo_sayisi, proje_var_mi, tek_hat_semasi_var_mi, topraklayici_tipi (ring / derin / yüzeysel / temel), olcum_metodu (çevrim empedansı / 3 uçlu / klamp), kontrol_nedeni (periyodik / ilk kontrol), hava_durumu, zemin_nem_durumu (toprak durumu).',
            'finding_map' => 'trafo gövdesi / yağ / buşing / Buchholz / soğutma kusuru → Transformatörler, hücre / bara / kablo başlığı kusuru → YG (OG) Hücreleri, kesici / ayırıcı / sigorta kusuru → Kesici ve Ayırıcılar, röle / akım trafosu / gerilim trafosu / sayaç kusuru → Koruma ve Ölçü, branşman / direk / parafudr kusuru → Enerji Girişi, topraklama direnci / bağlantısı / topraklama gerilimi kusuru → Trafo Merkezi Topraklaması, kapı / havalandırma / aydınlatma / bina / yangın söndürme kusuru → Trafo Odası / Bina, izole halı / eldiven / istanka / levha / talimat kusuru → Güvenlik Ekipmanları ve İşaretler gibi',
            'example' => $this->familyExample(
                [
                    ['system_name' => 'Tesisat Geneli', 'section_heading_patterns' => ['GÖZLE KONTROL KRİTERLERİ'], 'verdict' => $verdict('uygun_degil', 'Elektrik eldiveni kontrolü: Uygun Değil'), 'equipment_definitions' => []],
                    ['system_name' => 'Transformatörler', 'section_heading_patterns' => ['TRAFO BİLGİLERİ'], 'verdict' => $verdict('uygun', 'Trafo gövdesi, yağ seviyesi: Uygun'), 'equipment_definitions' => [[
                        'equipment_name' => 'Transformatör',
                        'instance_structure' => $single('Trafo No', 'TR-1'),
                        'attributes' => [['field' => 'Gücü (kVA)', 'source_pattern' => 'Gücü (kVA)', 'value' => '1600'], ['field' => 'Gerilimi', 'source_pattern' => 'Gerilimi', 'value' => '34,5 / 0,4 kV'], ['field' => 'Tipi', 'source_pattern' => 'Tipi', 'value' => 'Yağlı']],
                        'verdict' => $verdict('uygun', 'Trafo gövdesi, yağ seviyesi: Uygun'),
                    ]]],
                    ['system_name' => 'Trafo Merkezi Topraklaması', 'section_heading_patterns' => ['TRAFO İŞLETME VE KORUMA TOPRAKLAMALARI'], 'verdict' => $verdict('uygun', 'Topraklama gerilimi sınırın altında'), 'equipment_definitions' => [[
                        'equipment_name' => 'Topraklama Ölçümü',
                        'instance_structure' => ['identity_field' => 'Sıra', 'identity_value' => null, 'identity_hint' => ['pattern' => null, 'validation' => 'soft'], 'equipment_axis' => 'rows', 'group_width' => null, 'header_patterns' => ['Sıra', 'TRAFO İŞLETME VE KORUMA TOPRAKLAMALARI'], 'result_columns' => [], 'ambiguous' => false, 'ambiguous_reason' => null],
                        'attributes' => [['field' => 'Topraklama', 'source_pattern' => 'TRAFO İŞLETME VE KORUMA TOPRAKLAMALARI', 'value' => null], ['field' => 'RE (ohm)', 'source_pattern' => 'RE', 'value' => null], ['field' => 'UE (kV)', 'source_pattern' => 'UE', 'value' => null]],
                        'verdict' => ['status' => null, 'raw' => null, 'basis' => 'not_stated', 'evidence' => null],
                    ]]],
                ],
                [['id' => 'finding-1', 'system_name' => null, 'description' => 'Elektrik eldiveninin periyodik testi yapılmamıştır.', 'source_pages' => [2], 'severity' => null, 'ambiguous' => false]],
                [['key' => 'trafo_gucu', 'label_patterns' => ['Trafo Gücü']], ['key' => 'trafo_tipi', 'label_patterns' => ['Trafo Tipi']]],
                [['key' => 'trafo_gucu', 'value' => '1600 kVA'], ['key' => 'trafo_tipi', 'value' => 'Yağlı']],
                'trafo',
                'TRANSFORMATÖR 1-36 kV (YG) GÖZLE KONTROL VE TOPRAKLAMA TESİSATI PERİYODİK KONTROL RAPORU'
            ),
        ];
    }
}
