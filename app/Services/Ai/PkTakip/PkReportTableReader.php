<?php

namespace App\Services\Ai\PkTakip;

use App\Services\Ai\FireSuppressionUnifiedNormalizer;
use Throwable;

/**
 * pktakip rapor algılamasının ikinci adımı: Gemini'nin tarif ettiği ekipman tablolarını mevcut Camelot/normalizer
 * hattıyla (FireSuppressionUnifiedNormalizer - değiştirilmeden) okur ve sonucu pktakip'in ihtiyaç duyduğu sade
 * biçime çevirir: ekipman listesi (özellikler + uygunluk), ekipman türü bazında sayım ve ekipmana bağlanmış bulgular.
 * Kontrol maddeleri (control_items) sonuca taşınmaz.
 */
class PkReportTableReader
{
    public function __construct(private FireSuppressionUnifiedNormalizer $normalizer)
    {
    }

    /**
     * $installation (tesisat raporu; ekipman raporunda kapalı, davranış aynen kalır):
     * - Kimlik no + konum: aynı no farklı konumdaki ayrı ekipmandır (ör. dolap no her binada baştan başlar); tekrar
     *   okunan satırlar ancak no ve konumları aynıysa birleşir.
     * - Durum yalnızca ekipmanın gerçek kriterlerinden: tablonun başlık / kimlik / özellik satırları (ör. "Soru / Kriter",
     *   "Dolap No", "Marka") kriter sayılmaz. Kriteri olmayan ekipmanda durum boş kalır.
     */
    /**
     * $bulk (toplu ekipman raporu, ör. yangın tüpleri): kimlik yer + no + tip; aynı yerde aynı no'lu farklı tüpler olabildiği
     * için yalnızca birebir aynı okunan satırlar birleşir. Durum önce satırın kendi sonuç sütunundan (Değerlendirme / Sonuç),
     * yoksa kriterlerinden.
     */
    public function read(string $pdfPath, array $semantic, bool $installation = false, bool $bulk = false): array
    {
        $placeInIdentity = $installation || $bulk;
        $startedAt = microtime(true);

        try {
            $normalized = $this->normalizer->normalize($pdfPath, $semantic);
            $error = null;
        } catch (Throwable $exception) {
            // Örn. hiç ekipman tablosu olmayan rapor: tablo adımı atlanır, Gemini sonuçları yine geçerlidir.
            $normalized = ['systems' => [], 'equipment' => [], 'findings' => []];
            $error = $exception->getMessage();
        }

        $equipment = $this->equipment($normalized, $semantic, $placeInIdentity, $bulk);

        return [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'error' => $error,
            'equipment' => $equipment,
            'equipment_summary' => $this->summary($equipment),
            'findings' => $this->findings($normalized, $semantic),
        ];
    }

    private function equipment(array $normalized, array $semantic, bool $placeInIdentity, bool $bulk = false): array
    {
        $verdicts = $this->singleInstanceVerdicts($semantic);
        $notCriteria = $placeInIdentity ? $this->nonCriteriaTitles($semantic) : [];
        $results = array_values((array) ($normalized['equipment'] ?? []));
        $out = [];
        $index = 0;

        // normalizer equipment[]'i systems[].components[] ile AYNI sırada üretir (bkz. normalizeFinal).
        foreach ((array) ($normalized['systems'] ?? []) as $system) {
            $systemName = (string) ($system['name'] ?? '');
            foreach ((array) ($system['components'] ?? []) as $component) {
                $entry = $results[$index++] ?? [];
                $name = (string) ($component['name'] ?? '');
                $key = $this->key($systemName, $name);
                $status = $placeInIdentity
                    ? ($bulk ? $this->resultProperty((array) ($component['properties'] ?? [])) : null)
                        ?? $this->criteriaStatus((array) ($entry['control_items'] ?? []), $notCriteria[$key] ?? [])
                    : ($this->status($entry['result'] ?? null) ?? $this->status($component['compliance_status'] ?? null));
                $source = $status !== null ? 'table' : null;

                // Tek örnekli ekipmanın sonucu tablodan değil Gemini'nin verdict'inden gelir.
                if (isset($verdicts[$key])) {
                    $status = $verdicts[$key]['status'];
                    $source = 'gemini';
                }

                $out[] = [
                    'system_name' => $systemName,
                    'equipment_name' => $name,
                    'code' => $component['code'] ?? null,
                    'location' => $component['location'] ?? null,
                    'brand' => $component['brand'] ?? null,
                    'model' => $component['model'] ?? null,
                    'serial_no' => $component['serial_no'] ?? null,
                    'properties' => (array) ($component['properties'] ?? []),
                    'status' => $status,
                    'status_source' => $source,
                    'note' => $entry['note'] ?? $component['note'] ?? null,
                    'source_pages' => (array) ($component['source_pages'] ?? []),
                ];
            }
        }

        return $bulk ? $this->dropExactDuplicates($out) : $this->mergeDuplicates($out, $placeInIdentity);
    }

    // Toplu raporda aynı yerde aynı no'lu (ya da no'suz) farklı ekipmanlar olabilir: yalnızca kimliği ve bütün
    // özellikleri birebir aynı olan (aynı tablo iki kez okunmuş) satırlar tek kalır.
    private function dropExactDuplicates(array $equipment): array
    {
        $seen = [];
        $out = [];
        foreach ($equipment as $item) {
            $signature = json_encode([$item['system_name'], $item['equipment_name'], $item['code'], $item['location'], $item['properties']], JSON_UNESCAPED_UNICODE);
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $out[] = $item;
        }

        return $out;
    }

    // Satırın kendi sonuç sütunu (Değerlendirme / Sonuç / Uygunluk): "UYGUN" → uygun, "UYGUN DEĞİL" → uygun değil.
    private function resultProperty(array $properties): ?string
    {
        foreach ($properties as $name => $value) {
            $title = $this->title((string) $name);
            if (!str_contains($title, 'değerlendirme') && !str_contains($title, 'sonuç') && !str_contains($title, 'uygunluk')) {
                continue;
            }
            $text = str_replace([' ', '.', '-'], '', $this->title((string) $value));
            if (str_starts_with($text, 'uygundeğil') || str_starts_with($text, 'uygundegil') || str_starts_with($text, 'uygunolmayan') || in_array($text, ['ud', 'kullanılamaz'], true)) {
                return 'uygun_degil';
            }
            if ($text === 'uygun' || $text === 'uygundur') {
                return 'uygun';
            }
        }

        return null;
    }

    // Aynı tablo birden fazla kez okunabiliyor (örn. aynı pompa listesi ikinci kez, özellikleri boş olarak);
    // aynı sistem + ekipman türü + koda sahip kayıtlar birleştirilir, dolu değer boş olanın yerine geçer.
    // $withPlace: konum da kimliğin parçası; konumu boş tekrar okunan satır aynı nolu ilk kayda birleşir.
    private function mergeDuplicates(array $equipment, bool $withPlace = false): array
    {
        $merged = [];
        $firstByCode = [];
        foreach ($equipment as $item) {
            $code = trim((string) ($item['code'] ?? ''));
            if ($code === '') {
                $key = spl_object_id((object) []) . '#' . count($merged);
            } else {
                $codeKey = $this->key($item['system_name'], $item['equipment_name']) . '|' . mb_strtolower($code, 'UTF-8');
                $place = $withPlace ? $this->place($item) : '';
                $key = $place === '' ? ($firstByCode[$codeKey] ?? $codeKey) : $codeKey . '|' . $place;
                $firstByCode[$codeKey] ??= $key;
            }
            if (!isset($merged[$key])) {
                $merged[$key] = $item;
                continue;
            }
            foreach ($item as $field => $value) {
                if ($field === 'properties') {
                    foreach ((array) $value as $name => $propertyValue) {
                        $current = $merged[$key]['properties'][$name] ?? null;
                        if (($current === null || $current === '') && $propertyValue !== null && $propertyValue !== '') {
                            $merged[$key]['properties'][$name] = $propertyValue;
                        }
                    }
                } elseif (($merged[$key][$field] ?? null) === null || $merged[$key][$field] === [] || $merged[$key][$field] === '') {
                    $merged[$key][$field] = $value;
                }
            }
        }

        return array_values($merged);
    }

    // Ekipmanın gerçek kriterlerinden durum: bir kriter uygunsuzsa uygun değil, hepsi uygunsa uygun; kriter yoksa boş.
    private function criteriaStatus(array $items, array $notCriteria): ?string
    {
        $statuses = collect($items)
            ->reject(fn ($item) => in_array($this->title((string) ($item['title'] ?? '')), $notCriteria, true))
            ->map(fn ($item) => $this->status($item['status'] ?? $item['result'] ?? null))
            ->filter(fn (?string $status) => in_array($status, ['uygun', 'uygun_degil'], true));

        return $statuses->contains('uygun_degil') ? 'uygun_degil' : ($statuses->contains('uygun') ? 'uygun' : null);
    }

    // Tablo tanımındaki kriter olmayan satırların adları (yapay zekanın tarifinden): başlık, kimlik, özellikler.
    private function nonCriteriaTitles(array $semantic): array
    {
        $out = [];
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                $structure = (array) ($definition['instance_structure'] ?? []);
                $titles = [...(array) ($structure['header_patterns'] ?? []), $structure['identity_field'] ?? ''];
                foreach ((array) ($definition['attributes'] ?? []) as $attribute) {
                    $titles[] = $attribute['field'] ?? '';
                    $titles[] = $attribute['source_pattern'] ?? '';
                }
                $key = $this->key((string) ($system['system_name'] ?? ''), (string) ($definition['equipment_name'] ?? ''));
                $out[$key] = array_values(array_unique(array_filter(array_map(fn ($title) => $this->title((string) $title), $titles))));
            }
        }

        return $out;
    }

    // Karşılaştırma için: küçük harf, boşluklar tekleştirilmiş. "İ" küçültülünce gelen birleşik nokta (i̇) atılır:
    // "DEĞERLENDİRME" → "değerlendirme".
    private function title(string $value): string
    {
        $words = array_filter(explode(' ', str_replace(["\t", "\r", "\n"], ' ', $value)), fn (string $word) => $word !== '');

        return str_replace("\u{0307}", '', mb_strtolower(implode(' ', $words), 'UTF-8'));
    }

    // Satırın konumu: normalizer'ın konumu, yoksa rapordaki konum sütunu (Bulunduğu Yer, Konum, Kat…).
    private function place(array $item): string
    {
        $place = trim((string) ($item['location'] ?? ''));
        if ($place !== '') {
            return mb_strtolower($place, 'UTF-8');
        }
        foreach ((array) ($item['properties'] ?? []) as $name => $value) {
            // "Cihazın Bulunduğu Yer" gibi uzun başlıklar da konumdur.
            $title = $this->title((string) $name);
            if ((in_array($title, ['yer', 'mahal', 'kat'], true) || str_contains($title, 'bulunduğu yer') || str_contains($title, 'konum')) && trim((string) $value) !== '') {
                return mb_strtolower(trim((string) $value), 'UTF-8');
            }
        }

        return '';
    }

    // Ekipman türü bazında sayım: "2/3 Yangın Pompası uygun" gibi gösterim için.
    private function summary(array $equipment): array
    {
        $groups = [];
        foreach ($equipment as $item) {
            $label = $item['equipment_name'] !== '' ? $item['equipment_name'] : ($item['system_name'] ?: 'Ekipman');
            $key = $this->key($item['system_name'], $label);
            $groups[$key] ??= ['system_name' => $item['system_name'], 'equipment_name' => $label, 'total' => 0, 'uygun' => 0, 'uygun_degil' => 0, 'unknown' => 0];
            $groups[$key]['total']++;
            match ($item['status']) {
                'uygun' => $groups[$key]['uygun']++,
                'uygun_degil' => $groups[$key]['uygun_degil']++,
                default => $groups[$key]['unknown']++,
            };
        }

        return array_values($groups);
    }

    // Bulgular: normalizer'ın ekipman kodlarıyla eşleştirdiği affected_equipment ile birlikte.
    private function findings(array $normalized, array $semantic): array
    {
        $linked = [];
        foreach ((array) ($normalized['findings'] ?? []) as $finding) {
            $linked[(string) ($finding['id'] ?? '')] = (array) ($finding['affected_equipment'] ?? []);
        }

        return array_map(fn (array $finding) => [
            'id' => $finding['id'] ?? null,
            'system_name' => $finding['system_name'] ?? null,
            'description' => $finding['description'] ?? '',
            'source_pages' => (array) ($finding['source_pages'] ?? []),
            'severity' => $finding['severity'] ?? null,
            'ambiguous' => (bool) ($finding['ambiguous'] ?? false),
            'affected_equipment' => $linked[(string) ($finding['id'] ?? '')] ?? [],
        ], array_values(array_filter((array) ($semantic['extracted_data']['findings'] ?? []), 'is_array')));
    }

    private function singleInstanceVerdicts(array $semantic): array
    {
        $out = [];
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                if (($definition['instance_structure']['equipment_axis'] ?? null) !== 'none') continue;
                $out[$this->key((string) ($system['system_name'] ?? ''), (string) ($definition['equipment_name'] ?? ''))] = [
                    'status' => $this->status($definition['verdict']['status'] ?? null),
                ];
            }
        }

        return $out;
    }

    private function status(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;
        return in_array($value, ['uygun', 'uygun_degil', 'uygulanamiyor'], true) ? $value : null;
    }

    private function key(string $systemName, string $equipmentName): string
    {
        return mb_strtolower(trim($systemName) . '|' . trim($equipmentName), 'UTF-8');
    }
}
