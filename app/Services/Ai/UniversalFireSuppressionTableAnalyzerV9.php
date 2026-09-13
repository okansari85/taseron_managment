<?php
namespace App\Services\Ai;

/**
 * V9: Kullanıcıya gösterilecek sistem özetini sadeleştirir.
 * Matrix yoktur. Ekipman bazlı U/UD/N zorunlu değildir.
 * Sistem bazlı kontrol tabloları ayrıca yakalanır ve semantic sisteme bağlanır.
 */
class UniversalFireSuppressionTableAnalyzerV9 extends UniversalFireSuppressionTableAnalyzerV8
{
    public function analyze(array $pages, array $semantic): array
    {
        $result = parent::analyze($pages, $semantic);

        // Önce parent'ın ekipman bazlı kontrol sonuçlarını okunabilir yapıya çevir.
        $controlMap = [];
        foreach ((array)($result['control_matrix'] ?? []) as $control) {
            $code = trim((string)($control['control_code'] ?? ''));
            if ($code === '') continue;
            $controlMap[$code] = [
                'code' => $code,
                'description' => $control['description'] ?? null,
            ];
        }

        $equipment = [];
        foreach ((array)($result['equipment'] ?? []) as $item) {
            $controls = [];
            foreach ((array)($item['control_items'] ?? []) as $code => $status) {
                $code = trim((string)$code);
                $status = strtoupper(str_replace('.', '', trim((string)$status)));
                if ($code === '' || !in_array($status, ['U', 'UD', 'N'], true)) continue;
                $controls[] = [
                    'code' => $code,
                    'status' => $status,
                    'description' => $controlMap[$code]['description'] ?? null,
                ];
            }
            $item['control_items'] = $controls;
            $item['status'] = $this->equipmentStatus($controls);
            $item['nonconforming_controls'] = array_values(array_filter(
                $controls,
                fn(array $c) => ($c['status'] ?? null) === 'UD'
            ));
            $equipment[] = $item;
        }
        $result['equipment'] = $equipment;

        // NETA benzeri sistem-bazlı raporlar için D.1 / D.2 ... gibi
        // kontrolleri semantic sistemlere bağla.
        $systemControls = $this->extractSystemControls($pages, (array)($semantic['systems'] ?? []));

        $systems = [];
        foreach ((array)($result['systems'] ?? []) as $system) {
            $name = (string)($system['name'] ?? '');
            $category = (string)($system['category'] ?? 'diger');
            $key = $this->systemKey($name, $category);
            $components = array_values(array_filter(
                $equipment,
                fn(array $item) => (string)($item['category'] ?? '') === $category
                    && ($name === '' || (string)($item['system_name'] ?? '') === $name)
            ));

            $controls = $systemControls[$key] ?? [];
            if (!$controls) {
                $controls = $this->controlsFromEquipment($components);
            }

            $nonconformingEquipment = 0;
            foreach ($components as $component) {
                if (($component['status'] ?? null) === 'uygun_degil') {
                    $nonconformingEquipment++;
                }
            }

            $system['components'] = array_map(fn(array $item) => [
                'code' => $item['code'] ?? null,
                'name' => $item['name'] ?? null,
                'location' => $item['location_note'] ?? null,
                'brand' => $item['brand'] ?? null,
                'model' => $item['model'] ?? null,
                'serial_no' => $item['serial_no'] ?? null,
            ], $components);
            $system['equipment_count'] = count($components);
            $system['equipment_count_known'] = count($components) > 0;
            $system['control_count'] = count($controls);
            $system['nonconforming_count'] = count(array_filter(
                $controls,
                fn(array $c) => ($c['status'] ?? null) === 'UD'
            ));
            $system['nonconforming_equipment_count'] = $nonconformingEquipment;
            $system['status'] = $this->systemStatus($system, $components, $controls);
            $system['control_items'] = array_values($controls);

            // Matrix ve ham tablo UI çıktısından tamamen çıkar.
            unset($system['equipment_matrix'], $system['tables']);
            $systems[] = $system;
        }

        $result['systems'] = array_values($systems);
        unset($result['control_matrix'], $result['tables']);

        $result['analyzer']['version'] = '9.0.0';
        $result['analyzer']['equipment_count'] = count($equipment);
        $result['analyzer']['control_count'] = array_sum(array_map(
            fn(array $s) => (int)($s['control_count'] ?? 0),
            $systems
        ));

        return $result;
    }

    private function extractSystemControls(array $pages, array $semanticSystems): array
    {
        $definitions = [];
        foreach ($semanticSystems as $system) {
            if (!is_array($system)) continue;
            $name = trim((string)($system['name'] ?? ''));
            if ($name === '') continue;
            $category = (string)($system['category'] ?? 'diger');
            $definitions[] = [
                'name' => $name,
                'category' => $category,
                'key' => $this->systemKey($name, $category),
                'tokens' => $this->systemTokens($name, $category),
            ];
        }

        $out = [];
        $current = null;
        foreach (array_values($pages) as $pageIndex => $page) {
            foreach (preg_split('/\R/u', (string)$page) ?: [] as $raw) {
                $line = trim((string)$raw);
                if ($line === '') continue;

                // Yeni bölüm başlığı: önce mevcut sistem bağlamını değiştir.
                $section = $this->matchSection($line, $definitions);
                if ($section !== null && !$this->containsControlCode($line)) {
                    $current = $section;
                    continue;
                }

                // Bulgular/sonuç/not bölümlerine girildiğinde sistem kontrol bağlamını kes.
                if (preg_match('/^(?:\d+\.?\s*)?(?:tespit ve bulgular|bulgular|sonuc ve kanaat|sonuç ve kanaat|onay|notlar?)\b/iu', $line)) {
                    $current = null;
                    continue;
                }

                if ($current === null) continue;
                foreach ($this->parseSystemControlLine($line) as $control) {
                    $control['source_pages'] = [$pageIndex + 1];
                    $out[$current['key']][] = $control;
                }
            }
        }

        foreach ($out as $key => $controls) {
            $unique = [];
            foreach ($controls as $control) {
                $k = (string)$control['code'] . '|' . $this->normalizeKey((string)$control['description']);
                if (!isset($unique[$k])) {
                    $unique[$k] = $control;
                } else {
                    $unique[$k]['source_pages'] = array_values(array_unique(array_merge(
                        (array)($unique[$k]['source_pages'] ?? []),
                        (array)($control['source_pages'] ?? [])
                    )));
                }
            }
            $out[$key] = array_values($unique);
        }
        return $out;
    }

    private function parseSystemControlLine(string $line): array
    {
        if (!preg_match_all('/(?<![A-Za-z0-9])([A-ZÇĞİÖŞÜ]{1,3}\.\d+|\d+\.\d+)(?=[\s\.)])/u', $line, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $i => $match) {
            $code = trim((string)$match[0]);
            $start = $match[1] + strlen($match[0]);
            $end = isset($m[1][$i + 1]) ? $m[1][$i + 1][1] : strlen($line);
            $body = trim(substr($line, $start, $end - $start));

            // PDF extraction bazen UD'yi U.D olarak böler. U.Y ise U + Y
            // (ör. hortum tipi) olabileceğinden sadece ilk gerçek durum kodunu al.
            if (!preg_match('/(?<![A-Za-z])((?:U\.?D)|U|N)(?![A-Za-z])/iu', $body, $sm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $status = strtoupper(str_replace('.', '', trim((string)$sm[1][0])));
            if (!in_array($status, ['U', 'UD', 'N'], true)) continue;

            $description = trim(substr($body, 0, $sm[1][1]));
            $description = preg_replace('/^[*]+\s*/u', '', $description);
            $description = preg_replace('/\s+/u', ' ', (string)$description);
            $out[] = [
                'code' => $code,
                'description' => trim((string)$description),
                'status' => $status,
            ];
        }
        return $out;
    }

    private function matchSection(string $line, array $definitions): ?array
    {
        $normalized = $this->normalizeKey($line);
        $best = null;
        $bestScore = 0;
        foreach ($definitions as $definition) {
            $score = 0;
            foreach ($definition['tokens'] as $token) {
                if (mb_strlen($token, 'UTF-8') >= 4 && str_contains($normalized, $token)) $score++;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $definition;
            }
        }
        return $bestScore >= 2 ? $best : null;
    }

    private function containsControlCode(string $line): bool
    {
        return preg_match('/(?<![A-Za-z0-9])(?:[A-ZÇĞİÖŞÜ]{1,3}\.\d+|\d+\.\d+)(?=[\s\.)])/u', $line) === 1;
    }

    private function controlsFromEquipment(array $components): array
    {
        $byCode = [];
        foreach ($components as $item) {
            foreach ((array)($item['control_items'] ?? []) as $control) {
                $code = (string)($control['code'] ?? '');
                if ($code !== '') $byCode[$code] = $control;
            }
        }
        return array_values($byCode);
    }

    private function equipmentStatus(array $controls): string
    {
        foreach ($controls as $control) {
            if (($control['status'] ?? null) === 'UD') return 'uygun_degil';
        }
        return $controls ? 'uygun' : 'belirtilmemis';
    }

    private function systemStatus(array $system, array $components, array $controls): string
    {
        if (($system['nonconforming_equipment_count'] ?? 0) > 0) return 'uygunsuz';
        if (count(array_filter($controls, fn(array $c) => ($c['status'] ?? null) === 'UD')) > 0) return 'uygunsuz';
        if ($controls) return 'uygun';
        return 'belirtilmemis';
    }

    private function systemTokens(string $name, string $category): array
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', $this->normalizeKey($name)) ?: [],
            fn($v) => mb_strlen($v, 'UTF-8') >= 4
        ));
        $extra = match ($category) {
            'yangin_dolabi' => ['yangin', 'dolabi', 'dolap', 'hortum'],
            'yangin_pompasi' => ['yangin', 'pompa'],
            'su_deposu' => ['su', 'deposu', 'depo'],
            'sprinkler' => ['sprinkler', 'yagmurlama'],
            'hidrant' => ['hidrant', 'itfaiye'],
            'gazli_sondurme' => ['gazli', 'sondurme'],
            default => [],
        };
        return array_values(array_unique(array_merge($tokens, $extra)));
    }

    private function systemKey(string $name, string $category): string
    {
        return $this->normalizeKey($name) . '|' . $category;
    }

    private function normalizeKey(string $value): string
    {
        return strtr(mb_strtolower(trim($value), 'UTF-8'), [
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o',
            'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u',
        ]);
    }
}
