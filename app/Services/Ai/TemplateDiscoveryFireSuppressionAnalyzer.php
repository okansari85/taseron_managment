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
1. template: PDF'den keşfedilen yapısal okuma haritası (SADECE ekipman tabloları için - bkz. bölüm 5/5b).
2. extracted_data: report_category + findings + report_information + facility_information + overall_result + systems (her sistemin GERÇEK, ekipmana bağlı olmayan kontrol kriterleri) + (SINIRLI sayıda ekipman varsa) equipment (bunlar GERÇEK değerlerdir, aşağıya bak).

0. RAPOR TİPİ SINIFLANDIRMASI (extracted_data.report_category) — HER ŞEYDEN ÖNCE karar ver
Bu sistemde 4 farklı rapor tipi var, aynı tek yükleme akışından geçiyorlar ama farklı hedeflere kaydediliyorlar. PDF'in HANGİ tipte olduğunu extracted_data.report_category'ye TAM OLARAK şu 4 değerden biriyle yaz:
- "tekli_ekipman": Rapor TEK bir makineyi/ekipmanı konu alıyor (forklift, transpalet, vinç, basınçlı kap gibi) — raporun tamamı o TEK ekipmanın muayenesi, tablo/shape'e gerek yok, her şeyi doğrudan sen okursun (bkz. bölüm 5 istisnası).
- "ysc": Taşınabilir yangın söndürme cihazı (tüp) raporu — onlarca/yüzlerce tüp listeleyen bir tablo formatı (örn. "Cihaz Bazlı Tespit" tablosu, Tüp No/Cihaz Tipi/Dolum Tarihi/Değerlendirme sütunlarıyla). Sayı SINIRSIZ olabileceği için table_shape ŞARTTIR, asla extracted_data.equipment'a tek tek yazma.
- "yangin_tesisati": Bina/tesisat geneli yangın SÖNDÜRME sistemleri raporu — birden fazla SİSTEM içerir (yangın dolapları, pompa dairesi, hidrant, sprinkler, gazlı söndürme, sabit boru tesisatı gibi), her sistemin kendi kontrol kriterleri ve ekipman listesi olabilir.
- "yangin_algilama": Yangın ALGILAMA ve uyarı/alarm sistemi raporu (dedektör, alarm paneli, ihbar butonu gibi) — bu "yangin_tesisati" değildir, KENDİ AYRI kategorisidir, tesisata dahil etme.
Emin olamadığın durumlarda raporun ana konusuna (tek makine mi, tüp listesi mi, çoklu sistem mi, algılama mı) bak ve en uygun olanı seç — bu alan HER ZAMAN doldurulmalı, null bırakılamaz.

KESİN SINIR
extracted_data içinde report_category, findings, report_information, facility_information, overall_result, systems, equipment dışında hiçbir alan bulunamaz.
AI extracted_data altında components, results veya inventory verisi çıkarmayacak — bunlar EKİPMANA bağlı, rapor uzunluğuyla (ekipman sayısıyla) BÜYÜYEBİLEN kısımlardır, Camelot template'teki equipment[].table_shape tarifine göre çıkaracaktır (bölüm 5/5b).
report_information, facility_information, overall_result VE systems[].control_items HER raporda sabit/sınırlı boyutludur (birkaç alan / bir sonuç paragrafı / sistem başına birkaç-birkaç-on kriter — ekipman SAYISIYLA değil sistem SAYISIYLA ölçeklenir) — büyüklüğü rapor uzunluğuyla ARTMAZ, bu yüzden gerçek değerini SEN (AI) doğrudan okuyup yazacaksın; Camelot'un belirsiz hücre-komşuluğu tahminine, pattern eşleştirmesine veya bölüm başlığı varyasyonlarına (SONUÇ: / SONUÇ VE KANAAT gibi) bırakmıyoruz.
extracted_data.equipment İSTİSNAİDİR ve SADECE o equipment grubu gerçekten SINIRLI sayıda ise (bkz. bölüm 5) kullanılır — dolap/hidrant gibi potansiyel olarak çok sayıda olabilecek gruplar için KESİNLİKLE kullanma, onlar table_shape ile kalır.

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
- equipment: o sisteme ait ekipmanların yapısı (SADECE SINIRSIZ/ÇOK OLABİLİR gruplar için table_shape - bkz. bölüm 5)
- section_detection: sistemin başlangıç, devam ve bitiş patternleri

Sistemleri PDF'de açıkça bulabiliyorsan systems listesini boş bırakma.
Sistem adı gerçekten yoksa uydurma.
Aynı sistem birden fazla sayfada devam ediyorsa devam patternlerini belirt.

4. KONTROL MADDELERİ — ARTIK PATTERN DEĞİL, GERÇEK DEĞER
Her sistemin EKİPMANA BAĞLI OLMAYAN kendi kontrol kriterlerini (örn. "Genel Tespit", "Belge ve Kayıt Kontrolleri" gibi bir bölümün maddeleri) template'e pattern olarak YAZMA — bunun yerine GERÇEK kod/kriter/sonuç değerlerini doğrudan SEN oku ve extracted_data.systems[] içine yaz:
extracted_data.systems: [{"system_name": "<template'teki AYNI system_name>", "control_items": [{"code": "<PDF'deki gerçek kod>", "criterion": "<PDF'deki gerçek kriter metni, TAM/KISALTILMAMIŞ>", "result": "uygun"|"uygun_degil"|"uygulanamiyor"|null}]}]

Bu liste HER raporda sabit/sınırlı boyutludur (bir sistemin kriter sayısı ekipman sayısıyla BÜYÜMEZ - 10 madde de olsa 40 madde de olsa senin için okuması aynı derecede ucuzdur), bu yüzden report_information/overall_result ile AYNI güvenle gerçek değeri doğrudan yazıyorsun - pattern/regex YAZMANA gerek YOK, bu da kendi çıktını küçültür.

Kod ve kriter metnini PDF'de GERÇEKTEN yazdığı gibi (kısaltmadan, uydurmadan) al. Sonuç net değilse null bırak, tahmin etme.
Bir sistemin ekipmana bağlı olmayan kriteri yoksa (örn. sadece equipment[].table_shape ile okunan bir ekipman grubuysa) o sistem için control_items: [] bırak - systems listesinden sistemi ÇIKARMA, sadece control_items boş kalır.

ÖNEMLİ AYRIM — bir kriter EKİPMANA BAĞLI MI, SİSTEME Mİ AİT: Eğer bir kriter/sonuç HER TEK EKİPMAN ÖRNEĞİ için ayrı ayrı değerlendiriliyorsa (örn. "her tüpün kendi mühür durumu", "her dolabın kendi hortum durumu" - equipment tablosunun kendi satır/sütununda tekrarlanıyor) bu BURAYA (extracted_data.systems) YAZILMAZ, o equipment[].table_shape'in kendi result sütunlarından gelir (bkz. bölüm 5b). extracted_data.systems SADECE ekipmandan bağımsız, sistemin KENDİSİ için TEK SEFER değerlendirilen kriterleri taşır (örn. "önceki kontrol raporu var mı", "proje onaylı mı" gibi - tüm sistem için bir kez sorulur, ekipman başına tekrarlanmaz).

İSTİSNA — TEK EKİPMANLI RAPORLAR: Eğer rapor TEK bir ekipmanı konu alıyorsa (örn. bir forklift/transpalet raporunda "KONTROL KRİTERLERİ VE TESTLER" listesi ayrı bir tesis checklist'i değil, doğrudan o TEK makinenin muayenesidir), bu maddeleri extracted_data.systems'a DEĞİL, bölüm 5'teki extracted_data.equipment[].control_items içine GERÇEK değerlerle yaz (kod, kriter metni, sonuç).

5. EKİPMANLAR
Her ekipmanın hangi sisteme ait olduğunu açıkça system_name alanında belirt.

ÖNCE KARAR VER: bu ekipman grubu SINIRLI SAYIDA mı (rahatça sayabildiğin, tek haneli - iki haneli başında birkaç örnek, örn. bir pompa dairesindeki 2-4 pompa) yoksa SINIRSIZ/ÇOK OLABİLİR mi (dolap, hidrant, sprinkler başlığı gibi - 5 de olabilir 300 de, sayfalarca sürebilir)?

- SINIRSIZ/ÇOK OLABİLİR → gerçek değerleri extracted_data'ya YAZMA. Sadece template.equipment[].table_shape ile YAPIYI tarif et (aşağıdaki 5b), Camelot gerçek verileri okuyacak. Bu, rapor uzunluğundan bağımsız SABİT boyutlu bir çıktı sağlar.
- SINIRLI SAYIDA (özellikle tablo düzeni tuhafsa - örn. "Proje Değeri / Uygulama Değeri" gibi ikiye bölünmüş sütunlar, birleştirilmiş hücreler, sabit bir kolon-rol şemasıyla genelleştirmesi zor bir yapı) → bu durumda GERÇEK değerleri SEN oku ve extracted_data.equipment içine yaz (her örnek için: system_name, equipment_name, code, properties: [{key,value},...], result, control_items: [{code,criterion,result},...], source_pages). Bu az sayıda öğe için ucuzdur ve senin okuma yeteneğin (örn. "Proje Değeri" sütununu görmezden gel, sadece "Uygulama Değeri"ni al) hiçbir sabit kod şemasının yakalayamayacağı bir esneklik sağlar. Bu durumda o ekipman için table_shape'i boş/ilgisiz bırakabilirsin (yine de required alan olduğu için instance_axis'i uygun bir değerle, columns[] boş dizi olarak doldur).
  - Bu ekipmanın KENDİ muayene/kontrol listesi varsa (rapor TEK bir ekipmanı konu alıyorsa - örn. forklift/transpalet raporundaki "KONTROL KRİTERLERİ VE TESTLER"), o listeyi de BURADA control_items içine GERÇEK kod/kriter/sonuç değerleriyle yaz - template.control_items'a pattern olarak YAZMA (bkz. bölüm 4'teki istisna). Bu ekipmanın kendi checklist'i yoksa (çoğu durumda) control_items: [] bırak.

Ekipman için keşfet:
- equipment_name
- system_name
- table_shape (aşağıdaki 5b) — SINIRSIZ/ÇOK OLABİLİR durumundaki tek gerçek yapı tarifi budur, başka alan yok.

5b. table_shape — EKİPMAN TABLOSUNUN ROL HARİTASI (asıl okuma bu alandan yapılacak, SADECE SINIRSIZ/ÇOK OLABİLİR durumunda doldurulur)
Camelot zaten hücrelerin İÇERİĞİNİ okuyacak (koordinat/sütun tespiti dahil, ayrıca yapma). Senin işin SADECE tablonun YAPISINI tarif etmek: hangi satır/sütun hangi ROLE sahip. Gerçek ekipman verisini (kaç tane olduğunu, kodlarını, sonuçlarını) BURAYA YAZMA — sadece yapıyı tarif et, liste büyüklüğünden bağımsız SABİT boyutlu bir çıktı olmalı.

table_shape.instance_axis — tabloda her bir ekipman ÖRNEĞİ nerede tekrarlanıyor:
- "rows": her SATIR bir ekipman (örn. "No. | Dolap No. | Lokasyon | ... | U. | U.D. | N.U." başlıklı bir tablo, altında bir satır = bir dolap) — DESTEKLENEN, yaygın durum.
- "none": tek bir ekipman var, hiç tekrar yok (örn. tek bir panel/tank) — DESTEKLENEN.
- "columns" / "separate_blocks": ekipman sütun sütun veya her biri kendi ayrı küçük tablosunda yayılmış — bunların okuyucusu YOK. Gerçek tabloda bu durumla karşılaşırsan ÖNCE bölüm 5'teki SINIRLI/SINIRSIZ kararına geri dön: çoğu zaman bu yerleşimdeki ekipmanlar zaten SINIRLI sayıdadır (pompa gibi) — o zaman table_shape'i hiç kullanma, extracted_data.equipment ile direkt oku. Gerçekten SINIRSIZ VE sütun/blok yerleşimliyse (nadir), yine de en yakın tahminle "columns"/"separate_blocks" yaz — okunamaz ama en azından durum kaydedilmiş olur.

table_shape.header_row_patterns: instance_axis=rows ise, sütun başlıklarının olduğu satırı bulmaya yarayan pattern'ler (örn. "No.", "Dolap No.", "Lokasyon"). Diğer axis'lerde boş bırakılabilir.

table_shape.columns: HER sütuna/satıra (instance_axis'e göre) TEK bir role ata — role SADECE şu 4 değerden biri olabilir (başka kelime uydurma):
- "identity": ekipmanın kimlik/kod sütunu (örn. "Dolap No.", "Pompa No."). key ve value null.
- "property": serbest bir özellik (marka, lokasyon, tip, ölçü vb). key = sütun başlığının kendisi, value null.
- "result": bir sonuç/durum sütunu. İki alt durum var, aşağıdaki "SONUÇ SÜTUNLARI" bölümüne bak.
- "note": serbest metin açıklama/tespit sütunu (örn. "AÇIKLAMALAR"). key ve value null.

column_index — ZORUNLU, HER columns girdisi için:
PDF'deki gerçek header satırını soldan sağa say (ilk fiziksel sütun = 0, ikincisi = 1, ...) ve bu sütunun gerçek pozisyonunu column_index'e yaz. Bu, sütunu bulmanın ASIL yoludur — header_patterns sadece yedek/ek bilgi. Özellikle kısa ve birbirine benzer başlıklarda (örn. "U." / "U.D." / "N.U." — "U." metni "U.D."nin içinde de geçtiği için metin araması burada YANILTICI ve GÜVENİLMEZDİR) column_index'i doğru saymak KRİTİKTİR, aksi halde parser yanlış sütunu okur. header_patterns'ı yine de PDF'de gerçekten görülen başlık metniyle doldur (boş bırakma).

SONUÇ (result) SÜTUNLARI — İKİ FARKLI DURUM VAR, HANGİSİ OLDUĞUNU PDF'DEN AYIRT ET:
A) Her durum için AYRI bir sütun (checkbox tarzı) — örn. "U." "U.D." "N.U." üç ayrı sütun, her satırda sadece BİRİNDE işaret (✓) var. Bu durumda HER sütun için AYRI bir "result" girdisi yaz, value = o sütunun temsil ettiği SABİT durum: "uygun" | "uygun_degil" | "uygulanamiyor".
B) TEK bir sütun var ("Durum" gibi) ve içinde HER SATIRDA DEĞİŞEN bir kod metni yazıyor (örn. bazı satırda "U", bazısında "UD", bazısında "N"). Bu durumda TEK bir "result" girdisi yaz, value: null bırak (SABİT değer YOK, satırdan satıra değişiyor) — parser o sütunun her satırdaki ham metnini okuyup kendisi yorumlayacak.
Örnek B: {"role": "result", "column_index": 5, "header_patterns": ["Durum"], "key": null, "value": null, "sub_keys": []}
Hangi durumun geçerli olduğuna PDF'deki gerçek tabloya bakarak karar ver — örneklerden tahmin etme.

value'yu KENDİ TAHMİNİNLE doldurma — HEMEN HEMEN HER raporda bu kısaltmaların ne anlama geldiğini açıklayan bir LEJANT/açıklama cümlesi bulunur (tablonun hemen üstünde/altında, örn. "U: UYGUN U.D.: UYGUN DEĞİL N.U.: NUMUNEYE UYGULANAMAZ" ya da "İşaretler: U: Uygun; UD: Uygun Değil; N: Uygulaması Yok, Değerlendirme Dışı"). O cümleyi PDF'de bul ve value'yu ORADA yazana göre eşleştir (uygun/uygun değil/uygulanamaz-uygulaması yok gibi ifadeler sırasıyla "uygun"/"uygun_degil"/"uygulanamiyor"a karşılık gelir). Lejant cümlesi yoksa ancak o zaman sütun başlığının kendisinden mantıklı çıkarım yap.

BİLEŞİK (COMPOUND) PROPERTY SÜTUNLARI — sub_keys:
Bazı raporlarda TEK bir sütun başlığı aslında BİRDEN FAZLA ayrı özelliği tire (-) ile ayırarak listeler, örn. "Dolap Bilgileri (Makarası - Tipi -Makara Bağlantısı - Vana Tipi)" başlığı altında her satırda TEK bir hücrede "FETAŞ – DUVARA MONTE ŞİBER VANA - MAKARALI" gibi 4 ayrı bilgi art arda yazılı olabilir. Bunu tek bir bulanık (blob) metin olarak bırakma — başlıktaki sıralamayı SEN oku ve o alt-özelliklerin gerçek adlarını sırasıyla sub_keys içine yaz (örn. ["Makarası", "Tipi", "Makara Bağlantısı", "Vana Tipi"]). Parser her satırın hücresini bu sırayla tire/satır sonu ayırıcılarına göre bölüp her parçayı doğru alt-özelliğe atayacak. Sütun bileşik DEĞİLSE (tek bir düz özellikse, çoğunlukla bu durum) sub_keys'i boş dizi [] bırak.

ÖRNEK (SADECE ŞEKLİ ANLATMAK İÇİN, PDF'deki gerçek başlıklara göre değişir):
table_shape: {
  "instance_axis": "rows",
  "header_row_patterns": ["No.", "Dolap No.", "Lokasyon"],
  "columns": [
    {"role": "identity", "column_index": 1, "header_patterns": ["Dolap No."], "key": null, "value": null, "sub_keys": []},
    {"role": "property", "column_index": 2, "header_patterns": ["Lokasyon"], "key": "Lokasyon", "value": null, "sub_keys": []},
    {"role": "property", "column_index": 3, "header_patterns": ["Dolap Bilgileri (Makarası - Tipi -Makara Bağlantısı - Vana Tipi)"], "key": "Dolap Bilgileri", "value": null, "sub_keys": ["Makarası", "Tipi", "Makara Bağlantısı", "Vana Tipi"]},
    {"role": "result", "column_index": 4, "header_patterns": ["U."], "key": null, "value": "uygun", "sub_keys": []},
    {"role": "result", "column_index": 5, "header_patterns": ["U.D."], "key": null, "value": "uygun_degil", "sub_keys": []},
    {"role": "result", "column_index": 6, "header_patterns": ["N.U."], "key": null, "value": "uygulanamiyor", "sub_keys": []},
    {"role": "note", "column_index": 7, "header_patterns": ["AÇIKLAMALAR"], "key": null, "value": null, "sub_keys": []}
  ]
}
(column_index=0 burada "No." sütunudur — tabloda gerçekten var ama columns listesine dahil edilmemiştir, çünkü kullanılan bir role karşılık gelmiyor. Yine de saymaya 0'dan başlanır, o sütun atlanmaz.)
Bu SADECE ŞEKLİ gösterir — gerçek sütun sayısı, başlıkları, kaç "result" satırı olduğu ve hangi sütunun bileşik olduğu tamamen PDF'e göre değişir. instance_axis=none ise columns sadece "property"/"note" rolündeki alanları listeler (identity/result olmaz).

6. TEKİL EKİPMAN TABLOLARI
Bir ekipman bölümü tek bir örnek içeriyorsa (ör. sol tarafta "Soru / Kriter", sağ tarafta tek bir değerler sütunu) bu, table_shape.instance_axis="none" durumudur — 5b'deki kurallara göre tarif et.
Ekipmanın bağlı olduğu gerçek sistemi system_name ile yaz.

7. SONUÇ VE KANAAT
template.overall_result bölümünde SADECE pattern/konum tarifini keşfet (section_heading_patterns, overall_text, overall_status, camelot_extraction) — gerçek metni/durumu template'e yazma.

AYRICA: raporun nihai sonuç bölümünü ("SONUÇ VE KANAAT", "SONUÇ:", "8. SONUÇ VE KANAAT" gibi hangi başlıkla geçiyorsa) SEN oku ve extracted_data.overall_result içine gerçek değerleri yaz:
- text: nihai sonuç paragrafının PDF'deki gerçek metni (bulamıyorsan null)
- status: bu metnin ifade ettiği durum, SADECE şu 3 değerden biri: "uygun" | "uygun_degil" | "uygulanamiyor" (net değilse null; başka kelime uydurma)
Bu, template'teki pattern tarifinden BAĞIMSIZ olarak senin PDF'i okuyup verdiğin gerçek cevaptır — raporun sonuç bölümü hangi başlıkla, hangi sayfa düzeniyle geçerse geçsin doğrudan sen çıkarıyorsun.

8. FINDINGS
Findings gerçek anlamsal bulgu/tespit/kusur/eksiklik/not metinlerinden çıkarılır.
Her finding TAM OLARAK şu alanlara sahip olmalıdır:
- id
- system_name
- description
- source_pages

Başka alan EKLEME. Özellikle affected_equipment ekleme.

Her finding'in hangi sisteme ait olduğunu bölüm başlığı, kontrol maddesi, ekipman bölümü ve bulgu metni bağlamından tespit et.
Sistem güvenilir şekilde belirlenebiliyorsa gerçek PDF sistem adını system_name olarak yaz.
Belirlenemiyorsa null kullan; sistem uydurma.

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
Template SADECE ekipman tablolarının yapısal keşfi içindir (equipment[].table_shape).
Ekipmana bağlı, rapor uzunluğuyla (ekipman SAYISIYLA) BÜYÜYEBİLEN gerçek değerler Camelot'a bırakılır — equipment[].table_shape ile SADECE yapı tarif edilir, TEK istisna: sayıca SINIRLI bir ekipman grubuysa (bölüm 5'e bak) gerçek değerlerini extracted_data.equipment'a yazabilirsin.
Sisteme bağlı (ekipmandan bağımsız) kontrol kriterleri ARTIK pattern DEĞİL, gerçek değerdir (bölüm 4) - bunlar sistem SAYISIYLA ölçeklenir, ekipman sayısıyla değil, bu yüzden report_information ile aynı güvenle doğrudan okunur.
AI semantic'in gerçek veri kısmı: findings + report_information + facility_information + overall_result + systems[].control_items + (sınırlıysa) equipment (bunlar HER raporda sabit/sınırlı boyutlu kısımlardır — ekipman SAYISIYLA BÜYÜK ÖLÇÜDE büyümez).

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
      "systems": []
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
        "source_pages": [1]
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
    "systems": [
      {
        "system_name": "Belge ve Kayıt Kontrolleri",
        "control_items": [
          {"code": "5.1", "criterion": "Proje varlığı ve onayı", "result": "uygun"},
          {"code": "5.2", "criterion": "Önceki periyodik kontrol raporu var mı?", "result": "uygun_degil"}
        ]
      },
      {
        "system_name": "Yangın Pompaları",
        "control_items": []
      }
    ],
    "equipment": [
      {
        "system_name": "Yangın Pompaları",
        "equipment_name": "Yangın Pompası",
        "code": "1",
        "properties": [
          {"key": "Marka", "value": "..."},
          {"key": "Debi (m3/h)", "value": "..."}
        ],
        "result": null,
        "control_items": [],
        "source_pages": [1]
      },
      {
        "system_name": "Transpalet",
        "equipment_name": "Transpalet",
        "code": "MT24",
        "properties": [
          {"key": "Markası", "value": "..."}
        ],
        "result": null,
        "control_items": [
          {"code": "1", "criterion": "Sicil kartı, bakım defteri...", "result": "uygun"},
          {"code": "2", "criterion": "Azami kaldırma kapasitesi...", "result": "uygun"}
        ],
        "source_pages": [1]
      }
    ]
  }
}
(systems dizisi template.fire_systems.systems'teki HER sistem için bir kayıt içerir - control_items SADECE o sistemin ekipmandan bağımsız kendi kriterleri varsa doludur, yoksa [] kalır (yukarıdaki "Yangın Pompaları" örneğinde olduğu gibi - pompaların kendi ekipman bazlı kriterleri equipment[].control_items'tadır, systems'a YAZILMAZ). equipment dizisi SADECE sınırlı sayıda ekipman grupları için doludur - dolap/hidrant gibi grupları buraya YAZMA, boş bırak, onlar table_shape'ten gelir. control_items alt-dizisi de SADECE bu ekipmanın kendi muayene listesi varsa doludur - yukarıdaki pompa örneğinde olduğu gibi çoğu ekipmanda boş [] kalır.)

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
A başlığı → sistem A → A'nın altındaki control_items
B başlığı → sistem B → B'nin altındaki control_items
C başlığı → sistem C → C'nin altındaki control_items
D başlığı → sistem D → D'nin altındaki control_items
E başlığı → sistem E → E'nin altındaki control_items
F başlığı → sistem F → F'nin altındaki control_items

Ana rapor/tesisat başlığı, örneğin "SULU YANGIN SÖNDÜRME TESİSATI", altında ayrı kontrol grupları varsa bu 6 veya daha fazla sistemin yerine tek sistem olarak kullanılamaz. Bu tür ana başlık yalnızca raporun/tesisatın genel başlığıdır.

fire_systems.systems[] içinde her bağımsız kontrol grubu için ayrı nesne oluştur. Birden fazla kontrol grubu başlığını tek bir system_name altında birleştirme.

Her sistemin control_items listesine yalnızca kendi kontrol grubundaki maddeleri koy. Örneğin bir grupta 5.1-5.3, sonraki grupta 5.4-5.23 varsa bunlar iki ayrı sistem ve iki ayrı control_items grubudur.

EKİPMAN SİSTEM EŞLEŞTİRMESİ:
Bir ekipman tablosu belirli bir kontrol grubunun altında bulunuyorsa equipment.system_name o kontrol grubunun sistem adı olmalıdır. Ana tesisat başlığını equipment.system_name olarak kullanma.

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
