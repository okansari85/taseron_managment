<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Tek final normalizasyon noktası.
 *
 * Girdi olan Gemini JSON'unun yapısını değiştirmez; Gemini'nin keşfettiği
 * patternleri kullanır. PDF'deki somut kontrol satırları ve sonuç hücreleri
 * Camelot koordinatları üzerinden bulunur.
 *
 * Gemini:
 * - sistem/template
 * - kontrol kodu patterni
 * - sonuç patterni
 * - findings
 *
 * Camelot:
 * - gerçek equipment
 * - gerçek U/UD/N hücreleri
 * - gerçek criterion metni
 * - source page
 *
 * Bu sınıf dışında yeni bir normalizer katmanı oluşturulmaz.
 */
class FireSuppressionUnifiedNormalizer
{
    public function __construct(
        private readonly TemplateDrivenFireSuppressionMatrixExtractor $matrixExtractor,
        private readonly FireSuppressionStandardResultExtractor $standardResultExtractor,
        private readonly FireSuppressionResultMerger $merger,
        private readonly FireSuppressionOverallResultFallback $overallFallback,
        private readonly CamelotPdfTableExtractor $camelotExtractor,
    ) {}

    public function normalize(string $pdfPath, array $semantic): array
    {
        $this->validateGeminiTemplate($semantic);

        // Mevcut matrix/equipment extraction aynen korunur.
        $camelotResult = $this->matrixExtractor->extract($pdfPath, $semantic);
        $camelotResult = $this->standardResultExtractor->apply($pdfPath, $semantic, $camelotResult);
        $extracted = $this->merger->merge($camelotResult, $semantic);

        // Overall result mevcut extraction'da yoksa yalnızca son çare olarak PDF'den al.
        $overall = $extracted['extracted_data']['overall_result'] ?? null;
        if ($overall === null || $overall === [] || $overall === '') {
            $fallback = $this->overallFallback->extract($pdfPath);
            if ($fallback !== []) {
                $extracted['extracted_data']['overall_result'] = $fallback;
                $extracted['extracted_data']['report']['overall_result'] = $fallback;
            }
        }

        // Somut criterion metni Gemini'den tahmin edilmez.
        // Kod hücresi -> sonuç hücresi arasındaki Camelot koordinat bölgesinden alınır.
        $criteria = $this->extractConcreteCriteria($pdfPath, $semantic);
        $extracted = $this->applyConcreteCriteria($extracted, $criteria);

        // Equipment compliance tek tek equipment kayıtlarına işlenir.
        // Ayrı equipment_compliance aggregate bloğu üretilmez.
        $extracted = $this->applyEquipmentCompliance($extracted);

        return $this->normalizeFinal($extracted);
    }

    private function validateGeminiTemplate(array $semantic): void
    {
        $systems = (array) ($semantic['template']['fire_systems']['systems'] ?? []);
        if ($systems === []) {
            throw new RuntimeException('Gemini Template yetersiz: fire_systems.systems bulunamadı.');
        }

        $usableSystems = 0;
        foreach ($systems as $system) {
            if (!is_array($system)) continue;

            $codes = [];
            $results = [];

            foreach ((array) ($system['control_items'] ?? []) as $control) {
                if (!is_array($control)) continue;
                $codes = array_merge($codes, (array) ($control['control_code_patterns'] ?? []));
                $results = array_merge($results, (array) ($control['result_patterns'] ?? []));
            }

            $camelot = (array) (($system['control_matrix'] ?? [])['camelot_extraction'] ?? []);
            $codes = array_merge($codes, (array) ($camelot['control_code_patterns'] ?? []));
            $results = array_merge($results, (array) ($camelot['result_cell_patterns'] ?? []));

            if ($this->hasPatterns($codes) && $this->hasPatterns($results)) {
                $usableSystems++;
            }
        }

        if ($usableSystems === 0) {
            throw new RuntimeException(
                'Gemini Template yetersiz: hiçbir yangın sistemi için kontrol kodu ve sonuç hücresi patterni birlikte keşfedilemedi.'
            );
        }
    }

    /**
     * Gemini'nin ham semantic çıktısına dokunmadan kopya üzerinde somut
     * criterion listesi oluşturur.
     */
    private function extractConcreteCriteria(string $pdfPath, array $semantic): array
    {
        $systems = (array) ($semantic['template']['fire_systems']['systems'] ?? []);
        if ($systems === []) return $semantic;

        $camelot = $this->camelotExtractor->extract($pdfPath);
        $tables = array_values(array_filter(
            (array) ($camelot['tables'] ?? []),
            static fn ($table): bool =>
                is_array($table)
                && !empty($table['cells'])
                && in_array(($table['flavor'] ?? ''), ['lattice', 'stream'], true)
        ));

        if ($tables === []) return $semantic;

        foreach ($systems as $systemIndex => $system) {
            if (!is_array($system)) continue;

            $controls = $this->extractCriteriaForSystem($tables, $system);
            if ($controls !== []) {
                $semantic['template']['fire_systems']['systems'][$systemIndex]['control_items'] = $controls;
            }
        }

        return $semantic;
    }

    /**
     * Her sistem kendi Gemini patternleriyle taranır.
     *
     * Sabit madde sayısı, sabit equipment sayısı veya sabit kod aralığı yoktur.
     * Camelot hücre koordinatları gerçek sınırı belirler.
     */
    private function extractCriteriaForSystem(array $tables, array $system): array
    {
        $codePatterns = [];
        $resultPatterns = [];

        foreach ((array) ($system['control_items'] ?? []) as $template) {
            if (!is_array($template)) continue;
            $codePatterns = array_merge(
                $codePatterns,
                $this->patterns($template['control_code_patterns'] ?? [])
            );
            $resultPatterns = array_merge(
                $resultPatterns,
                $this->patterns($template['result_patterns'] ?? [])
            );
        }

        $camelotTemplate = (array) (($system['control_matrix'] ?? [])['camelot_extraction'] ?? []);
        $codePatterns = array_values(array_unique(array_merge(
            $codePatterns,
            $this->patterns($camelotTemplate['control_code_patterns'] ?? [])
        )));
        $resultPatterns = array_values(array_unique(array_merge(
            $resultPatterns,
            $this->patterns($camelotTemplate['result_cell_patterns'] ?? [])
        )));

        if ($codePatterns === [] || $resultPatterns === []) return [];

        $controls = [];

        foreach ($tables as $table) {
            $cells = (array) ($table['cells'] ?? []);
            $page = (int) ($table['page'] ?? 0);

            foreach ($cells as $rowIndex => $rowCells) {
                foreach ($rowCells as $columnIndex => $cell) {
                    if (!is_array($cell)) continue;

                    $value = $this->clean($cell['text'] ?? null);
                    if ($value === null) continue;

                    $code = $this->matchControlCode($value, $codePatterns);
                    if ($code === null) continue;

                    $criterion = $this->criterionBetweenCodeAndResult(
                        $cells,
                        (int) $rowIndex,
                        (int) $columnIndex,
                        $cell,
                        $code,
                        $resultPatterns,
                        $codePatterns
                    );

                    if ($criterion === null) continue;

                    $key = $this->normalizeCode($code);
                    if (!isset($controls[$key])) {
                        $controls[$key] = [
                            'code' => $code,
                            'criterion' => $criterion,
                            'source_pages' => $page > 0 ? [$page] : [],
                        ];
                        continue;
                    }

                    $controls[$key]['source_pages'] = array_values(array_unique(array_merge(
                        (array) ($controls[$key]['source_pages'] ?? []),
                        $page > 0 ? [$page] : []
                    )));

                    // Aynı kod birden fazla tabloda yakalanırsa en dolu gerçek criterion'u tut.
                    if (mb_strlen($criterion, 'UTF-8') > mb_strlen((string) ($controls[$key]['criterion'] ?? ''), 'UTF-8')) {
                        $controls[$key]['criterion'] = $criterion;
                    }
                }
            }
        }

        uksort($controls, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
        return array_values($controls);
    }

    /**
     * Kod hücresi ile sonuç hücresi arasındaki geometrik alan criterion'dur.
     * Öncelik yatay aynı satırdır; tabloda dikey düzen varsa dikey fallback kullanılır.
     */
    private function criterionBetweenCodeAndResult(
        array $cells,
        int $rowIndex,
        int $columnIndex,
        array $codeCell,
        string $code,
        array $resultPatterns,
        array $codePatterns
    ): ?string {
        // Bazı Camelot tablolarında kod ve criterion aynı hücreye düşebilir.
        $sameCell = $this->stripControlCode($this->clean($codeCell['text'] ?? null), $code);
        if ($sameCell !== null) return $sameCell;

        $cx1 = (float) ($codeCell['x1'] ?? 0);
        $cx2 = (float) ($codeCell['x2'] ?? 0);
        $cy1 = (float) ($codeCell['y1'] ?? 0);
        $cy2 = (float) ($codeCell['y2'] ?? 0);

        if ($cx2 <= $cx1 || $cy2 <= $cy1) return null;

        $rightResults = [];
        $verticalResults = [];
        $horizontalCandidates = [];
        $verticalCandidates = [];

        foreach ($cells as $r => $rowCells) {
            foreach ($rowCells as $c => $candidateCell) {
                if (!is_array($candidateCell)) continue;
                if ((int) $r === $rowIndex && (int) $c === $columnIndex) continue;

                $text = $this->clean($candidateCell['text'] ?? null);
                if ($text === null) continue;

                // Başka bir kontrol kodu criterion bölgesine taşmasın.
                if ($this->matchControlCode($text, $codePatterns) !== null) continue;

                $x1 = (float) ($candidateCell['x1'] ?? 0);
                $x2 = (float) ($candidateCell['x2'] ?? 0);
                $y1 = (float) ($candidateCell['y1'] ?? 0);
                $y2 = (float) ($candidateCell['y2'] ?? 0);

                if ($x2 <= $x1 || $y2 <= $y1) continue;

                $xOverlap = min($cx2, $x2) - max($cx1, $x1);
                $yOverlap = min($cy2, $y2) - max($cy1, $y1);

                $xRatio = max(0, $xOverlap) / max(0.01, min($cx2 - $cx1, $x2 - $x1));
                $yRatio = max(0, $yOverlap) / max(0.01, min($cy2 - $cy1, $y2 - $y1));

                if ($this->matchResultValue($text, $resultPatterns) !== null) {
                    if ($x1 >= $cx2 && $yRatio >= 0.35) {
                        $rightResults[] = [
                            'cell' => $candidateCell,
                            'gap' => $x1 - $cx2,
                        ];
                    }
                    if ($y1 >= $cy2 && $xRatio >= 0.35) {
                        $verticalResults[] = [
                            'cell' => $candidateCell,
                            'gap' => $y1 - $cy2,
                        ];
                    }
                    continue;
                }

                if ($x1 >= $cx2 && $yRatio >= 0.35) {
                    $horizontalCandidates[] = [
                        'text' => $text,
                        'x1' => $x1,
                        'x2' => $x2,
                    ];
                } elseif ($y1 >= $cy2 && $xRatio >= 0.35) {
                    $verticalCandidates[] = [
                        'text' => $text,
                        'y1' => $y1,
                        'y2' => $y2,
                    ];
                }
            }
        }

        // 1. Kod -> sağdaki en yakın U/UD/N hücresi.
        if ($rightResults !== []) {
            usort($rightResults, static fn (array $a, array $b): int => $a['gap'] <=> $b['gap']);
            $resultCell = $rightResults[0]['cell'];
            $boundary = (float) ($resultCell['x1'] ?? 0);

            $parts = array_values(array_filter(
                $horizontalCandidates,
                static fn (array $item): bool => $item['x1'] < $boundary
            ));

            usort($parts, static fn (array $a, array $b): int => $a['x1'] <=> $b['x1']);
            $criterion = $this->joinParts(array_column($parts, 'text'));
            if ($criterion !== null) return $criterion;
        }

        // 2. Dikey tablo fallback'i.
        if ($verticalResults !== []) {
            usort($verticalResults, static fn (array $a, array $b): int => $a['gap'] <=> $b['gap']);
            $resultCell = $verticalResults[0]['cell'];
            $boundary = (float) ($resultCell['y1'] ?? 0);

            $parts = array_values(array_filter(
                $verticalCandidates,
                static fn (array $item): bool => $item['y1'] < $boundary
            ));

            usort($parts, static fn (array $a, array $b): int => $a['y1'] <=> $b['y1']);
            $criterion = $this->joinParts(array_column($parts, 'text'));
            if ($criterion !== null) return $criterion;
        }

        return null;
    }

    private function joinParts(array $parts): ?string
    {
        $out = [];

        foreach ($parts as $part) {
            $part = $this->clean($part);
            if ($part === null) continue;

            if ($out !== [] && $this->normalizeLabel(end($out)) === $this->normalizeLabel($part)) {
                continue;
            }

            $out[] = $part;
        }

        return $this->clean(implode(' ', $out));
    }

    private function matchControlCode(string $value, array $patterns): ?string
    {
        $value = trim(str_replace(["\n", "\r"], ' ', $value));
        if ($value === '') return null;

        $valueCode = null;
        if (preg_match('/^\s*([A-Za-zÇĞİÖŞÜ]{0,8}[ -]?\d+(?:[.\-]\d+)*)\b/u', $value, $match) === 1) {
            $valueCode = trim($match[1]);
        }

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') continue;

            if (@preg_match($pattern, $value) === 1) {
                return $valueCode ?? $pattern;
            }

            if (@preg_match('~' . $pattern . '~iu', $value) === 1) {
                return $valueCode ?? $pattern;
            }
        }

        return null;
    }

    private function matchResultValue(string $value, array $patterns): ?string
    {
        $value = $this->clean($value);
        if ($value === null) return null;

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') continue;

            if ($this->normalizeLabel($value) === $this->normalizeLabel($pattern)) {
                return $value;
            }

            // Regex'in uzun bir criterion metnini sonuç hücresi sanmasını engelle.
            if (!$this->isAtomicResult($value)) continue;

            if (@preg_match($pattern, $value) === 1) return $value;
            if (@preg_match('~' . $pattern . '~iu', $value) === 1) return $value;
        }

        return null;
    }

    private function isAtomicResult(string $value): bool
    {
        return preg_match('/^(?:U|UD|N)$/iu', trim($value)) === 1;
    }

    private function stripControlCode(?string $value, string $code): ?string
    {
        if ($value === null) return null;

        $value = trim(
            preg_replace(
                '/^' . preg_quote($code, '/') . '\\s*[:.)-]?\\s*/iu',
                '',
                trim($value)
            ) ?? $value
        );

        return $value !== '' ? $value : null;
    }

    private function applyConcreteCriteria(array $extracted, array $criterionSemantic): array
    {
        $criteriaSystems = (array) ($criterionSemantic['template']['fire_systems']['systems'] ?? []);
        if ($criteriaSystems === []) return $extracted;

        $data =& $extracted;
        if (isset($extracted['extracted_data']) && is_array($extracted['extracted_data'])) {
            $data =& $extracted['extracted_data'];
        }

        if (!isset($data['systems']) || !is_array($data['systems'])) return $extracted;

        foreach ($data['systems'] as &$targetSystem) {
            if (!is_array($targetSystem)) continue;

            $targetName = $this->normalizeLabel(
                $targetSystem['name'] ?? $targetSystem['system_name'] ?? null
            );
            if ($targetName === '') continue;

            foreach ($criteriaSystems as $criteriaSystem) {
                if (!is_array($criteriaSystem)) continue;

                $criteriaName = $this->normalizeLabel($criteriaSystem['system_name'] ?? null);
                if ($criteriaName === '' || $criteriaName !== $targetName) continue;

                $criteriaByCode = [];
                foreach ((array) ($criteriaSystem['control_items'] ?? []) as $criterion) {
                    if (!is_array($criterion) || empty($criterion['code'])) continue;
                    $criteriaByCode[$this->normalizeCode($criterion['code'])] = $criterion;
                }

                foreach ((array) ($targetSystem['control_items'] ?? []) as &$control) {
                    if (!is_array($control)) continue;

                    $code = $this->normalizeCode($control['code'] ?? null);
                    if ($code === '' || !isset($criteriaByCode[$code])) continue;

                    $criterion = $criteriaByCode[$code];
                    if (!empty($criterion['criterion'])) {
                        $control['criterion'] = $criterion['criterion'];
                    }
                    if (!empty($criterion['source_pages'])) {
                        $control['source_pages'] = $criterion['source_pages'];
                    }
                }
                unset($control);
            }
        }
        unset($targetSystem);

        return $extracted;
    }

    /**
     * Matrix varsa matrix sonucunu kullanır.
     * Matrix yoksa Gemini findings içindeki affected_equipment kullanılır.
     * Her iki durumda da status doğrudan equipment kaydına yazılır.
     */
    private function applyEquipmentCompliance(array $extracted): array
    {
        $data =& $extracted;
        if (isset($extracted['extracted_data']) && is_array($extracted['extracted_data'])) {
            $data =& $extracted['extracted_data'];
        }

        if (!isset($data['systems']) || !is_array($data['systems'])) return $extracted;

        $findings = is_array($data['findings'] ?? null) ? $data['findings'] : [];

        foreach ($data['systems'] as &$system) {
            if (!is_array($system)) continue;

            $components = (array) ($system['components'] ?? $system['equipment'] ?? []);
            if ($components === []) continue;

            $controls = (array) ($system['control_items'] ?? []);
            $systemName = $this->normalizeLabel($system['name'] ?? $system['system_name'] ?? null);

            $components = $this->setEquipmentStatusesFromMatrix($components, $controls);

            $hasMatrixEvidence = $this->hasEquipmentMatrixEvidence($components, $controls);
            if (!$hasMatrixEvidence) {
                $components = $this->setEquipmentStatusesFromFindings(
                    $components,
                    $findings,
                    $systemName
                );
            }

            $system['components'] = $components;
            if (array_key_exists('equipment', $system)) {
                $system['equipment'] = $components;
            }
        }
        unset($system);

        return $extracted;
    }

    private function setEquipmentStatusesFromMatrix(array $components, array $controls): array
    {
        foreach ($components as $index => $component) {
            if (!is_array($component)) continue;
            $components[$index]['compliance_status'] = 'unknown';
        }

        $byCode = [];
        foreach ($components as $index => $component) {
            $code = $this->normalizeCode($component['code'] ?? null);
            if ($code !== '') $byCode[$code] = $index;
        }

        foreach ($controls as $control) {
            if (!is_array($control)) continue;

            foreach ((array) ($control['results'] ?? []) as $result) {
                if (!is_array($result)) continue;

                $equipmentCode = $this->normalizeCode(
                    $result['equipment_code'] ?? $result['equipment'] ?? null
                );
                if ($equipmentCode === '' || !isset($byCode[$equipmentCode])) continue;

                $resultValue = $this->normalizeResult($result['result'] ?? null);
                if ($resultValue === null) continue;

                $index = $byCode[$equipmentCode];
                if ($resultValue === 'uygun_degil') {
                    $components[$index]['compliance_status'] = 'uygun_degil';
                } elseif ($resultValue === 'uygun') {
                    // Aynı equipment için UD daha önce geldiyse U ile ezme.
                    if (($components[$index]['compliance_status'] ?? 'unknown') !== 'uygun_degil') {
                        $components[$index]['compliance_status'] = 'uygun';
                    }
                }
            }
        }

        return $components;
    }

    private function hasEquipmentMatrixEvidence(array $components, array $controls): bool
    {
        $codes = [];
        foreach ($components as $component) {
            if (!is_array($component)) continue;
            $code = $this->normalizeCode($component['code'] ?? null);
            if ($code !== '') $codes[$code] = true;
        }

        if ($codes === []) return false;

        foreach ($controls as $control) {
            if (!is_array($control)) continue;
            foreach ((array) ($control['results'] ?? []) as $result) {
                if (!is_array($result)) continue;
                $equipmentCode = $this->normalizeCode(
                    $result['equipment_code'] ?? $result['equipment'] ?? null
                );
                if ($equipmentCode !== '' && isset($codes[$equipmentCode])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function setEquipmentStatusesFromFindings(
        array $components,
        array $findings,
        string $systemName
    ): array {
        $byCode = [];
        foreach ($components as $index => $component) {
            if (!is_array($component)) continue;
            $components[$index]['compliance_status'] = $components[$index]['compliance_status'] ?? 'unknown';

            $code = $this->normalizeCode($component['code'] ?? null);
            if ($code !== '') $byCode[$code] = $index;
        }

        foreach ($findings as $finding) {
            if (!is_array($finding)) continue;

            $findingSystem = $this->normalizeLabel($finding['system_name'] ?? null);
            if ($findingSystem !== '' && $systemName !== '' && $findingSystem !== $systemName) {
                continue;
            }

            $affected = array_values(array_filter(array_map(
                fn ($value) => $this->normalizeCode($value),
                (array) ($finding['affected_equipment'] ?? [])
            )));

            $description = $this->normalizeCode($finding['description'] ?? null);

            foreach ($byCode as $code => $index) {
                if (in_array($code, $affected, true)) {
                    $components[$index]['compliance_status'] = 'uygun_degil';
                    continue;
                }

                if ($description !== '' && str_contains($description, $code)) {
                    $components[$index]['compliance_status'] = 'uygun_degil';
                }
            }
        }

        return $components;
    }

    private function normalizeFinal(array $semantic): array
    {
        $data = is_array($semantic['extracted_data'] ?? null)
            ? $semantic['extracted_data']
            : $semantic;

        $report = is_array($data['report'] ?? null) ? $data['report'] : [];
        $systems = is_array($data['systems'] ?? null)
            ? $data['systems']
            : (is_array($data['fire_systems'] ?? null) ? $data['fire_systems'] : []);
        $findings = is_array($data['findings'] ?? null) ? $data['findings'] : [];

        $normalizedSystems = [];
        $equipmentCount = 0;
        $controlCount = 0;

        foreach ($systems as $system) {
            if (!is_array($system)) continue;

            $name = $this->stringOrNull($system['name'] ?? $system['system_name'] ?? null);
            if ($name === null) continue;

            $category = $this->normalizeCategory($system['category'] ?? null);
            $components = $this->normalizeComponents(
                (array) ($system['components'] ?? $system['equipment'] ?? [])
            );
            $controls = $this->normalizeControls((array) ($system['control_items'] ?? []));

            $known = (bool) ($system['equipment_count_known'] ?? (count($components) > 0));
            $systemEquipmentCount = $known
                ? max(0, (int) ($system['equipment_count'] ?? count($components)))
                : count($components);

            $normalizedSystems[] = [
                'name' => $name,
                'category' => $category,
                'equipment_count' => $systemEquipmentCount,
                'equipment_count_known' => $known,
                'control_count' => count($controls),
                'components' => $components,
                'control_items' => $controls,
            ];

            $equipmentCount += count($components);
            $controlCount += count($controls);
        }

        $normalizedFindings = $this->normalizeFindings($findings);
        $coveredCategories = array_values(array_unique(array_filter(array_map(
            static fn (array $system) => $system['category'] ?? null,
            $normalizedSystems
        ))));

        return [
            'report' => [
                'report_no' => $this->stringOrNull($report['report_no'] ?? null),
                'company_name' => $this->stringOrNull($report['company_name'] ?? null),
                'control_date' => $this->dateOrNull($report['control_date'] ?? null),
                'next_control_date' => $this->dateOrNull($report['next_control_date'] ?? null),
                'overall_result' => $this->normalizeResult(
                    $report['overall_result'] ?? ($data['overall_result'] ?? null)
                ),
            ],
            'covered_categories' => $coveredCategories,
            'systems' => $normalizedSystems,
            'findings' => $normalizedFindings,
            'matched_inventory_items' => (array) ($data['matched_inventory_items'] ?? []),
            'candidate_inventory_items' => (array) ($data['candidate_inventory_items'] ?? []),
            'unmatched_codes' => array_values(array_filter(array_map(
                'strval',
                (array) ($data['unmatched_codes'] ?? [])
            ))),
            'analyzer' => [
                'version' => 'unified-normalizer-2',
                'table_count' => $this->templateTableCount($semantic),
                'equipment_count' => $equipmentCount,
                'control_count' => $controlCount,
                'finding_count' => count($normalizedFindings),
                'fixture_mode' => false,
            ],
            'fixture_id' => null,
        ];
    }

    private function normalizeComponents(array $components): array
    {
        $out = [];
        $seen = [];

        foreach ($components as $component) {
            if (!is_array($component)) continue;

            $code = $this->stringOrNull($component['code'] ?? null);
            $name = $this->stringOrNull($component['name'] ?? null);
            if ($code === null && $name === null) continue;

            $key = mb_strtoupper(trim((string) ($code ?? $name)), 'UTF-8');
            if ($key !== '' && isset($seen[$key])) continue;
            if ($key !== '') $seen[$key] = true;

            $out[] = [
                'code' => $code,
                'name' => $name,
                'location' => $this->stringOrNull($component['location'] ?? null),
                'brand' => $this->stringOrNull($component['brand'] ?? null),
                'model' => $this->stringOrNull($component['model'] ?? null),
                'serial_no' => $this->stringOrNull($component['serial_no'] ?? null),
                'properties' => is_array($component['properties'] ?? null)
                    ? $component['properties']
                    : [],
                'source_pages' => $this->pages($component['source_pages'] ?? []),
                'compliance_status' => $this->stringOrNull($component['compliance_status'] ?? null) ?? 'unknown',
            ];
        }

        return $out;
    }

    private function normalizeControls(array $controls): array
    {
        $out = [];
        $seen = [];

        foreach ($controls as $control) {
            if (!is_array($control)) continue;

            $code = trim((string) ($control['code'] ?? $control['control_code'] ?? ''));
            if ($code === '') continue;

            $key = $this->normalizeCode($code);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $scope = strtolower(trim((string) ($control['scope'] ?? 'system')));
            $scope = in_array($scope, ['equipment', 'system'], true) ? $scope : 'system';

            $equipment = trim((string) ($control['equipment'] ?? ''));
            if ($scope === 'system') $equipment = '';

            $criterion = $this->stringOrNull(
                $control['criterion'] ?? $control['description'] ?? null
            );

            $out[] = [
                'code' => $code,
                'description' => $criterion,
                'criterion' => $criterion,
                'scope' => $scope,
                'equipment' => $equipment,
                // Standard (non-matrix) controls carry a single scalar 'result'
                // (U/UD/...); matrix controls carry a per-equipment 'results' array
                // instead and have no top-level scalar result. Expose both rather
                // than only reading 'results', which silently dropped every
                // standard control's result.
                'result' => $this->stringOrNull($control['result'] ?? null),
                'result_normalized' => $this->normalizeResult($control['result'] ?? null),
                'results' => $this->normalizeResults((array) ($control['results'] ?? [])),
                'source_pages' => $this->pages($control['source_pages'] ?? []),
            ];
        }

        return $out;
    }

    private function normalizeResults(array $results): array
    {
        $out = [];

        foreach ($results as $key => $value) {
            if (is_array($value)) {
                if (array_key_exists('equipment_code', $value) || array_key_exists('result', $value)) {
                    $out[] = [
                        'equipment_code' => $this->stringOrNull(
                            $value['equipment_code'] ?? $value['equipment'] ?? null
                        ),
                        'result' => $this->stringOrNull($value['result'] ?? null),
                        'value' => $value['value'] ?? null,
                        'source_pages' => $this->pages($value['source_pages'] ?? []),
                    ];
                } else {
                    $out[$key] = $value;
                }
            } elseif ($value !== null) {
                $normalizedKey = trim((string) $key);
                if ($normalizedKey !== '') {
                    $out[$normalizedKey] = trim((string) $value);
                }
            }
        }

        return $out;
    }

    private function normalizeFindings(array $findings): array
    {
        $out = [];

        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) continue;

            $description = trim((string) ($finding['description'] ?? ''));
            if ($description === '') continue;

            $out[] = [
                'id' => $this->stringOrNull($finding['id'] ?? null) ?? 'finding-' . ($index + 1),
                'system_name' => $this->stringOrNull($finding['system_name'] ?? null),
                'description' => $description,
                'affected_equipment' => array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array) ($finding['affected_equipment'] ?? [])
                )))),
                'source_pages' => $this->pages($finding['source_pages'] ?? []),
            ];
        }

        return $out;
    }

    private function templateTableCount(array $semantic): int
    {
        $count = 0;

        foreach (
            (array) ($semantic['template']['fire_systems']['systems'] ?? $semantic['template']['systems'] ?? [])
            as $system
        ) {
            if (is_array($system)) {
                $count += count((array) ($system['tables'] ?? []));
            }
        }

        return $count;
    }

    private function pages(mixed $pages): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                fn ($page) => is_numeric($page) ? (int) $page : null,
                (array) $pages
            ),
            fn ($page) => $page !== null && $page > 0
        )));
    }

    private function normalizeCategory(mixed $category): string
    {
        $value = mb_strtolower(trim((string) $category), 'UTF-8');
        $allowed = [
            'yangin_dolabi',
            'yangin_pompasi',
            'hidrant',
            'sprinkler',
            'su_alma_verme',
            'su_deposu',
            'sabit_boru_tesisati',
            'gazli_sondurme',
            'diger',
        ];

        return in_array($value, $allowed, true) ? $value : 'diger';
    }

    private function normalizeCode(mixed $value): string
    {
        return mb_strtoupper(
            preg_replace('/\s+/u', '', trim((string) $value)) ?? '',
            'UTF-8'
        );
    }

    private function normalizeLabel(mixed $value): string
    {
        return rtrim(
            mb_strtolower(
                preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '',
                'UTF-8'
            ),
            ':'
        );
    }

    private function normalizeResult(mixed $value): ?string
    {
        if ($value === null) return null;

        // overall_result travels as {status, text} - reduce it to the status text
        // before normalizing rather than crashing on the array-to-string cast.
        if (is_array($value)) {
            $value = $value['status'] ?? $value['text'] ?? null;
            if ($value === null) return null;
        }

        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        if ($value === '') return null;

        if (in_array($value, ['uygun', 'u', 'ok'], true)) return 'uygun';
        if (in_array($value, ['uygun_degil', 'uygun değil', 'ud', 'uygunsuz'], true)) {
            return 'uygun_degil';
        }

        return $value;
    }

    private function hasPatterns(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (trim((string) $pattern) !== '') return true;
        }

        return false;
    }

    private function patterns(mixed $patterns): array
    {
        return array_values(array_filter(
            array_map(static fn ($value): string => trim((string) $value), (array) $patterns),
            static fn (string $value): bool => $value !== ''
        ));
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) return null;

        $value = str_replace(["\r", "\n"], ' ', (string) $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B|:");

        return $value === '' ? null : $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) return null;

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if ($value === null) return null;

        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('Y-m-d', $timestamp);
    }
}
