<?php

namespace App\Services\Ai\PkTakip;

use App\Services\PeriodicEquipmentSpecCatalog;
use ReflectionMethod;
use RuntimeException;

/**
 * Kısa talimat (yerel model): toplu tüp kontrol formu. Tür bulma raporu yangın söndürme cihazı listesi olarak tanıdığında
 * PkShortPromptRouter çağırır. Mevcut talimatın ekipman grupları (5) ve lejant (6) bölümleri ile JSON sözleşmesi (13)
 * birebir alınır; yalnızca kurallar kısaltılır. Tüp satırlarını yine Camelot okur (PkBulkReportReader), model yalnızca
 * tablonun yapısını ve rapor bilgilerini yazar.
 */
class PkBulkListPrompt
{
    public function __construct(private PkReportAnalyzer $analyzer, private PeriodicEquipmentSpecCatalog $catalog)
    {
    }

    public function read(array $pages, string $typeSlug, ?string $provider = null): array
    {
        $parts = [];
        foreach (array_values($pages) as $index => $page) {
            if (trim((string) $page) !== '') {
                $parts[] = '--- SAYFA ' . ($index + 1) . " ---\n" . trim((string) $page);
            }
        }
        // Tüp satırlarını Camelot okur; modele ilk iki sayfa (rapor bilgisi, tablo başlığı ve ilk satırlar) ve son sayfa (sonuç,
        // imza) yeter. Bütün satırlar küçük modelin penceresini aşıyordu (toplu form #6 / #7: ~28 bin token).
        // Son sayfadan yalnız alt kısım (sonuç, imza): tam son sayfayla girdi + çıktı yerel modelin penceresini aşıyordu (#6: son
        // sayfa baştan sona tüp satırı, cevap kuruluş bilgisinde kesildi). Tablo sayfası tam kalır: uzun soru başlıkları satırlara
        // bölündüğü için sayfanın başı yalnız başlıktan ibaret olabiliyor (#7: ilk 40 satırla 0 tüp).
        if (count($parts) > 3) {
            $tail = implode("\n", array_slice(explode("\n", end($parts)), -40));
            $parts = [...array_slice($parts, 0, 2), '--- (ara sayfalar: aynı tablonun devamı; satırları tablo okuyucu okur) ---', $tail];
        }
        $catalog = $this->catalog->promptCatalog();
        $type = collect((array) ($catalog['types'] ?? []))->firstWhere('slug', $typeSlug);
        if (!$parts || !$type) {
            throw new RuntimeException('Toplu form kısa talimatı: metin ya da tür yok.');
        }

        // shortcut: bölümler mevcut analizörün private metotlarından okunur (kopyası tutulmasın); kalıcı olursa ortak sınıfa taşınmalı.
        $system = (string) $this->call('systemPrompt');
        $prompt = $this->rules($type['name'])
            . "\n\n5. EKİPMAN GRUPLARI" . str($system)->between('5. EKİPMAN GRUPLARI', '7. SONUÇ VE KANAAT')
            . "\n\n" . $this->call('catalogInstruction', ['common_specs' => $catalog['common_specs'] ?? [], 'types' => [$type]])
            . "\n\n13. SON JSON SÖZLEŞMESİ" . str($system)->after('13. SON JSON SÖZLEŞMESİ');

        $semantic = PkAiProvider::client($provider)->extract($prompt, implode("\n\n", $parts));
        // Toplu form olduğuna yönlendirici karar verdi: kategori koddan ve şablonda yalnız tüp listesi. Küçük model "yangin_tesisati"
        // yazıp başka sistemler uyduruyordu (#10 Koç Medikal: tablo okunmadı, 27 yerine 0 tüp).
        $semantic['extracted_data']['report_category'] = PkBulkReportReader::CATEGORY;
        $semantic['template']['fire_systems']['systems'] = array_slice((array) ($semantic['template']['fire_systems']['systems'] ?? []), 0, 1);

        return $this->withHeaders($semantic, implode("\n\n", $parts), $provider);
    }

    // Tablo başlıkları ayrı küçük istekle sorulur: uzun talimatın içinde model başlıkları bölüyor / atlıyor (tablo okuyucu
    // sütunları başlık metniyle bulduğu için tek harf farkı tabloyu okunamaz yapar). Şablon bu cevaptan kurulur.
    private function withHeaders(array $semantic, string $text, ?string $provider): array
    {
        $definition = $semantic['template']['fire_systems']['systems'][0]['equipment_definitions'][0] ?? null;
        if (!is_array($definition)) {
            return $semantic;
        }
        $schema = ['type' => 'object', 'properties' => [
            'location_header' => ['type' => ['string', 'null']],
            'number_header' => ['type' => 'string'],
            'other_headers' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], 'required' => ['location_header', 'number_header', 'other_headers']];
        $found = PkAiProvider::client($provider)->extract(self::HEADERS_PROMPT, $text, 3000, schema: $schema);
        $number = trim((string) ($found['number_header'] ?? ''));
        if ($number === '') {
            return $semantic;
        }
        $others = collect((array) ($found['other_headers'] ?? []))->map(fn ($header) => trim((string) $header))->filter()->reject(fn ($header) => $header === $number)->values();
        $location = filled($found['location_header'] ?? null) ? trim((string) $found['location_header']) : null;
        $attributes = collect([$location, ...$others])->filter()->unique()->values()
            ->map(fn (string $header) => ['field' => $header, 'source_pattern' => $header, 'value' => null])->all();

        $definition['instance_structure']['identity_field'] = $number;
        $definition['instance_structure']['header_patterns'] = collect([$location, $number, ...$others])->filter()->unique()->values()->all();
        $definition['attributes'] = $attributes;
        $semantic['template']['fire_systems']['systems'][0]['equipment_definitions'][0] = $definition;

        return $semantic;
    }

    private const HEADERS_PROMPT = <<<'PROMPT'
Sana bir toplu yangın söndürücü (tüp) kontrol formunun metni verilecek. Formda cihazları satır satır listeleyen bir tablo var. Bu tablonun sütun başlıklarını, raporda yazdığı gibi harfi harfine yaz.
- Başlıkları YALNIZCA rapor metninden al. Bu talimattaki hiçbir sözcüğü başlık olarak yazma; raporda göremediğin başlığı uydurma.
- PDF metninde başlık satırı tablonun satırlarından SONRA gelebilir (tablo sayfasının sonunda) ve uzun başlıklar alt alta satırlara bölünmüş olabilir. Bölünmüş başlığı TEK başlık olarak birleştir (örn. "Seri" ve "Numarası" alt alta → "Seri Numarası"). Bir başlığın parçalarını ayrı başlık olarak yazma.
- location_header: cihazın yerini / konumunu gösteren sütunun başlığı; yoksa null.
- number_header: cihaz numarası sütununun başlığı.
- other_headers: geri kalan sütunların başlıkları soldan sağa (cihaz tipi, dolum tarihi, değerlendirme / sonuç gibi bilgiler). Değerlendirme / sonuç sütunu varsa mutlaka yaz. Numaralı kontrol sorusu sütunlarını (başında soru numarası olan uzun soru cümleleri) yazma.
PROMPT;

    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(PkReportAnalyzer::class, $method))->invoke($this->analyzer, ...$args);
    }

    private function rules(string $typeName): string
    {
        return <<<PROMPT
Sen bir toplu "{$typeName}" periyodik kontrol formunu okuyorsun: form, çok sayıda cihazı tek bir tabloda satır satır listeler. Görevin formu aşağıdaki JSON sözleşmesine birebir uyan tek bir JSON olarak yazmak. Tablodaki cihaz satırlarını SEN yazmazsın; onları sonra bir tablo okuyucu (Camelot) okur. Senin işin tablonun YAPISINI doğru tarif etmek ve rapor bilgilerini okumak. Raporda olmayan bilgiyi uydurma.

SABİT KARARLAR
- extracted_data.report_category = "ysc", extracted_data.extraction_mode = "structured".
- template.fire_systems.systems: TEK sistem. system_name ve section_heading_patterns = cihaz tablosunun raporda yazan başlığı (yoksa "{$typeName}").
- Bu sistemde TEK equipment_definitions kaydı: equipment_name = "{$typeName}", instance_structure.equipment_axis = "rows", identity_value = null, group_width = null, result_columns = [] (cihaz başına birçok numaralı soru sütunu varsa da boş bırak), verdict = {"status": null, "raw": null, "basis": "not_stated", "evidence": null}.

TABLO BAŞLIKLARI — EN ÖNEMLİ KURAL (tablo okuyucu sütunları başlık metniyle bulur; tek harf farkı tabloyu okunamaz yapar)
- identity_field = cihaz numarası sütununun başlığı, raporda yazdığı gibi (örn. "Tüp No", "Seri No", "Cihaz No").
- header_patterns = tablonun BÜTÜN sütun başlıkları, soldan sağa, raporda yazdığı gibi: cihazın yeri / konumu sütunu (örn. "Cihazın Bulunduğu Yer"), numara, tip / cins, kapasite, dolum tarihi, değerlendirme / sonuç sütunu dahil hiçbirini atlama. Kısaltma ya da kendi adını yazma.
- attributes = kimlik sütunu dışındaki her sütun için {"field": başlık, "source_pattern": başlık (raporda yazdığı gibi), "value": null}.
- PDF metninde başlık satırı tablonun satırlarından SONRA gelebilir ve uzun başlıklar satırlara bölünmüş olabilir. Bölünmüş başlığı birleştirerek yaz (örn. "Cihaz" ve "No" alt alta → "Cihaz No"; "Hidrostatik Test" ve "Tarihi" alt alta → "Hidrostatik Test Tarihi").

RAPOR BİLGİLERİ (extracted_data.report_information)
report_no ("Rapor No" başlığının değeri; sayfa köşesindeki form / doküman kodu tek başına rapor numarası değildir), company_title (raporu isteyen firma / müşteri; formu düzenleyen bakım / muayene firması değildir), address (kontrol adresi), report_date, control_date (kontrol tarihi), validity_date (sonraki kontrol tarihi). Değerleri raporda yazdığı gibi yaz.

BULGULAR (extracted_data.findings)
Raporun ikaz / öneri / kusur bölümündeki her madde ayrı bir finding'dir (system_name = yukarıdaki sistem adı, severity = null, ambiguous = false). Tablodaki cihaz satırlarını ve soru maddelerini bulgu yapma. Bölüm boşsa findings = [].

GENEL SONUÇ (extracted_data.overall_result): text = sonuç ve kanaat cümlesi; status: kullanıma uygunsa "uygun", değilse "uygun_degil".
SONUÇ LEJANTI (extracted_data.result_legend): işaretlerin açıklaması (örn. {"code": "✔", "meaning": "Uygun"}); yoksa [].
KONTROLÜ YAPAN KURULUŞ (extracted_data.inspection_body): formu düzenleyen firmanın bilgileri ve personeli; boş alanlar null.
TESİS BİLGİLERİ (extracted_data.facility_information): [].
PROMPT;
    }
}
