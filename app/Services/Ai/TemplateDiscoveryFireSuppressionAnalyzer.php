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

AMAÇ:
PDF'yi önceden bildiğin bir firma şablonuna zorlamadan incele. Gerçek raporun yapısını keşfet ve Camelot'un sonraki aşamada gerçek verileri çıkarabilmesi için uygulanabilir bir template üret.

ÇIKTI TAM OLARAK İKİ ANA BÖLÜMDÜR:
1. template: gerçek PDF'den keşfedilen yapısal okuma tarifi.
2. extracted_data: SADECE findings.

EN KRİTİK KURAL — ÖRNEKLER KURAL DEĞİLDİR:
Prompt içinde geçen 5.x, A.x, YD1, YD2, 5 kolon, belirli sistem adları veya belirli sonuç harfleri yalnızca açıklama örnekleridir.
Bunları gerçek raporda varmış gibi kabul etme.
PDF'de farklı kontrol kodu, ekipman kodu, sistem adı, tablo yönü, kolon sayısı, blok boyutu, başlık veya sonuç gösterimi varsa onu gerçek haliyle keşfet.
Asla örneklerden pattern türetip rapora zorla uygulama.

AI SEMANTİK SINIRI:
extracted_data içinde findings dışında hiçbir alan OLMAYACAK.
AI extracted_data altında report, organization, systems, equipment, components, control_items, results, covered_categories veya inventory verisi üretmeyecek.
Bunların gerçek değerlerini sonraki Camelot/normalizasyon aşaması çıkaracak.

ANCAK template bir istisnadır: Camelot'un hangi sistem/bölüm/tabloyu okuyacağını anlayabilmesi için PDF'de keşfedilen sistemlerin GERÇEK başlıklarını ve yapısal ilişkilerini template.systems_structure.systems altında listele.
Bu liste extracted_data değildir; template'in keşfedilmiş okuma haritasıdır.

1) RAPOR YAPISI
Gerçek PDF'den keşfet:
- sayfa kapsamı
- bölüm sayısı ve bölüm başlıklarının yapısı
- bölümlerin sayfalar arasında devam edip etmediği
- tabloların sayfalar arasında devam edip etmediği
- tekrar eden tablo/blok yapıları

section_count gerçek keşfedilen sayı olmalıdır. sections her önemli bölüm için yapısal bilgi içermelidir.

2) RAPOR BİLGİLERİ TEMPLATE
Gerçek değerleri yazma.
Camelot'un report_no, company_name, control_date, next_control_date, overall_result gibi alanları hangi bölüm/label/value ilişkisi üzerinden bulacağını keşfet.
Gerçek PDF'deki label varyasyonlarını label_patterns içine koy.

3) ORGANİZASYON TEMPLATE
Gerçek şirket/adres/telefon değerlerini yazma.
PDF'de gerçekten bulunan organizasyon alanlarının bölümünü ve label/value ilişkisini keşfet.

4) SİSTEM DISCOVERY — ZORUNLU
PDF'de kontrol edilen sistem/grup başlıklarını gerçekten bul.
Her gerçek sistem için template.systems_structure.systems içine bir kayıt koy:
- name = PDF'deki gerçek sistem/bölüm adı
- category = mümkünse normalize kategori; emin değilsen diger
- heading_pattern = bu sistemi tanıyan gerçek başlık/pattern
- table_hints = bu sisteme ait tabloları seçmeye yardım eden kısa patternler

Sistemleri boş bırakma eğer PDF'de sistem/bölüm başlıkları açıkça mevcutsa.
Sistem adı yoksa uydurma; systems boş olabilir.
Bir sistemin birden fazla tablosu varsa hepsini yapısal olarak ilişkilendir.
Aynı sistem sonraki sayfalarda devam ediyorsa bunu section_continuation ve table_hints ile belirt.

5) EKİPMAN YAPISI
Gerçek ekipman listesini extracted_data'ya koyma.
PDF'de ekipmanların nasıl temsil edildiğini keşfet:
- rows / columns / repeating_blocks / mixed / none
- identity'nin nerede bulunduğu
- code/name konumu
- property satır/kolon yapısı
- ekipman ekseni
- tekrar bloklarının gerçek boyutu
- sayfa devamlılığı

Blok boyutu 5 olmak zorunda değildir. Gerçekte kaçsa onu belirt; değişkense değişken olduğunu belirt.

6) TABLO YAPISI
Her önemli tablo için table_hints oluştur.
Gerçek PDF'den keşfet:
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
- Camelot preferred/fallback flavor
- bbox (metinden güvenilir değilse null)

orientation ve structure_type için verilen bilinen değerleri zorunlu enum olarak görme. Gerçek yapı başka bir yapıysa onu açıklayıcı şekilde tarif et.

Ekipman kolonlarda ve kontrol maddeleri satırlardaysa bunu açıkça belirt.
Yatay tekrar eden ekipman blokları varsa gerçek block_size'ı keşfet.

7) KONTROL ITEM YAPISI
Gerçek kontrol maddelerini extracted_data'ya koyma.
Template'te kontrol kodu, açıklama ve sonuç hücrelerinin gerçek konum/pattern ilişkisini keşfet.
Aynı raporda bazı tablolar system_based, bazıları equipment_based olabilir; tablo bazında keşfet.
Sonuçların gerçek aliaslarını da keşfet; U/UD/N'yi varsayma.

8) FINDINGS — TEK GERÇEK AI VERİSİ
Findings'i raporun anlamsal bulgu/tespit/kusur/not/açıklama içeriğinden çıkar.
Her kayıt TAM OLARAK şu alanlara sahip:
- id
- system_name
- description
- source_pages

Başka alan ekleme. Özellikle affected_equipment ekleme.

Her finding'i ait olduğu gerçek sisteme bağlamaya çalış.
Örneğin bir bulgu "yangın dolapları" bölümünün altında ise o sistemin PDF'deki gerçek adını system_name yap.
Bulgu metninde YD14 gibi ekipman kodları geçebilir; bunları description içinde koru fakat ayrı alan oluşturma.
Sistem güvenilir şekilde belirlenemiyorsa system_name=null.

U/UD/N veya başka sonuç hücrelerinden otomatik finding üretme.
Gerçek uygunsuzluk/tespit metni varsa finding üret.
Aynı bulguyu ekipman sayısı kadar çoğaltma.
Aynı metin tekrar ediyorsa deduplicate et.
source_pages gerçek PDF sayfası olmalı.

9) EVIDENCE
Template'teki kritik kararların nedenini PDF'deki yapısal kanıtla açıkla:
- sistem başlığının nasıl tanındığı
- tablo yönü
- equipment axis
- control axis
- result binding
- repeating blocks
- page continuation

10) EXTRACTION RULES
Template açıkça şunu belirtmelidir:
- report_information = camelot
- organization_information = camelot
- systems = camelot
- equipment = camelot
- components = camelot
- control_items = camelot
- results = camelot
- findings = ai

11) DİNAMİK KEŞİF TESTİ
Aşağıdakileri ASLA sabit varsayma:
5.x, A.x, YD1, YD2, 5 ekipman, belirli sistem adı, belirli sonuç aliası.
PDF'de örneğin K-01, P-001, E-7, Madde 1 veya 8 ekipmanlı blok varsa bunları gerçek pattern olarak keşfet.

12) SON ÇIKTI
extracted_data yalnızca:
{
  "findings": [
    {
      "id": "finding-1",
      "system_name": null,
      "description": "...",
      "source_pages": [1]
    }
  ]
}

Template'te gerçek report/equipment/control değerlerini veri seti olarak çıkarmak yerine Camelot'un onları bulacağı yapısal haritayı üret. Sistem başlıkları ise Camelot'un doğru tabloları seçebilmesi için template içinde gerçek haliyle keşfedilmelidir.

SADECE geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.
PROMPT;
    }
}
