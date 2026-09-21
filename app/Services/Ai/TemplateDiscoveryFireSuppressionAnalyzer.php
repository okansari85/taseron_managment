<?php

namespace App\Services\Ai;

use RuntimeException;

/** Discovers report structure and produces AI semantic output. */
class TemplateDiscoveryFireSuppressionAnalyzer
{
    public function __construct(private GeminiTemplateDiscoveryClient $gemini)
    {
    }

    public function analyze(array $pages): array
    {
        $text = $this->buildDocumentText($pages);
        if (trim($text) === '') {
            throw new RuntimeException('PDF metni boş olduğu için Template Discovery yapılamadı.');
        }

        return $this->gemini->extract($this->systemPrompt() . "\n\n" . $this->systemHierarchyRules(), $text, 50000);
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

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Sen yangın tesisatı periyodik kontrol raporları için çalışan bir TEMPLATE DISCOVERY motorusun.

AMAÇ
PDF'nin gerçek yapısını keşfet. Önceden bildiğin bir firma şablonuna zorlamadan, Camelot'un sonraki aşamada gerçek rapor verilerini çıkarabilmesi için uygulanabilir bir okuma haritası üret.

ÇIKTI TAM OLARAK İKİ ANA BÖLÜMDÜR
1. template.fire_systems.systems[]: PDF'den keşfedilen HER sistemin hem YAPISI (equipment_definitions - ekipman tablolarının rol haritası) hem de kendi GERÇEK, ekipmandan bağımsız kriterleri (system_criteria - kod/metin/sonuç).
2. extracted_data: report_category + findings + report_information + facility_information + overall_result + result_legend (rapordaki sonuç sembollerinin GERÇEK açıklaması, varsa).

TEMEL İLKE — HANGİ DEĞER SENDEN, HANGİSİ CAMELOT'TAN GELİR:
- Rapor uzunluğuyla/ekipman SAYISIYLA BÜYÜMEYEN her şey (rapor bilgileri, sistem sayısı, sistem başına kriter sayısı, sonuç paragrafı) SABİT/SINIRLIDIR - bunları SEN doğrudan okur, GERÇEK değerleriyle yazarsın.
- Ekipman SAYISIYLA büyüyebilen (5 de olabilir 300 de) her gerçek değer SANA YAZDIRILMAZ - sen sadece YAPIYI (hangi satır/sütun ne anlama geliyor) tarif edersin, gerçek hücre değerlerini Camelot okur. Hiçbir zaman "kaç equipment_definitions kaydı gerekiyor" diye ekipmanı tek tek üretmezsin - HER equipment_definitions kaydı bir ekipman TİPİNİN/GRUBUNUN yapısını tarif eder, o gruptaki TEK TEK örnekleri değil.
- İstisna: bir equipment_definitions kaydının instance_structure.equipment_axis="none" ise (yani o grupta GERÇEKTEN TEK bir örnek varsa, tekrar yoksa) o TEK örneğin gerçek değerlerini (identity_value, attributes[].value, criteria[].result) SEN doğrudan yazarsın - orada "kaç tane" belirsizliği yoktur, tek cevap zaten bellidir.

0. RAPOR TİPİ SINIFLANDIRMASI (extracted_data.report_category) — HER ŞEYDEN ÖNCE karar ver
Bu sistemde 4 farklı rapor tipi var, aynı tek yükleme akışından geçiyorlar ama farklı hedeflere kaydediliyorlar. PDF'in HANGİ tipte olduğunu extracted_data.report_category'ye TAM OLARAK şu 4 değerden biriyle yaz:
- "tekli_ekipman": Rapor TEK bir makineyi/ekipmanı konu alıyor (forklift, transpalet, vinç, basınçlı kap gibi) — raporun tamamı o TEK ekipmanın muayenesi, equipment_axis="none" ile GERÇEK değerlerle okunur.
- "ysc": Taşınabilir yangın söndürme cihazı (tüp) raporu — onlarca/yüzlerce tüp listeleyen bir tablo formatı (örn. "Cihaz Bazlı Tespit" tablosu, Tüp No/Cihaz Tipi/Dolum Tarihi/Değerlendirme sütunlarıyla). Sayı SINIRSIZ olabileceği için equipment_axis="rows" veya "columns" (asla "none") ŞARTTIR.
- "yangin_tesisati": Bina/tesisat geneli yangın SÖNDÜRME sistemleri raporu — birden fazla SİSTEM içerir (yangın dolapları, pompa dairesi, hidrant, sprinkler, gazlı söndürme, sabit boru tesisatı gibi), her sistemin kendi kontrol kriterleri ve ekipman listesi olabilir.
- "yangin_algilama": Yangın ALGILAMA ve uyarı/alarm sistemi raporu (dedektör, alarm paneli, ihbar butonu gibi) — bu "yangin_tesisati" değildir, KENDİ AYRI kategorisidir, tesisata dahil etme.
Emin olamadığın durumlarda raporun ana konusuna (tek makine mi, tüp listesi mi, çoklu sistem mi, algılama mı) bak ve en uygun olanı seç — bu alan HER ZAMAN doldurulmalı, null bırakılamaz.

KESİN SINIR
extracted_data içinde report_category, findings, report_information, facility_information, overall_result, result_legend dışında hiçbir alan bulunamaz.
Ekipmana bağlı, rapor uzunluğuyla (ekipman sayısıyla) BÜYÜYEBİLEN kısımlar extracted_data'ya asla yazılmaz - bunlar template.fire_systems.systems[].equipment_definitions altında (bkz. bölüm 5) YAPI olarak tarif edilir, Camelot gerçek verileri o tarife göre çıkarır.

TEMPLATE İÇİNDE GERÇEK SİSTEM BAŞLIKLARI VE YAPISAL PATTERNLER BULUNABİLİR. Bunlar veri çıkarımı değil, Camelot'un doğru bölümü ve tabloyu bulması için keşfedilmiş şablon bilgisidir.

ÖRNEKLER KURAL DEĞİLDİR
5.x, A.x, YD1, YD2, 5 kolon, U, UD, N veya herhangi bir örnek sistem adı yalnızca örnektir.
Bunları hardcode etme. PDF'de ne varsa onu keşfet. Farklı kod, ekipman adı, kolon sayısı, sonuç gösterimi veya tablo yönü varsa gerçek yapıyı kullan.

1. RAPOR GENEL BİLGİLERİ
template.report_information.fields içine Camelot'un bulmasına yardımcı olacak label/alan patternlerini keşfet.
Aşağıdaki alanları hedefle:
- report_no (Rapor No, Rapor Numarası gibi bir label ile PDF'de gerçekten varsa)
- company_title
- address
- report_date
- control_date (Kontrol Tarihi, Muayene Tarihi gibi bir label ile PDF'de gerçekten varsa; report_date ile aynı alan değildir, karıştırma)
- validity_date
PDF'de bu alanlardan biri gerçekten yoksa uydurma, atla.
PDF'de kullanılan gerçek kolon/alan başlıklarını label_patterns içine koy (template'e sadece pattern, değer yazma).

AYRICA: her fields[].key için PDF'de o alanın GERÇEK değerini oku ve extracted_data.report_information içine {"key": "<aynı key>", "value": "<PDF'den okunan gerçek metin>"} olarak ekle. Alan PDF'de gerçekten yoksa/okunamıyorsa value: null yaz, o key'i atlama. Bu değerler template'teki pattern'lerden BAĞIMSIZ olarak senin PDF'i okuyup verdiğin gerçek cevaplardır.

2. TESİSAT / PROJE BİLGİLERİ
PDF'de tesisata veya projeye ait bilgi bölümlerini tespit et.
Bölüm başlıklarını template.facility_or_project_information.section_heading_patterns içine koy.
Bu bölümde gerçekten bulunan dolu alanların/kolonların başlıklarını fields içinde keşfet (template'e sadece pattern, değer yazma).

AYRICA: bölüm 1'deki gibi, her facility fields[].key için PDF'de o alanın GERÇEK değerini oku ve extracted_data.facility_information içine {"key": "<aynı key>", "value": "<PDF'den okunan gerçek metin>"} olarak ekle. Alan yoksa value: null, key'i atlama.

3. YANGIN SİSTEMLERİ — ZORUNLU KEŞİF
PDF'deki her gerçek yangın sistemi veya ayrı kontrol bölümü bir sistem olarak ele alınmalıdır.
Her sistem için fire_systems.systems içine kayıt koy.
Her kayıt:
- system_name: PDF'deki gerçek sistem/bölüm adı
- section_heading_patterns: o sistemi tanıyan gerçek bölüm başlığı patternleri
- section_detection: sistemin başlangıç, devam ve bitiş patternleri
- system_criteria: o sistemin GERÇEK, ekipmandan bağımsız kriterleri (bkz. bölüm 4)
- equipment_definitions: o sisteme ait ekipman gruplarının YAPISI (bkz. bölüm 5)

Sistemleri PDF'de açıkça bulabiliyorsan systems listesini boş bırakma.
Sistem adı gerçekten yoksa uydurma.
Aynı sistem birden fazla sayfada devam ediyorsa devam patternlerini belirt.

4. SİSTEM KRİTERLERİ (system_criteria) — GERÇEK DEĞER, PATTERN DEĞİL
Her sistemin EKİPMANA BAĞLI OLMAYAN kendi kontrol kriterlerini (örn. "Genel Tespit", "Belge ve Kayıt Kontrolleri" gibi bir bölümün maddeleri) system_criteria içine GERÇEK değerleriyle yaz:
system_criteria: [{"code": "<PDF'deki gerçek kod>", "text": "<PDF'deki gerçek kriter metni, TAM/KISALTILMAMIŞ>", "result": {"raw": "<hücrede GERÇEKTEN yazan metin, örn. \"U\" veya \"UD\" - OLDUĞU GİBİ, kendi yorumunla değiştirme>", "label": "<istersen kısa bir okunabilir etiket, örn. \"Uygun\" - ama raw ASIL kaynaktır>"}}]

Bu liste HER raporda sabit/sınırlı boyutludur (bir sistemin kriter sayısı ekipman sayısıyla BÜYÜMEZ), bu yüzden report_information/overall_result ile AYNI güvenle gerçek değeri doğrudan yazıyorsun.

Kod ve kriter metnini PDF'de GERÇEKTEN yazdığı gibi (kısaltmadan, uydurmadan) al. result.raw'ı KENDİ YORUMUNLA NORMALİZE ETME ("uygun"/"uygun_değil" gibi bir kelimeye çevirme) - hücrede ne yazıyorsa (örn. "U", "UD", "✔") onu aynen kopyala; normalizasyonu Camelot tarafı zaten yapacak. Sonuç net okunamıyorsa raw: null, label: null bırak, tahmin etme.
Bir sistemin ekipmana bağlı olmayan kriteri yoksa (örn. sadece equipment_definitions ile okunan bir ekipman grubuysa) o sistem için system_criteria: [] bırak - systems listesinden sistemi ÇIKARMA, sadece system_criteria boş kalır.

ÖNEMLİ AYRIM — bir kriter EKİPMANA BAĞLI MI, SİSTEME Mİ AİT: Eğer bir kriter/sonuç HER TEK EKİPMAN ÖRNEĞİ için ayrı ayrı değerlendiriliyorsa (örn. "her tüpün kendi mühür durumu", "her dolabın kendi hortum durumu" - equipment tablosunun kendi satır/sütununda tekrarlanıyor) bu BURAYA (system_criteria) YAZILMAZ, o equipment_definitions[].equipment_control_criteria'nın YAPISINI tarif eder (bkz. bölüm 5). system_criteria SADECE ekipmandan bağımsız, sistemin KENDİSİ için TEK SEFER değerlendirilen kriterleri taşır (örn. "önceki kontrol raporu var mı", "proje onaylı mı" gibi - tüm sistem için bir kez sorulur, ekipman başına tekrarlanmaz).

5. EKİPMAN GRUPLARI (equipment_definitions)
Her equipment_definitions kaydı bir ekipman TİPİNİN/GRUBUNUN yapısını tarif eder (örn. "Yangın Dolabı" tipi, o tipten kaç örnek olursa olsun TEK bir kayıt). Hangi sisteme ait olduğu zaten o sistemin equipment_definitions listesinde bulunmasından bellidir, ayrıca system_name yazmana gerek yok.

Her equipment_definitions kaydı için keşfet:
- equipment_name: ekipman tipinin gerçek adı (örn. "Yangın Dolabı", "Yangın Pompası", "Tüp")
- instance_structure: bu grubun tekrar yapısı (aşağıya bak)
- attributes: gerçek, sabit özellikler (marka, konum, ölçü gibi - kriter DEĞİL)
- equipment_control_criteria: bu ekipmana özel kontrol kriterleri (varsa)

5a. instance_structure — EKİPMAN GRUBUNUN TEKRAR YAPISI
- identity_field: her örneği ayırt eden satır/sütun etiketi (örn. "Dolap No.", "No / Kod", "Tüp No"). Bu ZORUNLU tek çapa - parser gerçek tabloyu bu etiketi Camelot verisinde arayarak bulacak.
- identity_value: SADECE equipment_axis="none" (tek örnek, aşağıya bak) İSE bu TEK örneğin gerçek kodunu/kimliğini buraya yaz. Birden fazla örnek varsa (rows/columns) null bırak - gerçek kodları Camelot okuyacak.
- identity_hint: {"pattern": "...", "validation": "soft"} - isteğe bağlı, gördüğün kodların ortak bir şekli varsa (örn. hepsi "YD" ile başlıyorsa pattern: "YD*"). "soft" demek bu SADECE bir ipucu/doğrulama yardımcısıdır, bu deseni tutturamayan gerçek bir kodu ASLA reddetme veya filtreleme - Camelot her durumda gördüğü ham metni kullanır. Ortak bir şekil yoksa pattern: null yaz.
- equipment_axis: tabloda her bir ekipman ÖRNEĞİ nerede tekrarlanıyor:
  - "rows": her SATIR bir ekipman (örn. "No. | Dolap No. | Lokasyon | ... | U. | U.D. | N.U." başlıklı bir tablo, altında bir satır = bir dolap) — DESTEKLENEN, yaygın durum.
  - "columns": her SÜTUN bir ekipman — ekipmanlar YAN YANA dizilmiş, her ÖZELLİK/KRİTER kendi SATIRINDA, o satırın değerleri tüm ekipman sütunlarına yayılmış (TRANSPOZE tablo). Gerçek, sık görülen örnek: bir satır "No / Kod" (ya da "Soru / Kriter" gibi bir blok başlığı) yazar, hemen yanında "YD1 YD2 YD3 ... YD10" gibi ekipman kodları sıralanır; altındaki satırlar "Kat", "Marka", "Uzunluk" gibi özellik adlarıyla başlar; daha altta numaralı kriter satırları (örn. "5.38 Hortumda TSE standardı varlığı") olabilir, her ekipman sütununda kendi U/UD/N değeri. Bu şekil aynı raporda 10/20/50'li BLOKLAR halinde defalarca tekrarlayabilir. BU DESTEKLENEN, YAYGIN BİR DURUMDUR — "rows" ile karıştırma. Ayırt etmenin kesin yolu: gerçek ekipman KODLARI bir SATIRIN İÇİNDE yan yana mı duruyor (→ columns) yoksa bir SÜTUNUN İÇİNDE alt alta mı duruyor (→ rows)?
  - "none": tek bir ekipman var, hiç tekrar yok (örn. tek bir panel/tank, ya da rapor zaten TEK bir makineyi konu alıyorsa - forklift/transpalet gibi). Bu durumda identity_value/attributes[].value/criteria[].result GERÇEK değerlerle doldurulur (bkz. bölüm 5b/5c) - tek örnek olduğu için hangi değerin yazılacağı belirsizliği yoktur.
- group_width: SADECE equipment_axis="columns" İKEN doldur - bir blokta kaç ekipman sütunu var (örn. 5, 10). "rows"/"none" için null (satırlar tablonun sonuna kadar doğal olarak devam eder, sabit bir blok genişliği kavramı yok).
- header_patterns: yeni bir örnek bloğunun/tablonun başladığını gösteren gerçek etiket metni/metinleri (örn. ["No / Kod"], ya da blok başlığı ile kimlik etiketi ayrı satırlardaysa ["Soru / Kriter", "Dolap No"] gibi ikisini de ekle).
- result_columns: normal olarak criteria/property OLMAYAN, iki ÖZEL sütun/satır türünü AÇIKÇA işaretlemek için kullanılır - bu alan boşsa (çoğu rapor) parser her işaretsiz sütunu/satırı kendi başına bağımsız bir kriter sayar, bu DOĞRU davranıştır ve genelde dokunmana gerek yoktur. Sadece şu 2 GERÇEK durumda doldur:
  - Tek bir sonucu 2-3 sütuna/satıra bölen checkbox seti (klasik "U. | U.D. | N.U." üçlüsü - işaret hangi sütundaysa sonuç odur, diğer ikisi boş): HER birini {"header_pattern": "<PDF'deki gerçek etiket, örn. 'U.D.'>", "kind": "fixed_value", "value": "<bu sütunda işaret varsa anlamı: 'uygun' | 'uygun_degil' | 'uygulanamiyor'>"} olarak ayrı ayrı ekle. Parser bunları OTOMATİK olarak TEK bir sonuca indirger, sen sadece hangi sütun hangi anlama geliyor onu söylüyorsun.
  - Serbest metin not/açıklama sütunu (örn. "AÇIKLAMALAR", "NOTLAR"): {"header_pattern": "<gerçek etiket>", "kind": "note", "value": null} ekle - bu metin bir kriter/sonuç DEĞİLDİR, ayrı bir not alanına gider.
  Bunların İKİSİ DE değilse (yani gerçekten N tane BAĞIMSIZ, birbirinden farklı soru/kriter sütunun/satırın varsa - örn. AKTAŞ tüp raporundaki 7 ayrı numaralı soru sütunu) result_columns'a HİÇBİR ŞEY ekleme, boş [] bırak - parser zaten her birini kendi kriteri olarak doğru okur.
- ambiguous: bu tablonun eksenini, kimlik alanını veya sınırlarını GÜVENLE belirleyemediysen true yap ve ambiguous_reason'a neyin belirsiz olduğunu yaz - Camelot/PHP bunun ötesinde TAHMİN YÜRÜTMEYECEK, bu yüzden dürüst bir "emin değilim" yanlış bir tahminden çok daha değerlidir.

5b. attributes — GERÇEK, SABİT ÖZELLİKLER (kriter DEĞİL)
Her özellik için {"field": "<özelliğin adı>", "source_pattern": "<PDF'de GERÇEKTEN gördüğün etiket metni>", "value": null} yaz. value SADECE equipment_axis="none" iken (tek örnek) gerçek değerle doldurulur, aksi halde null kalır (Camelot okuyacak).
Bir satırın "özellik" mi yoksa "kriter" mi olduğundan emin değilsen ve o satır numaralı bir soru/kontrol maddesi gibi görünüyorsa (örn. "5.38 ..."), onu attributes'a EKLEME - bölüm 5c'deki gibi otomatik kriter olarak okunacaktır.
BİLEŞİK (COMPOUND) ÖZELLİKLER: Bazı raporlarda TEK bir başlık aslında BİRDEN FAZLA ayrı özelliği tire (-) ile ayırarak listeler, örn. "Dolap Bilgileri (Makarası - Tipi - Makara Bağlantısı - Vana Tipi)" başlığı altında "FETAŞ – DUVARA MONTE ŞİBER VANA - MAKARALI" gibi 4 ayrı bilgi art arda yazılı olabilir. Bunu tek bir bulanık (blob) metin olarak bırakma — başlıktaki sıralamayı SEN oku ve o alt-özellikleri AYRI attributes girdileri olarak ekle (her biri kendi field adıyla, örn. "Makarası", "Tipi", "Makara Bağlantısı", "Vana Tipi").

5c. equipment_control_criteria — EKİPMANA ÖZEL KRİTERLER (varsa)
{"present": true|false, "criteria": [...]}
present=false: bu ekipman grubu SAF ENVANTER, hiç pass/fail kriteri yok (gerçek örnek: "Dolap No / Marka / Bulunduğu Yer / Basınç / Uzunluk" listesi, hiç sonuç sütunu YOK). criteria: [] kalır.
present=true: criteria içine PDF'de GÖREBİLDİĞİN HER kriteri {"code": "...", "text": "...", "result": {"raw": null, "label": null}} olarak ekle - kaç tane olduğunu tam saymak veya HİÇBİRİNİ kaçırmamak ZORUNDA DEĞİLSİN, parser aynı bloktaki, attributes'ta listelemediğin HER satırı/sütunu otomatik olarak ek bir kriter sayar. Önemli olan, kriterler açıkça varken criteria'yı boş bırakmamandır. result SADECE equipment_axis="none" iken (bölüm 5a) gerçek değerle ({"raw": "U", "label": "Uygun"} gibi) doldurulur - "rows"/"columns" eksenlerinde (birden fazla örnek) result HER ZAMAN {"raw": null, "label": null} kalır, çünkü YÜZLERCE farklı örneğin HER BİRİNİN kendi cevabı farklı olabilir (gerçek örnek: 20 farklı "Yangın Dolabı", her biri "5.38" kriterine kendi ayrı U/UD/N cevabını veriyor - tek bir kutuya sığmaz), bu yüzden Camelot okur.

ÖRNEK 1 (SADECE ŞEKLİ ANLatmak İÇİN) — equipment_axis="rows", çok sayıda örnek:
equipment_definitions: [{
  "equipment_name": "Yangın Dolabı",
  "instance_structure": {
    "identity_field": "Dolap No.", "identity_value": null,
    "identity_hint": {"pattern": null, "validation": "soft"},
    "equipment_axis": "rows", "group_width": null,
    "header_patterns": ["No.", "Dolap No.", "Lokasyon"],
    "result_columns": [
      {"header_pattern": "U.", "kind": "fixed_value", "value": "uygun"},
      {"header_pattern": "U.D.", "kind": "fixed_value", "value": "uygun_degil"},
      {"header_pattern": "N.U.", "kind": "fixed_value", "value": "uygulanamiyor"},
      {"header_pattern": "AÇIKLAMALAR", "kind": "note", "value": null}
    ],
    "ambiguous": false, "ambiguous_reason": null
  },
  "attributes": [
    {"field": "Lokasyon", "source_pattern": "Lokasyon", "value": null}
  ],
  "equipment_control_criteria": {"present": true, "criteria": [
    {"code": "?", "text": "?", "result": {"raw": null, "label": null}}
  ]}
}]
(Not: "U."/"U.D."/"N.U." gibi TEK bir sonucu 2-3 sabit sütuna bölen checkbox seti ile AÇIKLAMALAR/not sütunu instance_structure.result_columns içinde YUKARIDAKİ gibi AÇIKÇA işaretlenir - HER birini ayrı bir criteria girdisi yapma, criteria burada bu durumda [] de bırakılabilir. Ama ekipman satırları arasında 5'ten fazla dar, numaralı, KENDİ SORU CÜMLESİ olan sütun görüyorsan (örn. "1- Yangın söndürme cihazları mühürleri...", "2- ...") bunlar GERÇEKTEN AYRI kriterlerdir - result_columns'a EKLEME, parser her birini kendi kriteri olarak zaten doğru okur.)

ÖRNEK 2 — equipment_axis="columns" (TRANSPOZE tablo, ekipmanlar yan yana, her özellik/kriter kendi satırında; gerçek, sık görülen rapor şekli):
Diyelim PDF'de şöyle bir tablo var (10'lu bloklar halinde tekrarlıyor):
  No / Kod          | YD1      | YD2      | ... | YD10
  Kat                | 2. KAT   | 2. KAT   | ... | 1. KAT
  Marka               | Türkoğlu | Türkoğlu | ... | Türkoğlu
  5.38 Hortumda TSE standardı varlığı | U | U | ... | U
  5.39 Projede gösterilen yerde ve özellikte olması | UD | UD | ... | UD
  ... (kaç kriter satırı olduğu PDF'e göre değişir, HEPSİNİ tek tek sayma)
equipment_definitions: [{
  "equipment_name": "Yangın Dolabı",
  "instance_structure": {
    "identity_field": "No / Kod", "identity_value": null,
    "identity_hint": {"pattern": null, "validation": "soft"},
    "equipment_axis": "columns", "group_width": 10,
    "header_patterns": ["No / Kod"],
    "result_columns": [],
    "ambiguous": false, "ambiguous_reason": null
  },
  "attributes": [
    {"field": "Kat", "source_pattern": "Kat", "value": null},
    {"field": "Marka", "source_pattern": "Marka", "value": null}
  ],
  "equipment_control_criteria": {"present": true, "criteria": []}
}]
(criteria burada BOŞ - "5.38"/"5.39" gibi satırlar attributes'ta listelenmediği için parser onları OTOMATİK kriter sayacak. present=true kalmalı çünkü kriterler gerçekten var, sadece tek tek saymana gerek yok.)

ÖRNEK 3 — equipment_axis="none" (tek örnek, GERÇEK değerlerle - örn. forklift raporu ya da 2-3 pompalık bir pompa dairesi):
equipment_definitions: [{
  "equipment_name": "Transpalet",
  "instance_structure": {
    "identity_field": "Ekipman No", "identity_value": "MT24",
    "identity_hint": {"pattern": null, "validation": "soft"},
    "equipment_axis": "none", "group_width": null,
    "header_patterns": [],
    "result_columns": [],
    "ambiguous": false, "ambiguous_reason": null
  },
  "attributes": [
    {"field": "Markası", "source_pattern": "Markası", "value": "..."}
  ],
  "equipment_control_criteria": {"present": true, "criteria": [
    {"code": "1", "text": "Sicil kartı, bakım defteri...", "result": {"raw": "U", "label": "Uygun"}},
    {"code": "2", "text": "Azami kaldırma kapasitesi...", "result": {"raw": "U", "label": "Uygun"}}
  ]}
}]

6. SONUÇ SEMBOLLERİNİN LEJANTI (extracted_data.result_legend)
Her raporun kendi sembol/kısaltma seti olabilir (U/UD/N, U./U.D./N.U., U/U.D/U.Y/G gibi farklı setler farklı raporlarda görülmüştür). HEMEN HEMEN HER raporda bu kısaltmaların ne anlama geldiğini açıklayan bir LEJANT/açıklama cümlesi bulunur (tablonun hemen üstünde/altında, örn. "U: UYGUN U.D.: UYGUN DEĞİL N.U.: NUMUNEYE UYGULANAMAZ" ya da "İşaretler: U: Uygun; UD: Uygun Değil; N: Uygulaması Yok, Değerlendirme Dışı"). O cümleyi PDF'de bul ve extracted_data.result_legend içine {"code": "<PDF'deki kısaltma>", "meaning": "<PDF'deki açıklaması>"} olarak, GERÇEKTEN YAZDIĞI GİBİ kopyala. Lejant cümlesi yoksa result_legend: [] bırak - kendi tahmininle uydurma.

7. SONUÇ VE KANAAT
template.overall_result bölümünde SADECE pattern/konum tarifini keşfet (section_heading_patterns, overall_text, overall_status, camelot_extraction) — gerçek metni/durumu template'e yazma.

AYRICA: raporun nihai sonuç bölümünü ("SONUÇ VE KANAAT", "SONUÇ:", "8. SONUÇ VE KANAAT" gibi hangi başlıkla geçiyorsa) SEN oku ve extracted_data.overall_result içine gerçek değerleri yaz:
- text: nihai sonuç paragrafının PDF'deki gerçek metni (bulamıyorsan null)
- status: bu metnin ifade ettiği durum, SADECE şu 3 değerden biri: "uygun" | "uygun_degil" | "uygulanamiyor" (net değilse null; başka kelime uydurma)
Bu, template'teki pattern tarifinden BAĞIMSIZ olarak senin PDF'i okuyup verdiğin gerçek cevaptır — raporun sonuç bölümü hangi başlıkla, hangi sayfa düzeniyle geçerse geçsin doğrudan sen çıkarıyorsun.

8. FINDINGS
Findings gerçek anlamsal bulgu/tespit/kusur/eksiklik/not metinlerinden çıkarılır.
Her finding şu alanlara sahip olmalıdır:
- id
- system_name
- description
- source_pages
- severity: PDF'in KENDİSİ bu bulguyu önem derecesine göre ayırıyorsa (örn. bir "(*)" işareti "majör uygunsuzluk" anlamına geliyorsa) onu buraya yaz; rapor böyle bir ayrım yapmıyorsa null bırak, kendinden bir önem derecesi uydurma.
- ambiguous: bu bulgunun hangi sisteme ait olduğunu GÜVENLE belirleyemediysen true yap (system_name null kalsın) - tahmin etmektense dürüstçe işaretle.

Başka alan EKLEME. Özellikle affected_equipment ekleme.

Her finding'in hangi sisteme ait olduğunu bölüm başlığı, kontrol maddesi, ekipman bölümü ve bulgu metni bağlamından tespit et.
Sistem güvenilir şekilde belirlenebiliyorsa gerçek PDF sistem adını system_name olarak yaz.
Belirlenemiyorsa null kullan ve ambiguous: true yap; sistem uydurma.

Bulgu metninde ekipman kodları geçiyorsa description içinde aynen koru. Ayrı ekipman alanı oluşturma.
Aynı bulguyu ekipman sayısı kadar çoğaltma.
Aynı bulguyu tekrar ediyorsa deduplicate et.
U/UD/N veya başka sonuç hücrelerinden tek başına finding üretme.
source_pages gerçek PDF sayfalarıdır.

9. DİNAMİK TABLO PATTERNLERİ
table_hints üretirken gerçek PDF'de gördüğün tablo başlıklarını ve kolon başlıklarını keşfet.
Tablonun:
- role
- page_hints
- title_patterns
- header_patterns
- structure_type
- orientation
- equipment_axis
- control_axis
- result_binding
- repeat_block
- continuation
bilgilerini mümkün olduğunca doldur.

Camelot için güvenilir bir bounding box metinden çıkarılamıyorsa uydurma koordinat verme.

10. EVIDENCE
Önemli yapısal kararların dayanağını PDF sayfalarıyla açıkla:
- sistem başlığı
- sistem/tablo ilişkisi
- ekipman ekseni
- kontrol ekseni
- sonuç kesişimi
- tekrar eden blok
- sayfa devamlılığı

11. ÇIKARIM SINIRI
equipment_definitions SADECE ekipman gruplarının YAPISAL keşfidir. Ekipman SAYISIYLA BÜYÜYEBİLEN gerçek değerler (identity_value, attributes[].value, criteria[].result) equipment_axis="rows"/"columns" iken HER ZAMAN null kalır - Camelot bunları gerçek tablodan okur. TEK istisna equipment_axis="none" (gerçekten tek örnek) - o zaman bu alanlar GERÇEK değerlerle doldurulur.
Sisteme bağlı (ekipmandan bağımsız) kontrol kriterleri (system_criteria) pattern DEĞİL, gerçek değerdir (bölüm 4) - bunlar sistem SAYISIYLA ölçeklenir, ekipman sayısıyla değil, bu yüzden report_information ile aynı güvenle doğrudan okunur.
AI semantic'in gerçek veri kısmı: findings + report_information + facility_information + overall_result + result_legend + systems[].system_criteria + (equipment_axis="none" olan equipment_definitions kayıtlarının identity_value/attributes/criteria'sı) - bunlar HER raporda sabit/sınırlı boyutlu kısımlardır, ekipman SAYISIYLA BÜYÜK ÖLÇÜDE büyümez.

13. SON JSON SÖZLEŞMESİ
Çıktının yapısı tam olarak aşağıdaki sözleşmeye uymalıdır:
{
  "template": {
    "template_type": "...",
    "template_version": "1.0",
    "report_information": {
      "fields": [
        {"key": "report_no", "label_patterns": []},
        {"key": "company_title", "label_patterns": []},
        {"key": "address", "label_patterns": []},
        {"key": "report_date", "label_patterns": []},
        {"key": "control_date", "label_patterns": []},
        {"key": "validity_date", "label_patterns": []}
      ]
    },
    "facility_or_project_information": {
      "section_heading_patterns": [],
      "fields": [
        {"key": "...", "label_patterns": []}
      ]
    },
    "fire_systems": {
      "systems": [
        {
          "system_name": "Belge ve Kayıt Kontrolleri",
          "section_heading_patterns": [],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "system_criteria": [
            {"code": "5.1", "text": "Proje varlığı ve onayı", "result": {"raw": "U", "label": "Uygun"}},
            {"code": "5.2", "text": "Önceki periyodik kontrol raporu var mı?", "result": {"raw": "UD", "label": "Uygun Değil"}}
          ],
          "equipment_definitions": []
        },
        {
          "system_name": "Yangın Pompa Bölmesi",
          "section_heading_patterns": [],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "system_criteria": [],
          "equipment_definitions": [
            {
              "equipment_name": "Yangın Pompası",
              "instance_structure": {
                "identity_field": "Pompa No", "identity_value": null,
                "identity_hint": {"pattern": null, "validation": "soft"},
                "equipment_axis": "rows", "group_width": null,
                "header_patterns": ["Pompa No", "Marka"],
                "result_columns": [],
                "ambiguous": false, "ambiguous_reason": null
              },
              "attributes": [
                {"field": "Marka", "source_pattern": "Marka", "value": null},
                {"field": "Debi (m3/h)", "source_pattern": "Debi (m3/h)", "value": null}
              ],
              "equipment_control_criteria": {"present": false, "criteria": []}
            }
          ]
        },
        {
          "system_name": "Yangın Dolapları",
          "section_heading_patterns": [],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "system_criteria": [],
          "equipment_definitions": [
            {
              "equipment_name": "Yangın Dolabı",
              "instance_structure": {
                "identity_field": "No / Kod", "identity_value": null,
                "identity_hint": {"pattern": null, "validation": "soft"},
                "equipment_axis": "columns", "group_width": 10,
                "header_patterns": ["No / Kod"],
                "result_columns": [
                  {"header_pattern": "U.", "kind": "fixed_value", "value": "uygun"},
                  {"header_pattern": "U.D.", "kind": "fixed_value", "value": "uygun_degil"},
                  {"header_pattern": "N.U.", "kind": "fixed_value", "value": "uygulanamiyor"}
                ],
                "ambiguous": false, "ambiguous_reason": null
              },
              "attributes": [
                {"field": "Kat", "source_pattern": "Kat", "value": null}
              ],
              "equipment_control_criteria": {"present": true, "criteria": []}
            }
          ]
        },
        {
          "system_name": "Transpalet",
          "section_heading_patterns": [],
          "section_detection": {"start_heading_patterns": [], "continuation_patterns": [], "end_detection_patterns": []},
          "system_criteria": [],
          "equipment_definitions": [
            {
              "equipment_name": "Transpalet",
              "instance_structure": {
                "identity_field": "Ekipman No", "identity_value": "MT24",
                "identity_hint": {"pattern": null, "validation": "soft"},
                "equipment_axis": "none", "group_width": null,
                "header_patterns": [],
                "result_columns": [],
                "ambiguous": false, "ambiguous_reason": null
              },
              "attributes": [
                {"field": "Markası", "source_pattern": "Markası", "value": "..."}
              ],
              "equipment_control_criteria": {"present": true, "criteria": [
                {"code": "1", "text": "Sicil kartı, bakım defteri...", "result": {"raw": "U", "label": "Uygun"}},
                {"code": "2", "text": "Azami kaldırma kapasitesi...", "result": {"raw": "U", "label": "Uygun"}}
              ]}
            }
          ]
        }
      ]
    },
    "overall_result": {
      "section_heading_patterns": [],
      "overall_text": {
        "label_patterns": [],
        "value_location_patterns": [],
        "text_boundary_patterns": []
      },
      "overall_status": {
        "label_patterns": [],
        "status_patterns": [],
        "value_location_patterns": []
      },
      "camelot_extraction": {
        "section_patterns": [],
        "text_patterns": [],
        "status_patterns": [],
        "status_extraction": "dynamic"
      }
    },
    "findings_structure": {
      "system_assignment": {
        "required": true,
        "source": [],
        "fallback": null
      },
      "deduplication": {
        "enabled": true,
        "duplicate_finding_rule": "same_finding_same_system"
      },
      "finding_fields": []
    }
  },
  "extracted_data": {
    "report_category": "yangin_tesisati",
    "findings": [
      {
        "id": "finding-1",
        "system_name": null,
        "description": "...",
        "source_pages": [1],
        "severity": null,
        "ambiguous": false
      }
    ],
    "report_information": [
      {"key": "report_no", "value": "..."},
      {"key": "company_title", "value": "..."},
      {"key": "address", "value": "..."},
      {"key": "report_date", "value": "..."},
      {"key": "control_date", "value": "..."},
      {"key": "validity_date", "value": "..."}
    ],
    "facility_information": [
      {"key": "...", "value": "..."}
    ],
    "overall_result": {
      "text": "...",
      "status": "uygun"
    },
    "result_legend": [
      {"code": "U", "meaning": "Uygun"},
      {"code": "UD", "meaning": "Uygun Değil"},
      {"code": "N", "meaning": "Uygulaması Yok, Değerlendirme Dışı"}
    ]
  }
}
(fire_systems.systems dizisindeki HER sistem için: system_criteria SADECE o sistemin ekipmandan bağımsız kendi kriterleri varsa doludur, yoksa [] kalır - yukarıdaki "Yangın Pompa Bölmesi" örneğinde olduğu gibi, pompaların kendi ekipman bazlı kriterleri equipment_definitions[].equipment_control_criteria'dadır, system_criteria'ya YAZILMAZ. equipment_definitions HER sistemde olabilir; equipment_axis="rows"/"columns" olanlarda (Yangın Pompa Bölmesi, Yangın Dolapları örnekleri) identity_value/attributes[].value/criteria[].result HEP null'dur - gerçek değerleri Camelot okur. equipment_axis="none" olan TEK örnekli gruplarda (Transpalet örneği) bu alanlar GERÇEK değerlerle doludur.)

SADECE geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.
PROMPT;
    }

    private function systemHierarchyRules(): string
    {
        return <<<'PROMPT'
KRİTİK SİSTEM GRUPLAMA KURALI — BU KURAL ÖNCELİKLİDİR

PDF'de "TESPİT VE DEĞERLENDİRMELER" gibi bir ana başlık altında A, B, C, D, E, F veya benzeri ayrı kontrol grupları bulunuyorsa HER BİR AYRI KONTROL GRUBU AYRI BİR SİSTEMDİR.

Bir kontrol grubunun başlığı ile onu izleyen kontrol maddeleri arasında bağ kur. Başlık değiştiğinde sistem değişir. O başlığın altındaki kontrol kodları yalnızca o sisteme aittir; sonraki kontrol grubu başlığına kadar devam eder.

ÖRNEK MANTIK:
A başlığı → sistem A → A'nın altındaki system_criteria
B başlığı → sistem B → B'nin altındaki system_criteria
C başlığı → sistem C → C'nin altındaki system_criteria
D başlığı → sistem D → D'nin altındaki system_criteria
E başlığı → sistem E → E'nin altındaki system_criteria
F başlığı → sistem F → F'nin altındaki system_criteria

Ana rapor/tesisat başlığı, örneğin "SULU YANGIN SÖNDÜRME TESİSATI", altında ayrı kontrol grupları varsa bu 6 veya daha fazla sistemin yerine tek sistem olarak kullanılamaz. Bu tür ana başlık yalnızca raporun/tesisatın genel başlığıdır.

fire_systems.systems[] içinde her bağımsız kontrol grubu için ayrı nesne oluştur. Birden fazla kontrol grubu başlığını tek bir system_name altında birleştirme.

Her sistemin system_criteria listesine yalnızca kendi kontrol grubundaki maddeleri koy. Örneğin bir grupta 5.1-5.3, sonraki grupta 5.4-5.23 varsa bunlar iki ayrı sistem ve iki ayrı system_criteria grubudur.

EKİPMAN SİSTEM EŞLEŞTİRMESİ:
Bir ekipman tablosu belirli bir kontrol grubunun altında bulunuyorsa o ekipman tanımını (equipment_definitions) O KONTROL GRUBUNUN sistem kaydının kendi equipment_definitions listesine koy - ana tesisat başlığının sistemine DEĞİL.

FINDING SİSTEM EŞLEŞTİRMESİ:
Bir finding metni "5.40", "5.24" gibi kontrol koduyla başlıyor veya kontrol kodunu içeriyorsa önce bu kontrol kodunun hangi kontrol grubuna ait olduğunu bul. Finding.system_name olarak o grubun gerçek sistem adını kullan.

Finding yalnızca genel tesisat başlığını referans almamalıdır. Kontrol kodu ve kontrol grubu belirlenebiliyorsa ana tesisat adını fallback olarak kullanma.

Örneğin PDF'de:
- 5.1-5.3 → Belge ve Kayıt Kontrolleri
- 5.4-5.23 → Yangın Pompa Bölmesi
- 5.24-5.25 → Su Deposu Kontrolü
- 5.26-5.37 → Yağmurlama Sistemi Kontrolü
- 5.38-5.52 → Yangın Dolapları ve Hortum Sistemlerinin Kontrolü
- 5.53-5.55 → Hidrant ve İtfaiye Bağlantısı Kontrolü
ise tam olarak bu altı sistem ayrı ayrı oluşturulmalı ve findings aynı eşleşmeye göre atanmalıdır.

Bu örnekteki isimleri başka raporlara hardcode etme; kural yapıdır: HER AYRI KONTROL ITEM GRUBU = AYRI SİSTEM.
PROMPT;
    }
}
