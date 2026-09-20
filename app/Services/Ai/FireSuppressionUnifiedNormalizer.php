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

        // Overall result mevcut extraction'da yoksa YA DA net bir uygun/değil
        // durumu içermiyorsa (tablo parçalanması yüzünden cümle "8. SONUÇ VE
        // KANAAT" dışındaki başka bir bölümden - örn. genel NOTLAR metninden -
        // yanlışlıkla alınmış ve asıl karar cümlesine hiç ulaşmamış olabilir),
        // son çare olarak PDF'in ham metninden (pdftotext) al - o zaten güvenilir
        // çalışıyor.
        $overall = $extracted['extracted_data']['overall_result'] ?? null;
        $hasStatus = is_array($overall) && trim((string) ($overall['status'] ?? '')) !== '';
        if ($overall === null || $overall === [] || $overall === '' || !$hasStatus) {
            $fallback = $this->overallFallback->extract($pdfPath);
            if ($fallback !== [] && trim((string) ($fallback['status'] ?? '')) !== '') {
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

        // A system's compliance data can ALSO come entirely from Gemini's own
        // direct-read equipment (extracted_data.equipment[].control_items) -
        // a single-equipment report (e.g. a forklift/transpalet) has no
        // Camelot code/result pattern at all by design, since the whole
        // checklist was read directly instead. Collect which system_names
        // have such a group so those count as usable too.
        $directReadSystemNames = [];
        foreach ((array) ($semantic['extracted_data']['equipment'] ?? []) as $directItem) {
            if (!is_array($directItem)) continue;
            $name = trim((string) ($directItem['system_name'] ?? ''));
            if ($name !== '' && (array) ($directItem['control_items'] ?? [])) {
                $directReadSystemNames[$name] = true;
            }
        }

        // A system's compliance data can ALSO come entirely from Gemini's own
        // direct-read SYSTEM-level checklist (extracted_data.systems[]) -
        // control_code_patterns/control_text_patterns/control_matrix no
        // longer exist in the schema at all for this (see
        // GeminiTemplateDiscoveryClient/TemplateDiscoveryFireSuppressionAnalyzer),
        // so the pattern-based "usable" check below would otherwise find
        // NOTHING for every multi-system report and always reject it.
        $directReadSystemControlNames = [];
        foreach ((array) ($semantic['extracted_data']['systems'] ?? []) as $directSystem) {
            if (!is_array($directSystem)) continue;
            $name = trim((string) ($directSystem['system_name'] ?? ''));
            if ($name !== '' && (array) ($directSystem['control_items'] ?? [])) {
                $directReadSystemControlNames[$name] = true;
            }
        }

        // A system whose ONLY real data is its equipment table (table_shape)
        // is also usable on its own - e.g. a system made purely of a dolap/
        // hidrant/tüp table with no equipment-independent checklist at all.
        $systemsWithEquipmentShape = [];
        foreach ($systems as $system) {
            if (!is_array($system)) continue;
            $name = trim((string) ($system['system_name'] ?? ''));
            if ($name !== '' && (array) ($system['equipment'] ?? [])) {
                $systemsWithEquipmentShape[$name] = true;
            }
        }

        $usableSystems = 0;
        foreach ($systems as $system) {
            if (!is_array($system)) continue;

            $systemName = trim((string) ($system['system_name'] ?? ''));
            if ($systemName !== '' && (
                isset($directReadSystemNames[$systemName])
                || isset($directReadSystemControlNames[$systemName])
                || isset($systemsWithEquipmentShape[$systemName])
            )) {
                $usableSystems++;
                continue;
            }

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
                'Gemini Template yetersiz: hiçbir yangın sistemi için kontrol kodu ve sonuç hücresi patterni (veya doğrudan okunan ekipman checklist\'i) birlikte keşfedilemedi.'
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
        // Report metadata (report_no, company, dates) is extracted by the base
        // extractor under 'report_information' (real Camelot-driven field values),
        // not 'report' - 'report' only ever carries overall_result (set by the
        // merger). Read both so neither source is silently ignored.
        $reportInfo = is_array($data['report_information'] ?? null) ? $data['report_information'] : [];
        $systems = is_array($data['systems'] ?? null)
            ? $data['systems']
            : (is_array($data['fire_systems'] ?? null) ? $data['fire_systems'] : []);
        $findings = is_array($data['findings'] ?? null) ? $data['findings'] : [];

        $normalizedSystems = [];
        // Flat, top-level equipment list — the frontend's matching/eşleştirme
        // UI and per-equipment control-item review (Onayla adımı) read this
        // (draft.equipment[]), NOT systems[].components[]. That top-level
        // field never existed before, so the matching table always rendered
        // empty ("Bileşenler: 0") and control items never populated,
        // regardless of how much data the systems actually carried.
        $equipment = [];
        $equipmentCount = 0;
        $controlCount = 0;

        foreach ($systems as $system) {
            if (!is_array($system)) continue;

            $name = $this->stringOrNull($system['name'] ?? $system['system_name'] ?? null);
            if ($name === null) continue;

            $category = $this->normalizeCategory($system['category'] ?? null, $name);
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

            foreach ($components as $component) {
                $equipment[] = $this->buildEquipmentEntry($component, $category, $controls);
            }

            $equipmentCount += count($components);
            $controlCount += count($controls);
        }

        $normalizedFindings = $this->normalizeFindings($findings, $normalizedSystems);
        $coveredCategories = array_values(array_unique(array_filter(array_map(
            static fn (array $system) => $system['category'] ?? null,
            $normalizedSystems
        ))));

        // AI already reads the report's own real "SONUÇ VE KANAAT" paragraph
        // (extracted_data.overall_result.text) - normalizeResult() below
        // reduces the {status, text} pair down to JUST the status for the
        // compliance enum, which used to silently throw the real sentence
        // away entirely (never reached the frontend, findings-empty reports
        // had no verdict text anywhere to show/save). Keep it alongside the
        // status instead of discarding it.
        $overallResultRaw = $report['overall_result'] ?? ($data['overall_result'] ?? null);
        $overallResultText = is_array($overallResultRaw) ? $this->stringOrNull($overallResultRaw['text'] ?? null) : null;

        return [
            'report' => [
                'report_no' => $this->stringOrNull($report['report_no'] ?? $reportInfo['report_no'] ?? null),
                'company_name' => $this->stringOrNull($report['company_name'] ?? $reportInfo['company_title'] ?? null),
                'report_date' => $this->dateOrNull($report['report_date'] ?? $reportInfo['report_date'] ?? null),
                'control_date' => $this->dateOrNull($report['control_date'] ?? $reportInfo['control_date'] ?? null),
                'next_control_date' => $this->dateOrNull($report['next_control_date'] ?? $reportInfo['validity_date'] ?? null),
                'overall_result' => $this->normalizeResult($overallResultRaw),
                'overall_result_text' => $overallResultText,
            ],
            'covered_categories' => $coveredCategories,
            'systems' => $normalizedSystems,
            'equipment' => $equipment,
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

        foreach ($components as $component) {
            if (!is_array($component)) continue;

            $code = $this->stringOrNull($component['code'] ?? null);
            $name = $this->stringOrNull($component['name'] ?? null);
            if ($code === null && $name === null) continue;

            // Equipment codes are NOT a reliable identity by themselves - numbering
            // legitimately restarts per building/location, and two genuinely
            // different items can even end up with identical property text by
            // coincidence (same brand, same measurement). Deduping here on
            // code (or code+properties) risks silently merging distinct real
            // occurrences. The extraction layer already scopes each occurrence to
            // its own block/table position, so trust that and pass every
            // occurrence through as its own record rather than re-deriving
            // "sameness" from content here.
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

    // Builds one flat top-level equipment[] entry from a component + the
    // system's already-normalized control_items - linking each control back
    // to THIS equipment by matching either a single-scope control
    // (scope=equipment, equipment=code) or a matrix control's per-equipment
    // results[] entry (equipment_code=code). Field names here (category,
    // location_note) deliberately match FireSuppressionMatchingProfile's
    // CANDIDATE_FIELDS, since the matching engine reads directly from this
    // array - the old raw component shape ('location', no 'category') meant
    // the matching engine's category/location filters were always silently
    // empty.
    private function buildEquipmentEntry(array $component, string $category, array $controls): array
    {
        $code = $component['code'] ?? null;
        $items = [];
        $hasNonconforming = false;
        $hasConforming = false;

        foreach ($controls as $control) {
            $status = null;

            if ($code !== null && ($control['scope'] ?? '') === 'equipment' && ($control['equipment'] ?? '') === $code) {
                $status = $control['result_normalized'] ?? null;
            } elseif ($code !== null) {
                foreach ((array) ($control['results'] ?? []) as $result) {
                    if (($result['equipment_code'] ?? null) === $code) {
                        $status = $this->normalizeResult($result['result'] ?? null);
                        break;
                    }
                }
            }

            if ($status === null) continue;

            $items[] = [
                'code' => $control['code'] ?? null,
                'title' => $control['criterion'] ?? $control['code'] ?? '',
                'status' => $status,
                // Kontrol maddesinin kendi bir "tespit/açıklama" metni yok -
                // sadece kriter (yukarıdaki title) + sonuç var. Burayı da
                // criterion ile doldurmak title'ın birebir tekrarına yol
                // açıyordu (frontend'de aynı metin hem başlıkta hem altındaki
                // kutuda görünüyordu). Boş bırakılır - kullanıcı isterse
                // kendi tespitini yazar.
                'description' => null,
            ];

            if ($status === 'uygun_degil') $hasNonconforming = true;
            elseif ($status === 'uygun') $hasConforming = true;
        }

        return [
            'code' => $code,
            'category' => $category,
            'location_note' => $component['location'] ?? null,
            'brand' => $component['brand'] ?? null,
            'model' => $component['model'] ?? null,
            'serial_no' => $component['serial_no'] ?? null,
            'result' => $hasNonconforming ? 'uygun_degil' : ($hasConforming ? 'uygun' : null),
            'note' => null,
            // Serbest formattaki ekipman özellikleri (örn. "Ölçülen Basınç",
            // "Hortum Uzunluğu") - components[].properties'te zaten çıkarılmış
            // durumdaydı ama bu equipment[] düzleştirmesine hiç aktarılmıyordu,
            // bu yüzden rapor kaydedilirken tamamen kayboluyordu.
            'properties' => is_array($component['properties'] ?? null) ? $component['properties'] : [],
            'control_items' => $items,
        ];
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

    private function naturalEquipmentOrder(string $code): array
    {
        preg_match('/^(\D*)(\d*)(.*)$/u', $code, $m);
        return [$m[1] ?? $code, isset($m[2]) && $m[2] !== '' ? (int) $m[2] : -1, $m[3] ?? ''];
    }

    /**
     * A finding usually names equipment by a short acronym of the equipment's
     * OWN type name (e.g. "Yangın Dolapları" -> "YD", "Yangın Hidrantı" -> "YH"),
     * not the bare code - derive it from the first letter of each word rather
     * than hardcoding a per-vendor abbreviation.
     */
    private function equipmentCodePrefix(string $equipmentName): string
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', trim(mb_strtoupper($equipmentName, 'UTF-8'))) ?: [],
            static fn (string $w): bool => $w !== ''
        ));
        if (count($words) < 2) return '';

        $letters = '';
        foreach ($words as $word) {
            $letters .= mb_substr($word, 0, 1, 'UTF-8');
        }
        return $letters;
    }

    private function normalizeFindings(array $findings, array $normalizedSystems = []): array
    {
        // Gemini deliberately never fills affected_equipment (the prompt tells it
        // not to - equipment codes just stay inline in the description text). Find
        // them here instead, by matching the description against the REAL equipment
        // codes already extracted for that finding's own system - never a generic
        // pattern - so this only ever reports a code we've actually confirmed
        // exists, rather than guessing from arbitrary numbers in the text.
        $codesBySystem = [];
        $prefixBySystem = [];
        foreach ($normalizedSystems as $system) {
            if (!is_array($system)) continue;
            $systemKey = $this->normalizeLabel((string) ($system['name'] ?? ''));
            if ($systemKey === '') continue;
            $codes = [];
            $equipmentName = '';
            foreach ((array) ($system['components'] ?? []) as $component) {
                $code = trim((string) ($component['code'] ?? ''));
                if ($code !== '') $codes[] = $code;
                if ($equipmentName === '') $equipmentName = trim((string) ($component['name'] ?? ''));
            }
            $codesBySystem[$systemKey] = array_values(array_unique($codes));
            // A finding usually refers to equipment by a short acronym of its own
            // type name (e.g. "Yangın Dolapları" -> "YD-9"), not the bare code -
            // derive that acronym from the SAME equipment_name Camelot already
            // read, rather than hardcoding it per report/vendor.
            $prefixBySystem[$systemKey] = $this->equipmentCodePrefix($equipmentName);
        }

        $out = [];

        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) continue;

            $description = trim((string) ($finding['description'] ?? ''));
            if ($description === '') continue;

            $systemName = $this->stringOrNull($finding['system_name'] ?? null);
            $affected = array_values(array_unique(array_filter(array_map(
                'strval',
                (array) ($finding['affected_equipment'] ?? [])
            ))));

            if ($systemName !== null) {
                $systemKey = $this->normalizeLabel($systemName);
                $candidateCodes = $codesBySystem[$systemKey] ?? [];
                $prefix = $prefixBySystem[$systemKey] ?? '';
                $codeLookup = array_flip($candidateCodes);

                if ($prefix !== '') {
                    $quotedPrefix = preg_quote($prefix, '/');

                    // Range phrases: "YD-16 İLE YD-72 ARASI" -> every real code
                    // that actually exists between 16 and 72 (never invents a
                    // code that isn't genuinely part of this equipment).
                    if (preg_match_all(
                        '/' . $quotedPrefix . '[-\s]?(\d+)\s*(?:[İI]LE|-)\s*' . $quotedPrefix . '[-\s]?(\d+)\s*ARASI/iu',
                        $description,
                        $rangeMatches,
                        PREG_SET_ORDER
                    ) > 0) {
                        foreach ($rangeMatches as $rangeMatch) {
                            $start = (int) $rangeMatch[1];
                            $end = (int) $rangeMatch[2];
                            if ($start > $end) [$start, $end] = [$end, $start];
                            for ($n = $start; $n <= $end; $n++) {
                                if (isset($codeLookup[(string) $n])) $affected[] = (string) $n;
                            }
                        }
                    }

                    // Individual prefixed mentions: "YD-9", "YD118" (missing
                    // dash - a real typo/OCR variant in the source), "YD 9". The
                    // prefix removes the ambiguity a bare short number would have,
                    // so no length filtering is needed here.
                    foreach ($candidateCodes as $code) {
                        if (preg_match('/\b' . $quotedPrefix . '[-\s]?' . preg_quote($code, '/') . '\b/iu', $description) === 1) {
                            $affected[] = $code;
                        }
                    }
                }

                // Bare (unprefixed) mentions - keep this conservative: skip short
                // pure-digit codes ("1", "2"), too likely to coincidentally
                // appear inside an unrelated control-code reference like "5.2)".
                foreach ($candidateCodes as $code) {
                    if (mb_strlen($code) < 2 && ctype_digit($code)) continue;
                    if (preg_match('/\b' . preg_quote($code, '/') . '\b/ui', $description) === 1) {
                        $affected[] = $code;
                    }
                }

                $affected = array_values(array_unique($affected));
                usort($affected, fn (string $a, string $b) => $this->naturalEquipmentOrder($a) <=> $this->naturalEquipmentOrder($b));
            }

            $out[] = [
                'id' => $this->stringOrNull($finding['id'] ?? null) ?? 'finding-' . ($index + 1),
                'system_name' => $systemName,
                'description' => $description,
                'affected_equipment' => $affected,
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

    private function normalizeCategory(mixed $category, ?string $systemName = null): string
    {
        $value = mb_strtolower(trim((string) $category), 'UTF-8');
        // Bu liste FireSuppressionInventoryItem::CATEGORIES ile BİREBİR aynı
        // olmak zorunda - burada üretilen bir kategori, daha sonra envantere
        // eklenirken/control_items'e bağlanırken AYNI whitelist'e karşı
        // (Rule::in(CATEGORIES)) doğrulanıyor.
        $allowed = [
            'yangin_dolabi',
            'yangin_pompasi',
            'hidrant',
            'sprinkler',
            'su_deposu',
            'sabit_boru',
            'su_alma_verme',
            'gazli_sondurme',
            'yangin_algilama',
            'diger',
        ];

        if (in_array($value, $allowed, true)) return $value;

        // No explicit category is ever set upstream - nothing in the extraction
        // pipeline produces one. Derive it from the (real, Camelot-read) system
        // name instead of always falling back to 'diger', using the standard
        // Turkish fire-safety terminology rather than any one vendor's exact
        // wording, so this holds across different companies' reports.
        return $this->categoryFromSystemName($systemName ?? '');
    }

    private function categoryFromSystemName(string $systemName): string
    {
        $normalized = strtr(mb_strtolower(trim($systemName), 'UTF-8'), [
            'ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c',
        ]);
        if ($normalized === '') return 'diger';

        // NOT: raporda AYRI bir bölüm/sistem olan bir şeyi sırf sabit kategori
        // listesinde tam karşılığı yok diye başka bir kategoriye GÖMMÜYORUZ -
        // rapordaki ayrımı yok sayıp yanlış/karışık verecekti. "İtfaiye Su
        // Alma Ve Verme Ağızları" bu yüzden artık kendi gerçek kategorisi
        // (su_alma_verme, bkz. FireSuppressionInventoryItem::CATEGORIES).
        // Hâlâ hiç eşleşmeyen sistemler 'diger'e düşer, kullanıcı "Yeni
        // Sistemler" adımındaki modalde bunu KENDİ ayrı kategorisiyle çözer
        // (bkz. upload.vue applyCategoryOverridesAndContinue).
        $keywordsByCategory = [
            'yangin_dolabi' => ['dolab', 'dolap'],
            'hidrant' => ['hidrant'],
            'sprinkler' => ['yagmurlama', 'sprinkler'],
            'yangin_pompasi' => ['pompa'],
            'su_deposu' => ['su deposu', 'su tank'],
            'sabit_boru' => ['boru', 'kolektor'],
            'su_alma_verme' => ['su alma', 'su verme'],
            'gazli_sondurme' => ['gazli', 'gaz sondurme'],
            'yangin_algilama' => ['algilama', 'alarm sistem', 'dedektor', 'ihbar sistem'],
        ];

        foreach ($keywordsByCategory as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) return $category;
            }
        }

        return 'diger';
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
        // Turkish capital "İ" doesn't lowercase to a plain "i" - mb_strtolower
        // turns it into "i" + a combining dot above (U+0307), TWO codepoints.
        // That invisible extra character silently breaks any small fixed-
        // width gap in a regex below (e.g. "DEĞİLDİR" -> "deği̇ldir", where
        // the combining dot eats one of the 2 characters a ".{0,2}" gap
        // allows before "ld", so it never reaches "ld" and the whole
        // "uygun değil" phrase check misses - falling through to the bare
        // "uygun" check and wrongly reporting the OPPOSITE compliance
        // result). Strip it right after lowercasing so every check below
        // sees plain, predictable "i"s regardless of capitalization.
        $value = preg_replace('/\x{0307}/u', '', $value) ?? $value;
        if ($value === '') return null;

        // Already-canonical output (e.g. from a source that names the status
        // directly instead of a raw report code, like table_shape's own
        // "result" columns) must short-circuit here - the phrase regexes
        // below need whitespace between words ("uygun değil") and would
        // otherwise treat "uygun_degil"'s underscore as a \w character,
        // matching the bare "uygun" branch and silently flipping it back to
        // the wrong status.
        if (in_array($value, ['uygun', 'uygun_degil', 'uygulanamiyor'], true)) return $value;

        // Checkbox-style glyph marks (e.g. AKTAŞ's "✔"/"✘" legend) instead of
        // a text abbreviation - checked BEFORE the dash-stripping $compact
        // step below, since a lone "-"/"–" mark would otherwise be stripped
        // down to an empty string and fall through to the "unrecognized"
        // safety default (uygun_degil), wrongly flagging an actual "✔" (or
        // "not applicable" dash) result as non-compliant.
        if (in_array($value, ['✔', '✓'], true)) return 'uygun';
        if (in_array($value, ['✘', '✗', '×'], true)) return 'uygun_degil';
        if (in_array($value, ['–', '-', '—'], true)) return 'uygulanamiyor';

        // Tek/çift harfli kısaltmalar farklı raporlarda nokta/eğik çizgi/tire
        // ile de yazılabiliyor ("U.D", "U/D", "U-D", "N/A") - kısaltma
        // karşılaştırmasını bunlardan arındırılmış bir kopya üzerinden
        // yapıyoruz. Cümle/kelime bazlı eşleşmeler (aşağıdaki regex'ler)
        // boşluğa ihtiyaç duyduğu için ORİJİNAL $value üzerinden kalır. Bu
        // olmadan örn. "U.D" hiçbir statüye eşleşmiyor, ham haliyle geçip
        // frontend'deki 3 durum butonundan hiçbiri seçili görünmüyordu.
        $compact = preg_replace('/[.\/\-\s]+/u', '', $value) ?? $value;

        if (in_array($compact, ['uygun', 'u', 'ok', 'uygundur'], true)) return 'uygun';
        if (in_array($compact, ['uygundegil', 'ud', 'uygunsuz'], true)) {
            return 'uygun_degil';
        }
        // Raporlarda üçüncü bir kısaltma olarak "N" (N/A) veya "U.Y" da
        // kullanılıyor - ikisi de "uygulanamıyor" (kontrol maddesi bu
        // ekipman/sistem için anlamsız). "U.Y" için kanıt: NETA fixture'ında
        // bu kodu taşıyan TÜM maddelerin kriter metni bu tesise uygulanamaz
        // koşullu senaryolar ("LPG ikmal istasyonlarında...", "...su
        // sistemine bağlı ise..." gibi) - "Uy(gulanamıyor)" kısaltması.
        // "N.U." (Numuneye Uygulanamaz) gazlı söndürme raporlarında görülen
        // ayrı bir varyant - aynı bucket.
        if (in_array($compact, ['n', 'na', 'nu', 'uy', 'uygulanamaz', 'uygulanamıyor', 'uygulanamiyor'], true)) {
            return 'uygulanamiyor';
        }
        // A longer status sentence ("... kullanımı uygun değildir.") OR the
        // bare word on its own ("UYGUN DEĞİL", no "-dir" suffix - e.g. a
        // table cell's terse DEĞERLENDİRME value rather than a narrative
        // sentence) - plain substring search (no regex), covering both the
        // normal spelling and the plain-g fallback some sources use.
        // "değil" alone (not "değildir") used to be missed here (the old
        // regex required a literal "ld" tail, which bare "değil" never has),
        // silently falling through to the bare "uygun" check below and
        // reporting the OPPOSITE compliance result.
        if (str_contains($value, 'değil') || str_contains($value, 'degil')) return 'uygun_degil';
        if (str_contains($value, 'uygun')) return 'uygun';

        // Bu noktaya gelen değer, yukarıdaki hiçbir bilinen "uygun" ifadesine
        // uymuyor ama yine de Gemini'nin BU RAPOR İÇİN keşfettiği
        // result_patterns'e uyduğu için buraya kadar geldi (bkz. matchResultValue) -
        // yani gerçek bir sonuç kodu, sadece Türkçe "uygun/uygun değil" kelime
        // kalıplarımızın dışında bir kısaltma (raporlar arasında bu kısaltmalar
        // HİÇ sabit değil - "yav her raporda pattern değişir"). Böyle bir
        // durumda değeri ham haliyle geçirip frontend'de HİÇBİR durum
        // butonunun seçili görünmemesine (sessizce kaybolmasına) izin vermek
        // yerine, güvenlik önceliğiyle "uygun_degil" (incelemesi gereken)
        // sayıyoruz - net biçimde "uygun" olduğu tespit edilemeyen hiçbir
        // madde sessizce atlanmaz.
        return 'uygun_degil';
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
