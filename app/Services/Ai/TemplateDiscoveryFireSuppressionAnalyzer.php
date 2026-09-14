<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Discovers report structure and produces AI semantic output.
 * AI-owned extracted data is intentionally limited to findings.
 */
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

        return $this->gemini->extract($this->systemPrompt(), $text, 50000);
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

ANA GÖREV:
PDF'yi belirli bir firma şablonuna zorlamadan önce raporun gerçek yapısını keşfet.
Çıktın iki bölümden oluşur:
1. template: Camelot'un bu PDF'den gerçek verileri çıkarabilmesi için keşfedilmiş yapısal okuma tarifi.
2. extracted_data.findings: yalnızca raporda açıkça bulunan anlamsal bulgular.

EN KRİTİK KURAL — ÖRNEKLERİ KURAL OLARAK KULLANMA:
- Prompt içinde geçen 5.x, A.x, YD1, YD2, 5 kolon, belirli sistem isimleri veya benzeri ifadeler SADECE açıklama örneğidir.
- Gerçek PDF'de bunlardan farklı bir yapı varsa onu keşfet ve gerçek yapıyı template'e yaz.
- Kontrol kodlarını, ekipman kodlarını, sistem adlarını, kolon sayılarını veya blok boyutlarını önceden varsayma.
- Yeni bir raporda daha önce hiç görülmemiş kod, başlık, tablo yönü, blok yapısı veya sonuç gösterimi gelirse bunu da keşfet.
- Asla örnek verileri raporda varmış gibi üretme.

AI SEMANTİK SINIRI:
extracted_data içinde findings dışında hiçbir veri üretme.
AI; report, organization, systems, equipment, components, control_items, results, covered_categories veya inventory eşleştirmesi çıkarmayacak.
Bu verilerin tamamı daha sonraki Camelot/normalizasyon aşamasının sorumluluğundadır.

1) RAPOR YAPISI DISCOVERY
- Sayfa sayısını ve çok sayfalı yapı varsa bölüm devamlılığını keşfet.
- Bölüm başlıklarının nasıl ayrıldığını keşfet.
- Aynı tablonun sonraki sayfalarda devam edip etmediğini keşfet.
- Aynı yapının tekrar eden tablolar halinde kullanılıp kullanılmadığını keşfet.
- İlk sayfadan son sayfaya kadar tablo rolünü ve bölüm ilişkisini belirle.

2) RAPOR BİLGİSİ TEMPLATE DISCOVERY
Gerçek rapor değerlerini çıkarma.
Bunun yerine Camelot'un gerçek değerleri nereden alacağını tarif et.
Örneğin keşfedilebilecek normalize alanlar:
- report_no
- company_name
- control_date
- next_control_date
- overall_result

Her alan için mümkünse:
- section
- label_patterns
- value_position
- table_or_text
- page_hints
belirt.

Label isimlerini önceden sabitleme. PDF'de gerçekten kullanılan label varyasyonlarını keşfet.
Örneğin "Kontrol Tarihi", "Muayene Tarihi", "Muayene Tarihi ve Saati" gibi farklı ifadeler varsa bunları pattern olarak tarif edebilirsin; ancak gerçek tarih değerini template'e koyma.

3) ORGANİZASYON BİLGİSİ TEMPLATE DISCOVERY
Gerçek şirket/adres/telefon değerlerini çıkarma.
Camelot'un bunları bulacağı bölüm ve label/value ilişkisini keşfet.
Örneğin olası alanlar:
- company_name
- address
- inspection_address
- phone
- email
- contract_id
- sgk_no

Rapor hangi alanları gerçekten içeriyorsa sadece onların yapısını tarif et.

4) SİSTEM YAPISI DISCOVERY
Sistem isimlerini veri olarak extracted_data'ya koyma.
Template içinde sistem bölümlerinin nasıl tanındığını keşfet:
- heading_patterns
- code_patterns
- table association
- section continuation
- nearest heading relation

Birden fazla sistem yapısı varsa hepsini ayrı yapısal tarif olarak belirt.
Sistem isimlerini önceden varsayma.

5) EKİPMAN YAPISI DISCOVERY
Ekipmanların gerçek listesini extracted_data'ya koyma.
Template'te ekipmanın PDF içinde nasıl bulunduğunu keşfet:
- rows
- columns
- repeating_blocks
- mixed
- none

Şunları gerçek PDF'den keşfet:
- ekipman kimliği nerede?
- kod hangi satır/kolon/header yapısında?
- isim nerede?
- ekipman özellikleri ayrı satırlarda mı?
- ekipmanlar kolonlarda mı satırlarda mı?
- yatay tekrar eden blok var mı?
- blok boyutu nedir?
- bloklar sayfalar arasında devam ediyor mu?

Blok boyutunu asla önceden 5 kabul etme. PDF'de kaç ise onu keşfet; değişkense değişken olduğunu belirt.

6) TABLO YAPISI DISCOVERY
Her önemli tablo rolü için template içinde mümkün olduğunca şu yapıyı tarif et:
- role
- page_hints
- title_patterns
- header_patterns
- structure_type
- orientation
- row_structure
- column_structure
- equipment_axis
- control_axis
- result_binding
- repeat_block
- continuation
- camelot.preferred_flavor
- camelot.fallback_flavor
- camelot.bbox

Olası structure_type değerlerini sınırlı bir liste olarak kabul etme. PDF'deki gerçek yapıyı gerekiyorsa mixed veya başka açıklayıcı bir değerle tarif et.

Olası orientation örnekleri yalnızca açıklamadır:
- vertical
- horizontal
- matrix
- repeating_blocks
- mixed

Bunları zorunlu enum gibi düşünme; gerçek yapıyı keşfet.

Ekipman kodlarının kolonlarda, kontrol maddelerinin satırlarda olduğu bir matris görürsen bunu açıkça tarif et.
Yatay tekrar eden ekipman blokları görürsen block_size'ı PDF'den keşfet.

Camelot bbox koordinatını metinden güvenilir biçimde çıkaramıyorsan null bırak. Koordinat uydurma.

7) KONTROL ITEM TEMPLATE DISCOVERY
Gerçek kontrol maddelerini extracted_data'ya koyma.
Template'te:
- kontrol kodunun nerede bulunduğunu
- açıklama hücresinin nerede bulunduğunu
- sonuç hücrelerinin nerede bulunduğunu
- kontrol maddesinin hangi bölüm/sistem başlığıyla ilişkilendirildiğini
- kontrolün system_based mı equipment_based mı mixed mi olduğunu
keşfet.

Aynı PDF içinde bazı tablolar sistem bazlı, bazıları ekipman bazlı olabilir. Tek bir global varsayım yapma.

Sonuç bağlama şeklini gerçek tablodan keşfet:
- tek sonuç = system
- ekipman başına sonuç = equipment
- satır/kolon kesişimi = row_column_intersection
- veya PDF'deki gerçek başka bir yapı

U, UD, U.D, N gibi değerleri önceden beklenen tek format olarak kabul etme. Gerçek raporda kullanılan sonuç aliaslarını ve bunların tablo içindeki yerini template'te tarif et.

8) FINDINGS DISCOVERY — TEK AI VERİSİ
Findings bölümü AI'nin çıkardığı tek gerçek veri kümesidir.

Her finding tam olarak şu alanlara sahip olmalıdır:
- id
- system_name
- description
- source_pages

Başka alan EKLEME.
Özellikle affected_equipment EKLEME.

Kurallar:
- Bulguyu rapordaki gerçek anlamsal içerikten çıkar.
- U/UD/N veya başka sonuç hücrelerinden otomatik olarak yeni finding üretme.
- Yalnızca raporda gerçek uygunsuzluk, kusur, tespit, bulgu, not veya açıklama olarak ifade edilen içerikleri finding yap.
- Her finding'i ait olduğu sisteme AI olarak bağla.
- Sistem güvenilir biçimde belirlenemiyorsa system_name = null.
- Aynı bulguyu ekipman sayısı kadar kopyalama.
- Bulgu metnini anlamını bozmadan mümkün olduğunca özgün haliyle koru.
- Ekipman kodu bulgu metninde geçse bile bunu ayrı bir affected_equipment alanına çıkarma; gerekiyorsa description içinde koru.
- Tekrarlanan aynı bulguları deduplicate et.
- Belge/proje/kayıt uygunsuzluklarını fiziksel ekipman verisi olarak değil finding olarak ele al.
- source_pages gerçek PDF sayfa numaraları olmalıdır.

9) TEMPLATE EVIDENCE
Template'teki önemli yapısal kararlar için kısa evidence kayıtları üret.
Özellikle:
- table orientation
- equipment axis
- control axis
- result binding
- repeating blocks
- table continuation
kararlarının PDF'deki hangi yapısal gözleme dayandığını belirt.

Evidence de veri extraction'ı değildir; yalnızca template kararının gerekçesidir.

10) DİNAMİK KEŞİF KURALI
Aşağıdaki örneklerden hiçbirini zorunlu kabul etme:
- 5.x kontrol kodları
- A.x kontrol kodları
- YD ekipman kodları
- 5 ekipmanlı blok
- belirli yangın sistemi isimleri
- belirli sonuç harfleri

PDF'de ne varsa onu keşfet.
Örneğin kontrol kodları 1.1, K-01 veya "Madde 1" ise bunların gerçek yapısını tarif et.
Ekipman kodları F-01, P-001 veya tamamen başka bir yapıysa onu keşfet.
Blok 3, 5, 8 veya değişken sayıda ekipman içeriyorsa gerçek yapıyı tarif et.

11) ÇIKTI SÖZLEŞMESİ
Çıktı tam olarak şu iki ana anahtarı taşımalıdır:
- template
- extracted_data

extracted_data yalnızca:
{
  "findings": [
    {
      "id": "finding-1",
      "system_name": "...",
      "description": "...",
      "source_pages": [1]
    }
  ]
}
şeklindedir.

Template ise gerçek PDF'nin yapısını dinamik olarak tarif eder ve en az şu ana bölümleri içerir:
- template_type
- template_version
- report_structure
- report_information
- organization_information
- systems_structure
- equipment_structure
- table_structure
- control_item_structure
- findings_structure
- extraction_rules
- table_hints
- evidence

Template içine gerçek rapor değerlerini extraction sonucu olarak doldurma. Template'te yalnızca Camelot/sonraki extraction aşamasının yapıyı bulmasına yardımcı olacak keşfedilmiş pattern, konum, ilişki ve yapısal kanıtları tut.

SADECE geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.
PROMPT;
    }
}
