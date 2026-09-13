<?php

namespace App\Services\Ai;

/**
 * V8: V7'nin tablo/semantic eşleştirmesinden sonra fiziksel ekipmanı tekilleştirir.
 *
 * Aynı ekipman tablosu Gemini'nin birden fazla semantic sistem adıyla eşleşebilir.
 * Bu durumda aynı fiziksel kodun birden fazla sistem altında çoğaltılmasını önler.
 * Öncelik: gerçek ekipman kategorisi > diger.
 * Firma/şablon özelinde hiçbir kural içermez.
 */
class UniversalFireSuppressionTableAnalyzerV8 extends UniversalFireSuppressionTableAnalyzerV7
{
    public function analyze(array $pages, array $semantic): array
    {
        $result = parent::analyze($pages, $semantic);

        $equipment = $this->uniquePhysicalEquipment($result['equipment'] ?? []);
        $result['equipment'] = $equipment;

        $preferredByCode = [];
        foreach ($equipment as $item) {
            $code = trim((string)($item['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $preferredByCode[$code] = $item;
        }

        $result['systems'] = array_map(function (array $system) use ($preferredByCode): array {
            $components = [];
            $seen = [];

            foreach ((array)($system['components'] ?? []) as $component) {
                $code = trim((string)($component['code'] ?? ''));
                if ($code === '') {
                    continue;
                }

                // Aynı fiziksel kod başka bir sistemde daha spesifik bir kategori
                // altında tanımlandıysa generic/diger sistemden çıkar.
                if (isset($preferredByCode[$code])) {
                    $preferredCategory = (string)($preferredByCode[$code]['category'] ?? '');
                    $systemCategory = (string)($system['category'] ?? '');
                    if ($preferredCategory !== ''
                        && $systemCategory === 'diger'
                        && $preferredCategory !== 'diger') {
                        continue;
                    }
                }

                if (isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $components[] = $component;
            }

            $system['components'] = $components;
            $system['equipment_matrix'] = [
                'codes' => array_values(array_map(fn ($c) => $c['code'] ?? null, $components)),
                'locations' => array_values(array_map(fn ($c) => $c['location'] ?? null, $components)),
            ];

            return $system;
        }, (array)($result['systems'] ?? []));

        $result['analyzer']['version'] = '8.0.0';
        $result['analyzer']['equipment_count'] = count($equipment);
        $result['analyzer']['control_count'] = count($result['control_matrix'] ?? []);

        return $result;
    }

    private function uniquePhysicalEquipment(array $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code = trim((string)($item['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $groups[$code][] = $item;
        }

        $out = [];
        foreach ($groups as $code => $candidates) {
            usort($candidates, function (array $a, array $b): int {
                $pa = $this->equipmentPriority($a);
                $pb = $this->equipmentPriority($b);
                if ($pa !== $pb) {
                    return $pb <=> $pa;
                }

                return $this->equipmentRichness($b) <=> $this->equipmentRichness($a);
            });

            $winner = $candidates[0];

            // Kazanan kaydın boş alanlarını daha zengin bir duplicate kayıttan
            // tamamla; ancak kategori/sistem kimliğini değiştirme.
            foreach ($candidates as $candidate) {
                foreach (['location_note', 'brand', 'model', 'serial_no'] as $field) {
                    if (($winner[$field] ?? null) === null || trim((string)$winner[$field]) === '') {
                        if (($candidate[$field] ?? null) !== null && trim((string)$candidate[$field]) !== '') {
                            $winner[$field] = $candidate[$field];
                        }
                    }
                }

                if (empty($winner['properties']) && !empty($candidate['properties'])) {
                    $winner['properties'] = $candidate['properties'];
                }
                if (empty($winner['control_items']) && !empty($candidate['control_items'])) {
                    $winner['control_items'] = $candidate['control_items'];
                }
                $winner['source_pages'] = array_values(array_unique(array_merge(
                    (array)($winner['source_pages'] ?? []),
                    (array)($candidate['source_pages'] ?? [])
                )));
            }

            $out[] = $winner;
        }

        return $out;
    }

    private function equipmentPriority(array $item): int
    {
        $category = (string)($item['category'] ?? 'diger');
        return $category === 'diger' ? 10 : 100;
    }

    private function equipmentRichness(array $item): int
    {
        $score = 0;
        foreach (['location_note', 'brand', 'model', 'serial_no'] as $field) {
            if (($item[$field] ?? null) !== null && trim((string)$item[$field]) !== '') {
                $score += 10;
            }
        }
        $score += count((array)($item['properties'] ?? [])) * 2;
        $score += count((array)($item['control_items'] ?? [])) * 2;
        $score += count((array)($item['source_pages'] ?? []));
        return $score;
    }
}
