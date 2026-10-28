<?php

namespace App\Services\Ai\PkTakip;

/**
 * Elektrik ailesi (yerel model, Docling akışı): türün talimatı (PkElectricalReportAnalyzer) bölüm bölüm küçük ajanlara
 * verilir; kurallar aynen o talimattan. Sistemler (0, 2, 3) Docling'in özetinden (başlıklar ve tabloların ilk satırı: bir
 * bölümün kendi formu / tablosu var mı burada görünür); ölçüm / cihaz listeleri sistem başına tablo haritasıyla (4. bölüm
 * adlandırma kuralıyla; yapıyı PkTableDefinitions bulur, satırları mevcut tablo okuyucu okur); tesis bilgisi ve lejant (6, 7).
 * Rapor bilgisi / sonuç / kuruluş (PkHeaderPass) ve bulgular (PkFindingsPass) ayrı ajanlardır. Sözleşme aynı.
 */
class PkElectricalAgents
{
    public function __construct(private PkGeminiReportClient $contract, private PkTableDefinitions $definitions)
    {
    }

    /**
     * @param string $instruction türün talimatı
     * @param array $parts PkDocumentSections::texts() (by_system: sistem adı => o sistemin bölümleri; facility)
     * @param list<array> $doclingTables pk_docling.py "tables"
     */
    public function read(string $instruction, array $parts, array $doclingTables, string $pdfPath, array $tableLabels, string $typeSlug, ?string $provider = null): array
    {
        $schema = $this->contract->responseSchema()['properties'];
        $template = $schema['template']['properties'];
        $data = $schema['extracted_data']['properties'];
        $system = $template['fire_systems']['properties']['systems']['items']['properties'];

        // Her sistem ayrı ajan, yalnız kendi bölümü: sonucu (3. bölüm kuralı) ve ölçüm / cihaz listesi (4. bölüm, tablo haritası).
        $systems = collect();
        $map = [];
        foreach ((array) ($parts['by_system'] ?? []) as $name => $text) {
            $verdict = PkLocalRetry::client($provider, fn () => false)->extract(
                self::sections($instruction, [3], false) . "\n\nBU ADIMDA: verilen bölüm \"{$name}\" sistemine ait; yalnız bu sistemin sonucunu (verdict) yaz.",
                $text,
                2000,
                schema: ['type' => 'object', 'properties' => ['verdict' => $system['verdict']], 'required' => ['verdict']]
            )['verdict'] ?? null;
            $lines = explode("\n", $text);
            $systems[$name] = [
                'system_name' => $name,
                'section_heading_patterns' => array_values(array_unique(array_map(fn ($line) => substr($line, 3), array_filter($lines, fn ($line) => str_starts_with($line, '## '))))),
                'verdict' => $verdict ?? ['status' => null, 'raw' => null, 'basis' => 'not_stated', 'evidence' => null],
            ];
            $tableNos = array_values(array_filter(array_map(fn ($line) => str_starts_with($line, '### Tablo ') ? (int) substr($line, 10) : 0, $lines)));
            if ($name !== 'Tesisat Geneli' && $tableNos) {
                $map[$name] = app(PkTableMap::class)->map($text, [$name => []], $tableNos, $tableLabels, $provider, self::sections($instruction, [4], false))[$name] ?? ['lists' => [], 'singles' => []];
            }
        }
        $definitions = $this->definitions->build($pdfPath, $doclingTables, $map);

        // Tesis bilgisi bölümü etiketlenmediyse bütün yapılı metin (Tesis Özellikleri kartı her raporda okunur).
        $facilityText = $parts['facility'] ?? $parts['all'] ?? null;
        $facility = $this->facility(self::sections($instruction, [6, 7]), $facilityText, $provider);
        $found = ['template_type' => 'tesisat_raporu']; // shortcut: tek bölüm raporu (sistem_raporu) ayrımı yok; gerekirse kapsam ajanı eklenir

        return [
            'template' => [
                'template_type' => $found['template_type'] ?? null,
                'template_version' => '1.0',
                'report_information' => [],
                'facility_or_project_information' => $facility['facility_or_project_information'] ?? null,
                'fire_systems' => ['systems' => $systems->map(fn (array $row, string $name) => $row + [
                    'section_detection' => ['start_heading_patterns' => [], 'continuation_patterns' => [], 'end_detection_patterns' => []],
                    'equipment_definitions' => $definitions[$name] ?? [],
                ])->values()->all()],
            ],
            'extracted_data' => [
                'report_category' => null,
                'extraction_mode' => 'structured',
                'equipment_type' => ['slug' => $typeSlug, 'evidence' => null],
                'equipment_tag' => ['label' => null, 'value' => null, 'evidence' => null],
                'equipment_specs' => [],
                'report_information' => [],
                'facility_information' => (array) ($facility['facility_information'] ?? []),
                'result_legend' => (array) ($facility['result_legend'] ?? []),
                'findings' => [],
                'overall_result' => null,
                'inspection_body' => null,
            ],
        ];
    }

    /**
     * Tesis / proje bilgisi ajanı (elektrik ve yangın tesisatı ortak): kural metni türün talimatından. Kart yalnız ilk etiketi
     * kullanır (PkElectricalReportReader::facilityValues); alan × etiket döngüsü sınırlanır (NuExtract3 #128 / #129: "Bina
     * Yüksekliği" tekrarı cevap sınırını aştı).
     *
     * @return array{facility_or_project_information?: array, facility_information?: array, result_legend?: array}
     */
    public function facility(string $rules, ?string $text, ?string $provider = null): array
    {
        if (blank($text)) {
            return [];
        }
        $schema = $this->contract->responseSchema()['properties'];
        $template = $schema['template']['properties']['facility_or_project_information'];
        $template['properties']['fields']['maxItems'] = 30;
        $template['properties']['fields']['items']['properties']['label_patterns']['maxItems'] = 2;

        return PkLocalRetry::client($provider, fn () => false)->extract(
            $rules . "\n\nBU ADIMDA: yalnız tesis / proje bilgileri (template.facility_or_project_information, extracted_data.facility_information) ve sonuç lejantı (extracted_data.result_legend).",
            $text,
            4000,
            schema: ['type' => 'object', 'properties' => [
                'facility_or_project_information' => $template,
                'facility_information' => $schema['extracted_data']['properties']['facility_information'],
                'result_legend' => $schema['extracted_data']['properties']['result_legend'],
            ], 'required' => ['facility_or_project_information', 'facility_information', 'result_legend']]
        );
    }

    // Talimatın istenen numaralı bölümleri (başlık satırı "N. "; alt bölümler "4a." gibi bir öncekinin içinde kalır);
    // $common: ilk bölümden önceki ortak kısım (sabit kararlar) da eklenir.
    public static function sections(string $instruction, array $numbers, bool $common = true): string
    {
        $out = [];
        $keep = $common;
        foreach (explode("\n", $instruction) as $line) {
            if (preg_match('/^(\d{1,2})\. /', $line, $match)) {
                $keep = in_array((int) $match[1], $numbers, true);
            }
            if ($keep) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }
}
