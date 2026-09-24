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

    public function read(string $pdfPath, array $semantic): array
    {
        $startedAt = microtime(true);

        try {
            $normalized = $this->normalizer->normalize($pdfPath, $semantic);
            $error = null;
        } catch (Throwable $exception) {
            // Örn. hiç ekipman tablosu olmayan rapor: tablo adımı atlanır, Gemini sonuçları yine geçerlidir.
            $normalized = ['systems' => [], 'equipment' => [], 'findings' => []];
            $error = $exception->getMessage();
        }

        $equipment = $this->equipment($normalized, $semantic);

        return [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'error' => $error,
            'equipment' => $equipment,
            'equipment_summary' => $this->summary($equipment),
            'findings' => $this->findings($normalized, $semantic),
        ];
    }

    private function equipment(array $normalized, array $semantic): array
    {
        $verdicts = $this->singleInstanceVerdicts($semantic);
        $results = array_values((array) ($normalized['equipment'] ?? []));
        $out = [];
        $index = 0;

        // normalizer equipment[]'i systems[].components[] ile AYNI sırada üretir (bkz. normalizeFinal).
        foreach ((array) ($normalized['systems'] ?? []) as $system) {
            $systemName = (string) ($system['name'] ?? '');
            foreach ((array) ($system['components'] ?? []) as $component) {
                $entry = $results[$index++] ?? [];
                $name = (string) ($component['name'] ?? '');
                $status = $this->status($entry['result'] ?? null) ?? $this->status($component['compliance_status'] ?? null);
                $source = $status !== null ? 'table' : null;

                // Tek örnekli ekipmanın sonucu tablodan değil Gemini'nin verdict'inden gelir.
                $key = $this->key($systemName, $name);
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

        return $this->mergeDuplicates($out);
    }

    // Aynı tablo birden fazla kez okunabiliyor (örn. aynı pompa listesi ikinci kez, özellikleri boş olarak);
    // aynı sistem + ekipman türü + koda sahip kayıtlar birleştirilir, dolu değer boş olanın yerine geçer.
    private function mergeDuplicates(array $equipment): array
    {
        $merged = [];
        foreach ($equipment as $item) {
            $code = trim((string) ($item['code'] ?? ''));
            $key = $code === '' ? spl_object_id((object) []) . '#' . count($merged) : $this->key($item['system_name'], $item['equipment_name']) . '|' . mb_strtolower($code, 'UTF-8');
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
