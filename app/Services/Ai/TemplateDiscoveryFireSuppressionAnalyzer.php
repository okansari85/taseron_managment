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
1. template: PDF'den keşfedilen yapısal okuma haritası.
2. extracted_data: SADECE findings.

KESİN SINIR
extracted_data içinde findings dışında hiçbir alan bulunamaz.
AI extracted_data altında report, organization, systems, equipment, components, control_items, results, overall_result veya inventory verisi çıkarmayacak.
Gerçek rapor değerlerini Camelot çıkaracaktır.

TEMPLATE İÇİNDE GERÇEK SİSTEM BAŞLIKLARI VE YAPISAL PATTERNLER BULUNABİLİR. Bunlar veri çıkarımı değil, Camelot'un doğru bölümü ve tabloyu bulması için keşfedilmiş şablon bilgisidir.

ÖRNEKLER KURAL DEĞİLDİR
5.x, A.x, YD1, YD2, 5 kolon, U, UD, N veya herhangi bir örnek sistem adı yalnızca örnektir.
Bunları hardcode etme. PDF'de ne varsa onu keşfet. Farklı kod, ekipman adı, kolon sayısı, sonuç gösterimi veya tablo yönü varsa gerçek yapıyı kullan.

1. RAPOR GENEL BİLGİLERİ
Sadece Camelot'un bulmasına yardımcı olacak label/alan patternlerini keşfet.
Aşağıdaki dört alanı hedefle:
- company_title
- address
- report_date
- validity_date
Gerçek değerleri template'e yazma.
PDF'de kullanılan gerçek kolon/alan başlıklarını label_patterns içine koy.

2. TESİSAT / PROJE BİLGİLERİ
PDF'de tesisata veya projeye ait bilgi bölümlerini tespit et.
Bölüm başlıklarını section_heading_patterns içine koy.
Bu bölümde gerçekten bulunan dolu alanların/kolonların başlıklarını fields içinde keşfet.
Alanların gerçek değerlerini template'e yazma; Camelot çıkaracak.

3. YANGIN SİSTEMLERİ — ZORUNLU KEŞİF
PDF'deki her gerçek yangın sistemi veya ayrı kontrol bölümü bir sistem olarak ele alınmalıdır.
Her sistem için fire_systems.systems içine kayıt koy.
Her kayıt:
- system_name: PDF'deki gerçek sistem/bölüm adı
- section_heading_patterns: o sistemi tanıyan gerçek bölüm başlığı patternleri
- control_items: o sisteme ait kontrol kriterlerinin yapısı
- equipment: o sisteme ait ekipmanların yapısı
- control_matrix: sistemde kontrol x ekipman matrix'i varsa yapısı
- section_detection: sistemin başlangıç, devam ve bitiş patternleri

Sistemleri PDF'de açıkça bulabiliyorsan systems listesini boş bırakma.
Sistem adı gerçekten yoksa uydurma.
Aynı sistem birden fazla sayfada devam ediyorsa devam patternlerini belirt.

4. KONTROL MADDELERİ
Her sistemin altında o sisteme ait kontrol maddelerinin patternlerini keşfet.
- control_code_patterns: gerçek kontrol kodu patternleri
- control_text_patterns: gerçek kriter/açıklama patternleri
- result_patterns: PDF'de gerçekten görülen sonuç gösterimlerinin patternleri

Kontrol kodlarını örneklerden üretme.
Sonuç aliaslarını da varsayma.

Kontrol kodu pattern'i birden fazla basamağı kapsayan bir sayı aralığını (örn. 38-52) tek regex ile ifade edecekse DİKKAT: onlar basamağını sabitleyip birler basamağına aralık verme — örn. [4-5][0-2] YANLIŞTIR, sadece 40,41,42,50,51,52'yi eşler, 43-49'u tamamen KAÇIRIR. Onluk sınırı aşan aralıkları basamak gruplarına böl: 38-52 için doğrusu ^5\.(?:3[8-9]|4[0-9]|5[0-2])$ (38-39 | 40-49 | 50-52) şeklindedir.
Yazdığın control_code_patterns'ın gerçekte eşleştirdiği kod sayısı, aynı control_items içindeki control_text_patterns listesinin eleman sayısıyla eşit olmalıdır — kendi yazdığın pattern'i bu şekilde kontrol et, eşleşmiyorsa düzelt.

5. EKİPMANLAR
Her ekipmanın hangi sisteme ait olduğunu açıkça system_name alanında belirt.
Ekipmanların gerçek değerlerini extracted_data'ya koyma; bunlar template içinde Camelot'un okuyacağı yapısal bilgi olarak kalır.

Ekipman için keşfet:
- equipment_name
- system_name
- equipment_identity.header_patterns
- equipment_identity.identity_patterns
- tablo yönü
- tekrar eden blok olup olmadığı
- sol kolon başlık/label/cell patternleri
- sağ kolon başlık/value/cell patternleri
- Camelot'un ekipman başlığını ve değer konumunu nasıl bulacağı

Ekipman tablosu dikey iki kolonlu olabilir, yatay olabilir, tekrar eden bloklardan oluşabilir veya başka bir yapı olabilir. Önceden varsayma.

6. KONTROL KRİTERİ × EKİPMAN MATRIX
Bir sistemde kontrol kriterleri ile ekipmanların kesişiminde sonuç/durum hücreleri bulunuyorsa control_matrix.present=true yap.

ÖNEMLİ: Eksen yönünü analiz et.
- Ekipmanlar kolonlarda, kontrol kriterleri satırlarda olabilir.
- Ekipmanlar satırlarda, kontrol kriterleri kolonlarda olabilir.
- Başka bir düzen varsa onu tarif et.

control_matrix.axis_detection altında gerçek yapıyı keşfet:
- equipment_axis.axis = rows veya columns veya keşfedilen başka yön
- control_axis.axis = rows veya columns veya keşfedilen başka yön
- result_axis.location = kesişim/keşfedilen gerçek konum
- patternleri PDF'den doldur

control_matrix.matrix_relationship ile ekipman/kontrol/sonuç ilişkisini açıkça belirt.
control_matrix.camelot_extraction ile Camelot'un tabloyu nasıl okuyacağını tarif et.

Matrix yoksa present=false yap ama yapıyı yine geçerli şekilde döndür.

7. TEKİL EKİPMAN TABLOLARI
Örneğin bir ekipman bölümünde sol tarafta "Soru / Kriter", sağ tarafta değerlerin bulunduğu iki kolonlu veya tekrarlanan bir tablo olabilir.
Bu durumda left_column ve right_column patternlerini gerçek PDF'den keşfet.
Ekipmanın bağlı olduğu gerçek sistemi system_name ile yaz.

8. SONUÇ VE KANAAT
overall_result bölümünü keşfet.
- section_heading_patterns: sonuç/kanaat bölüm başlığı patternleri
- overall_text: nihai açıklama metninin bulunduğu alanın patternleri
- overall_status: nihai durum/karar ifadesinin bulunduğu alanın patternleri
- camelot_extraction: Camelot'un bu iki değeri bulma yöntemi

Gerçek overall metni veya durumunu template'e değer olarak yazma; yalnızca pattern ve konum tarifini keşfet.

9. FINDINGS
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

10. DİNAMİK TABLO PATTERNLERİ
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

11. EVIDENCE
Önemli yapısal kararların dayanağını PDF sayfalarıyla açıkla:
- sistem başlığı
- sistem/tablo ilişkisi
- ekipman ekseni
- kontrol ekseni
- sonuç kesişimi
- tekrar eden blok
- sayfa devamlılığı

12. ÇIKARIM SINIRI
Template yapısal keşif içindir.
Gerçek rapor değerleri Camelot'a bırakılır.
AI semantic'in gerçek veri kısmı yalnızca findings'tir.

13. SON JSON SÖZLEŞMESİ
Çıktının yapısı tam olarak aşağıdaki sözleşmeye uymalıdır:
{
  "template": {
    "template_type": "...",
    "template_version": "1.0",
    "report_information": {
      "fields": [
        {"key": "company_title", "label_patterns": []},
        {"key": "address", "label_patterns": []},
        {"key": "report_date", "label_patterns": []},
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
    "findings": [
      {
        "id": "finding-1",
        "system_name": null,
        "description": "...",
        "source_pages": [1]
      }
    ]
  }
}

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
