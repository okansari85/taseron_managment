<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Discovers report structure and produces the stable target JSON in one AI pass.
 *
 * This intentionally lives beside the existing analyzer so the old extraction
 * path can remain available while the new template-driven path is tested.
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
            if ($page === '') continue;
            $parts[] = '--- SAYFA ' . ($index + 1) . " ---\n" . $page;
        }

        return implode("\n\n", $parts);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Sen yangın tesisatı periyodik kontrol raporları için çalışan bir TEMPLATE DISCOVERY motorusun.

ANA GÖREV:
PDF'nin tek bir firma şablonuna ait olduğunu varsayma. Önce raporun yapısını keşfet, sonra aynı keşiften standart hedef JSON'u üret.

ÇIKTI İKİ BÖLÜMDEN OLUŞMALIDIR:
1. template: bu rapor formatının nasıl okunacağını tarif eden yapısal tarif.
2. extracted_data: sistemin sabit normalize edilmiş hedef JSON'u.

KRİTİK AYRIM:
- template gerçek veriyi tekrar eden bir rapor özeti değildir.
- template; bölüm, tablo, alan anlamı, tablo yönelimi, ekipman yerleşimi, kontrol kapsamı, sonuç hücreleri ve Camelot'un hangi tabloyu seçmesi gerektiğine dair kanıtları tarif eder.
- extracted_data gerçek rapordan çıkarılan değerleri taşır.
- Template'te YD1 gibi gerçek kodlar kanıt olarak kullanılabilir; ancak template'in kendisini rapora özel veri deposuna dönüştürme. Yapısal pattern'i tarif et.

1) RAPOR BİLGİLERİ DISCOVERY
Raporun üst bilgi bölümünü bul.
Aşağıdaki normalize alanların raporda hangi label/anlam ile bulunduğunu keşfet:
- report_no
- company_name
- control_date
- next_control_date
- overall_result

Başlık isimleri değişebilir. Örn. "Muayene Tarihi ve Saati" ve "Kontrol Tarihi" aynı normalize anlama gelebilir. Bunu template'e yaz.

2) KURULUŞ BİLGİLERİ DISCOVERY
Varsa:
- company_name
- address
- inspection_address
- phone
- email
- contract_id
- sgk_no
alanlarının label varyasyonlarını ve bölümünü template'e yaz.

3) SİSTEM DISCOVERY
Raporda gerçekten kontrol edilen sistemleri/grupları belirle.
Her sistem için:
- name: rapordaki gerçek sistem adı
- category: yangin_dolabi, yangin_pompasi, hidrant, sprinkler, su_alma_verme, su_deposu, sabit_boru_tesisati, gazli_sondurme veya diger

Her sistemin ilgili tablolarını template'te tanımla.

4) EKİPMAN YAPISI DISCOVERY
Ekipmanın tabloda nasıl temsil edildiğini belirle:
- none
- rows
- columns
- repeating_blocks
- mixed

Özellikle şunları keşfet:
- ekipman kodu hangi label/başlıktan geliyor?
- ekipmanlar kolon mu satır mı?
- ekipman listesi ayrı tablo mu?
- aynı tablo içinde ekipman özellikleri dikey satırlarda mı?
- 5'li/10'lu tekrar eden yatay bloklar var mı?
- equipment code pattern nedir?

5) KONTROL KAPSAMI DISCOVERY — ÇOK ÖNEMLİ
Her kontrol tablosunun kapsamını açıkça belirle:
- system_based
- equipment_based
- mixed
- unknown

Ekipman bazlı olduğuna dair kanıt:
Ekipman kodları kolon başlıklarında/satır başlıklarında bulunuyor ve aynı kontrol maddesi için her ekipmana ayrı sonuç hücresi bulunuyorsa equipment_based.

Sistem bazlı olduğuna dair kanıt:
Kontrol maddesi için tek bir sonuç hücresi bulunuyor ve sonuç belirli bir ekipmana bağlanmıyorsa system_based.

Aynı raporda farklı tablolar farklı kapsam kullanabilir. Sistem genelinde tek bir varsayım yapma.

6) CONTROL ITEM DISCOVERY
Her kontrol maddesini normalize hedefe taşı.
Her kayıt:
- code
- description
- scope = system | equipment
- equipment = ekipman bazlıysa ekipman kodlarını virgülle ayırılmış string; sistem bazlıysa ""
- results = system bazlıysa örn. {"status":"U"}; equipment bazlıysa örn. {"YD1":"UD","YD2":"U"}
- source_pages

Ekipman bazlı kontrol sonucunu sistem geneline yayma.
Sistem bazlı sonucu bütün ekipmanlara kopyalama.

7) COMPONENT DISCOVERY
Raporda fiziksel ekipman/bileşen listesi varsa:
- code
- name
- location
- brand
- model
- serial_no
- properties
- source_pages
alanlarını çıkar.

Raporda olmayan ekipmanı uydurma.
Yangın dolabı kodları raporda gerçek fiziksel ekipman olarak listeleniyorsa components altında tut.

8) FINDINGS DISCOVERY
Bulguları ayrı çıkar.
Her bulgu:
- id
- system_name
- description
- affected_equipment
- source_pages

Bulgu metninde belirli ekipman kodu açıkça geçiyorsa affected_equipment'e koy.
Kod geçmiyorsa ekipman tahmin etme.
Aynı bulguyu ekipman başına çoğaltma.

9) CAMELOT TEMPLATE DISCOVERY
Her önemli tablo için template içinde şu bilgileri üret:
- role
- page
- title_patterns
- header_patterns
- structure_type
- orientation
- equipment_axis
- control_axis
- result_binding
- repeat_block
- camelot.preferred_flavor
- camelot.fallback_flavor
- camelot.bbox = null (PDF metninden güvenilir koordinat çıkaramıyorsan uydurma)

Camelot için sayfa + başlık/kolon pattern'i nokta atışı tablo seçimine yardımcı olacak şekilde yaz.
Koordinat uydurma.

10) KANIT
Template'teki kritik structural kararların yanında evidence üret.
Özellikle:
- equipment_structure
- control_structure
- result_binding
- repeating_blocks
kararlarının nedenini kısa metinlerle belirt.

11) HEDEF JSON
extracted_data şu yapıya uymalı:
{
  "report": {
    "report_no": null,
    "company_name": null,
    "control_date": null,
    "next_control_date": null,
    "overall_result": null
  },
  "covered_categories": [],
  "systems": [
    {
      "name": "",
      "category": "",
      "equipment_count": 0,
      "equipment_count_known": false,
      "control_count": 0,
      "components": [
        {
          "code": null,
          "name": null,
          "location": null,
          "brand": null,
          "model": null,
          "serial_no": null,
          "properties": {},
          "source_pages": []
        }
      ],
      "control_items": [
        {
          "code": "",
          "description": null,
          "scope": "system | equipment",
          "equipment": "",
          "results": {},
          "source_pages": []
        }
      ]
    }
  ],
  "findings": [
    {
      "id": null,
      "system_name": null,
      "description": "",
      "affected_equipment": [],
      "source_pages": []
    }
  ],
  "matched_inventory_items": [],
  "candidate_inventory_items": [],
  "unmatched_codes": [],
  "analyzer": {
    "version": "template-discovery-1",
    "table_count": 0,
    "equipment_count": 0,
    "control_count": 0,
    "finding_count": 0,
    "fixture_mode": false
  },
  "fixture_id": null
}

HEDEF JSON KURALLARI:
- equipment_count_known=false yalnızca rapor ekipman sayısını gerçekten belirlemeye izin vermiyorsa kullan.
- equipment_count, components içindeki gerçek ekipman sayısı biliniyorsa ondan hesaplanabilir.
- control_count, sistemin control_items sayısından hesaplanabilir.
- covered_categories sistemlerden türetilmelidir.
- matched_inventory_items, candidate_inventory_items ve unmatched_codes AI tarafından envanter eşleştirmesi yapılmadan boş bırakılır.
- source_pages her veri kaynağı için gerçek sayfa numarasıdır.
- Bilgi yoksa null/[] kullan; tahmin etme.

SADECE geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.
PROMPT;
    }
}
