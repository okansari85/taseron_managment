<?php

namespace App\Services\Ai\PkTakip;

/**
 * Tek ekipman raporunun ekipman alt ajanı (yerel model): ekipmanın kimliği, etiket / tespit bilgileri, katalog etiketi
 * (örn. yakıt türü) ve katalogdaki teknik özellikleri okur; sözleşmedeki tek sistem / tek ekipman kaydını, equipment_tag ve
 * equipment_specs'i kurar. Rapor bilgileri (PkHeaderPass) ve bulgular (PkFindingsPass) ayrı ajanlardır.
 */
class PkSingleEquipmentAgent
{
    public function __construct(private PkCachedCamelot $camelot)
    {
    }

    /**
     * @param array $type PeriodicEquipmentSpecCatalog::promptCatalog() türü (slug, name, tag, specs)
     * @param list<array{key: string, name: string, unit: ?string}> $commonSpecs
     */
    public function apply(array $semantic, string $documentText, array $type, array $commonSpecs, ?string $provider = null, ?string $pdfPath = null): array
    {
        // Düz metinde yan yana iki alan aynı satıra düşüyor ("SEKSİYONEL Kapı Yüksekliği"); Camelot hücreleri ayrı verir.
        $documentText = ($pdfPath ? $this->rows($pdfPath) : '') ?: $documentText;
        $specs = collect([...$commonSpecs, ...(array) ($type['specs'] ?? [])])->unique('key')->values();
        $tag = (array) ($type['tag'] ?? []);
        $text = ['type' => ['string', 'null']];
        $schema = ['type' => 'object', 'properties' => [
            'identity_field' => $text,
            'identity_value' => $text,
            'attributes' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['field' => ['type' => 'string'], 'value' => $text], 'required' => ['field', 'value']]],
            'tag' => ['type' => ['string', 'null'], 'enum' => [...(array) ($tag['options'] ?? []), null]],
            'tag_evidence' => $text,
            'specs' => ['type' => 'object', 'properties' => $specs->mapWithKeys(fn ($spec) => [$spec['key'] => ['type' => 'object', 'properties' => ['raw_label' => $text, 'raw_value' => $text], 'required' => ['raw_label', 'raw_value']]])->all(), 'required' => $specs->pluck('key')->all()],
        ], 'required' => ['identity_field', 'identity_value', 'attributes', 'tag', 'tag_evidence', 'specs']];
        $list = $specs->map(fn ($spec) => '- ' . $spec['key'] . ': ' . $spec['name'] . (filled($spec['unit'] ?? null) ? ' (' . $spec['unit'] . ')' : ''))->implode("\n");
        $tagRule = $tag ? '- tag: "' . ($tag['label'] ?? '') . '" raporda hangisi yazıyorsa (' . implode(', ', (array) ($tag['options'] ?? [])) . '); yazmıyorsa null. tag_evidence: raporda yazan ifade.' : '- tag: null.';

        $found = PkLocalRetry::client($provider, fn (array $r) => blank($r['identity_value'] ?? null) && empty($r['attributes']))->extract(<<<PROMPT
Sana TEK bir "{$type['name']}" ekipmanının periyodik kontrol raporu verilecek. Ekipmanın bilgilerini raporda yazdığı gibi oku; raporda olmayanı uydurma, null bırak.
- identity_field / identity_value: ekipmanı tanımlayan alanın adı (örn. "Seri No", "Ekipman No") ve değeri.
- attributes: ekipmanın etiket / tespit bilgileri tablosundaki alan:değer çiftleri. Kontrol kriterleri / kontrol listesi maddeleri bilgi değildir; yazma.
{$tagRule}
- specs: aşağıdaki her özellik için raporda anlamı BİREBİR aynı alan varsa raw_label = rapordaki alan adı, raw_value = değeri; yoksa ikisi de null. Benzer ama farklı bilgiyi eşleme (örn. "Tekerlek Tipi" direk tipi değildir).
ÖZELLİKLER (anahtar: ad):
{$list}
PROMPT, $documentText, 4000, schema: $schema);

        $status = $semantic['extracted_data']['overall_result']['status'] ?? null;
        $verdict = ['status' => $status, 'raw' => $semantic['extracted_data']['overall_result']['text'] ?? null, 'basis' => $status ? 'explicit' : 'not_stated', 'evidence' => $semantic['extracted_data']['overall_result']['text'] ?? null];
        $semantic['template']['fire_systems']['systems'] = [[
            'system_name' => $type['name'],
            'section_heading_patterns' => [],
            'section_detection' => ['start_heading_patterns' => [], 'continuation_patterns' => [], 'end_detection_patterns' => []],
            'verdict' => $verdict,
            'equipment_definitions' => [[
                'equipment_name' => $type['name'],
                'instance_structure' => [
                    'identity_field' => $found['identity_field'] ?? null,
                    'identity_value' => $found['identity_value'] ?? null,
                    'identity_hint' => ['pattern' => null, 'validation' => 'soft'],
                    'equipment_axis' => 'none',
                    'group_width' => null,
                    'header_patterns' => [],
                    'result_columns' => [],
                    'ambiguous' => false,
                    'ambiguous_reason' => null,
                ],
                'attributes' => collect((array) ($found['attributes'] ?? []))->filter(fn ($pair) => is_array($pair) && filled($pair['field'] ?? null))
                    ->map(fn ($pair) => ['field' => $pair['field'], 'source_pattern' => $pair['field'], 'value' => $pair['value'] ?? null])->values()->all(),
                'verdict' => $verdict,
            ]],
        ]];
        $semantic['extracted_data']['equipment_tag'] = ['label' => $tag['label'] ?? null, 'value' => $found['tag'] ?? null, 'evidence' => $found['tag_evidence'] ?? null];
        $semantic['extracted_data']['equipment_specs'] = $specs->map(fn ($spec) => (array) ($found['specs'][$spec['key']] ?? []) + ['key' => $spec['key']])
            ->filter(fn ($spec) => filled($spec['raw_value'] ?? null))
            ->map(fn ($spec) => ['key' => $spec['key'], 'value' => $spec['raw_value'], 'raw_label' => $spec['raw_label'] ?? null, 'raw_value' => $spec['raw_value']])
            ->values()->all();

        return $semantic;
    }

    // shortcut: yalnız 1. sayfanın tabloları (etiket bilgileri orada); bilgiler başka sayfadaki bir form çıkarsa sayfaları genişlet.
    private function rows(string $pdfPath): string
    {
        $out = [];
        foreach ((array) ($this->camelot->extract($pdfPath)['tables'] ?? []) as $table) {
            foreach ((int) ($table['page'] ?? 0) === 1 ? (array) ($table['data'] ?? []) : [] as $row) {
                $cells = array_values(array_filter(array_map(fn ($cell) => trim((string) $cell), (array) $row), fn ($cell) => $cell !== ''));
                // Camelot aynı tabloyu iki yöntemle (lattice / stream) verebiliyor: aynı satır bir kez.
                if ($cells) {
                    $out['| ' . implode(' | ', $cells) . ' |'] = true;
                }
            }
        }

        return implode("\n", array_keys($out));
    }
}
