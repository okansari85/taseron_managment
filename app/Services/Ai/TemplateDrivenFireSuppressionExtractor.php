<?php

namespace App\Services\Ai;

use RuntimeException;

class TemplateDrivenFireSuppressionExtractor
{
    public function __construct(private CamelotPdfTableExtractor $camelot) {}

    public function extract(string $pdfPath, array $semantic): array
    {
        $camelot = $this->camelot->extract($pdfPath);
        $allTables = array_values(array_filter((array) ($camelot['tables'] ?? []), fn ($table) => is_array($table) && !empty($table['data'])));
        $latticeTables = array_values(array_filter($allTables, fn ($table) => ($table['flavor'] ?? '') === 'lattice'));
        if (!$latticeTables) throw new RuntimeException('Camelot template extraction için kullanılabilir lattice tablo bulamadı.');

        $template = is_array($semantic['template'] ?? null) ? $semantic['template'] : [];
        $systems = (array) ($template['fire_systems']['systems'] ?? []);
        $extractedSystems = [];

        // Gemini-direct equipment (extracted_data.equipment) - real values it
        // read itself for a SMALL, boundedly-countable group (e.g. 2-4 pumps
        // with an idiosyncratic "proje değeri / uygulama değeri" split column
        // no fixed structural rule generalizes well). Grouped by system_name
        // so a system with a direct-read group skips table_shape/legacy
        // entirely for it - trust the AI's own reading over a structural
        // guess when the AI already did the reading.
        $directEquipmentBySystem = [];
        foreach ((array) ($semantic['extracted_data']['equipment'] ?? []) as $directItem) {
            if (!is_array($directItem)) continue;
            $directSystemName = $this->string($directItem['system_name'] ?? null);
            if ($directSystemName === null) continue;
            $properties = [];
            foreach ((array) ($directItem['properties'] ?? []) as $propertyEntry) {
                if (!is_array($propertyEntry)) continue;
                $propertyKey = $this->string($propertyEntry['key'] ?? null);
                $propertyValue = $this->string($propertyEntry['value'] ?? null);
                if ($propertyKey === null || $propertyValue === null) continue;
                $properties[$propertyKey] = $this->cleanValue($propertyValue);
            }
            $directEquipmentBySystem[$directSystemName][] = [
                'code' => $this->string($directItem['code'] ?? null),
                'name' => $this->string($directItem['equipment_name'] ?? null),
                'system_name' => $directSystemName,
                'properties' => $properties,
                'result' => $this->string($directItem['result'] ?? null),
                'note' => null,
                'source_pages' => array_values(array_unique(array_filter(array_map('intval', (array) ($directItem['source_pages'] ?? []))))),
            ];
        }

        foreach ($systems as $system) {
            if (!is_array($system)) continue;
            $systemName = $this->string($system['system_name'] ?? null);
            if ($systemName === null) continue;

            $controlTemplates = (array) ($system['control_items'] ?? []);
            $equipment = [];

            if (isset($directEquipmentBySystem[$systemName])) {
                $equipment = $directEquipmentBySystem[$systemName];
            } else {
                // table_shape is the sole, authoritative structure
                // declaration (role: identity/property/result/note) - one
                // generic reader for any rows/none-axis layout. A bounded,
                // idiosyncratically-laid-out equipment group (e.g. a pump
                // room) is expected to arrive via extracted_data.equipment
                // (the direct-read branch above) instead of here. There is
                // deliberately no further fallback: an equipment group that
                // is neither table_shape rows/none NOR direct-read simply
                // yields no equipment for now, rather than guessing via
                // heuristics that have repeatedly mismatched across reports.
                foreach ((array) ($system['equipment'] ?? []) as $equipmentTemplate) {
                    if (!is_array($equipmentTemplate)) continue;
                    foreach ($this->extractEquipmentFromTableShape($latticeTables, $equipmentTemplate) as $item) {
                        $equipment[] = $item;
                    }
                }
            }

            $resultAxis = (array) ($system['control_matrix']['axis_detection']['result_axis'] ?? []);

            $controlItems = $this->extractControls($latticeTables, $controlTemplates, $resultAxis);

            // extractRowBasedEquipment() carries each row's OWN result (a
            // per-equipment aggregate judgment, e.g. one dolap = one U/U.D./
            // N.U. mark) rather than a separate per-criterion control_items
            // section - synthesize one equipment-scoped control_item per such
            // result so it flows through the SAME compliance pipeline as
            // every other equipment (buildEquipmentEntry() matches on
            // scope=equipment + equipment=code) instead of a parallel path.
            //
            // A source report can (data-entry mistake) reuse the same
            // identity code for two different pieces of equipment (e.g. the
            // same "Tüp No" printed on two different extinguisher rows) -
            // a plain 'EQP-<code>' control code would then collide and
            // FireSuppressionUnifiedNormalizer::normalizeControls() dedupes
            // by code, silently DROPPING the second occurrence entirely
            // (undercounting control_items). Suffix only on an actual repeat
            // so the common, non-duplicated case keeps the clean 'EQP-<code>'.
            $codeOccurrences = [];
            foreach ($equipment as $item) {
                $code = $item['code'] ?? null;
                $result = $item['result'] ?? null;
                if ($code === null || $result === null) continue;

                $occurrence = $codeOccurrences[$code] ?? 0;
                $codeOccurrences[$code] = $occurrence + 1;
                $controlCode = 'EQP-' . $code . ($occurrence > 0 ? '#' . ($occurrence + 1) : '');

                $controlItems[] = [
                    'code' => $controlCode,
                    'criterion' => $item['note'] ?? null,
                    'scope' => 'equipment',
                    'equipment' => $code,
                    'result' => $result,
                    'source_pages' => $item['source_pages'] ?? [],
                ];
            }

            $extractedSystems[] = [
                'system_name' => $systemName,
                'equipment' => $equipment,
                'control_items' => $controlItems,
            ];
        }

        // report_information / facility_information: Gemini reads these small,
        // fixed-size sections directly and reports the REAL value (see
        // GeminiTemplateDiscoveryClient's extracted_data.report_information /
        // .facility_information) - no Camelot grid/label-adjacency guessing
        // needed, unlike equipment/control_items which can grow unbounded with
        // report length. Older fixtures captured before this existed won't
        // have it, so fall back to the legacy Camelot-pattern extraction only
        // when Gemini's real-value list is empty.
        $reportInformation = $this->extractedFieldValues($semantic['extracted_data']['report_information'] ?? []);
        if (!$reportInformation) {
            $reportInformation = $this->extractReportInformation($allTables, (array) ($template['report_information']['fields'] ?? []));
        }
        $facilityInformation = $this->extractedFieldValues($semantic['extracted_data']['facility_information'] ?? []);
        if (!$facilityInformation) {
            $facilityInformation = $this->extractFacilityInformation($allTables, (array) ($template['facility_or_project_information'] ?? []));
        }

        // Same reasoning as report_information above: Gemini reads the final
        // verdict paragraph directly (extracted_data.overall_result), which
        // sidesteps both the Camelot table-boundary heuristics AND the raw-text
        // regex fallback (FireSuppressionOverallResultFallback) - those stay in
        // place below/in the normalizer only for older fixtures that don't have
        // this field. Only trust it when it actually carries a status; a
        // text-only/empty value isn't useful and should still fall through.
        $overallResult = (array) ($semantic['extracted_data']['overall_result'] ?? []);
        $overallStatus = $this->string($overallResult['status'] ?? null);
        if ($overallStatus === null) {
            $overallResult = $this->extractOverallResult($allTables, (array) ($template['overall_result'] ?? []));
        } else {
            $overallResult = array_filter([
                'text' => $this->string($overallResult['text'] ?? null),
                'status' => $overallStatus,
            ], fn ($value) => $value !== null);
        }

        $result = [
            'extracted_data' => [
                'report_information' => $reportInformation,
                'facility_or_project_information' => $facilityInformation,
                'fire_systems' => $extractedSystems,
                'overall_result' => $overallResult,
                'findings' => (array) ($semantic['extracted_data']['findings'] ?? []),
            ],
        ];

        $result = $this->sanitizeUtf8($result);
        $invalidPath = $this->findInvalidUtf8Path($result);
        if ($invalidPath !== null) throw new RuntimeException('Geçersiz UTF-8 çıktı alanı: ' . $invalidPath);
        return $result;
    }

    // Reads equipment straight from Gemini's declared table_shape (role:
    // identity/property/result/note) instead of guessing between several
    // shape-specific heuristics below. Gemini describes the STRUCTURE only
    // (fixed-size output regardless of row count); Camelot already supplied
    // the actual cell text; this method is the "place these cells into the
    // structure AI described" step - one generic reader for any layout.
    //
    // "separate_blocks" (each instance is its own small table) has no
    // proven real-world case yet and isn't handled - returns [] for now.
    private function extractEquipmentFromTableShape(array $tables, array $template): array
    {
        $shape = (array) ($template['table_shape'] ?? []);
        $axis = mb_strtolower(trim((string) ($shape['instance_axis'] ?? '')), 'UTF-8');
        $roleColumns = (array) ($shape['columns'] ?? []);
        if ($axis === '' || !$roleColumns) return [];

        if ($axis === 'none') return $this->extractShapeSingle($tables, $roleColumns, $template);
        if ($axis === 'columns') return $this->extractEquipmentFromColumns($tables, $shape, $roleColumns, $template);
        if ($axis !== 'rows') return [];

        $headerPatterns = $this->patterns($shape['header_row_patterns'] ?? []);
        if (!$headerPatterns) return [];

        $items = [];
        foreach ($tables as $table) {
            $grid = $this->matrix($table);
            if (!$grid) continue;

            foreach ($grid as $headerRowIndex => $row) {
                if (!$this->matchesAny($this->rowText($row), $headerPatterns)) continue;

                // Locate each declared column by its POSITION (column_index)
                // first - exact, unambiguous, immune to the text-overlap trap
                // where a short header like "U." is literally a substring of
                // "U.D." and "N.U.". A roleDef missing column_index (older
                // capture, before this field existed) falls back to
                // resolveColumnRole()'s collapsed-exact-first matching below.
                $columnRoles = [];
                $unresolvedRoleDefs = [];
                foreach ($roleColumns as $roleDef) {
                    if (!is_array($roleDef)) continue;
                    $columnIndex = $roleDef['column_index'] ?? null;
                    if (is_int($columnIndex) && $columnIndex >= 0 && array_key_exists($columnIndex, $row)) {
                        $columnRoles[$columnIndex] = $roleDef;
                    } else {
                        $unresolvedRoleDefs[] = $roleDef;
                    }
                }
                if ($unresolvedRoleDefs) {
                    foreach ($row as $column => $cellValue) {
                        if (array_key_exists($column, $columnRoles)) continue;
                        $winner = $this->resolveColumnRole((string) $cellValue, $unresolvedRoleDefs);
                        if ($winner === null) continue;
                        $columnRoles[(int) $column] = $winner;
                        $unresolvedRoleDefs = array_values(array_udiff(
                            $unresolvedRoleDefs,
                            [$winner],
                            fn ($a, $b) => $a === $b ? 0 : 1
                        ));
                    }
                }

                $identityColumn = null;
                $propertyColumns = [];
                $resultColumns = [];
                $noteColumn = null;
                foreach ($columnRoles as $column => $roleDef) {
                    $role = (string) ($roleDef['role'] ?? '');
                    if ($role === 'identity' && $identityColumn === null) { $identityColumn = (int) $column; continue; }
                    if ($role === 'property') {
                        $propertyColumns[(int) $column] = [
                            'key' => $this->string($roleDef['key'] ?? null) ?? $this->cleanValue((string) ($row[$column] ?? '')),
                            'sub_keys' => $this->patterns($roleDef['sub_keys'] ?? []),
                        ];
                        continue;
                    }
                    // value===null here is a DELIBERATE dynamic marker (a
                    // single "Durum"-style column whose cell text varies per
                    // row, e.g. "U"/"UD"/"N"), not "unset" - the data-row loop
                    // below reads the raw cell text instead of a fixed value.
                    if ($role === 'result') { $resultColumns[(int) $column] = $this->string($roleDef['value'] ?? null); continue; }
                    if ($role === 'note' && $noteColumn === null) { $noteColumn = (int) $column; continue; }
                }
                if ($identityColumn === null || (!$propertyColumns && !$resultColumns)) continue;

                $found = false;
                for ($r = $headerRowIndex + 1; $r < count($grid); $r++) {
                    $dataRow = $grid[$r];
                    $identityValue = trim((string) ($dataRow[$identityColumn] ?? ''));
                    if ($identityValue === '' || $identityValue === '-') continue;
                    if ($this->matchesAny($identityValue, $headerPatterns)) break;

                    $properties = [];
                    foreach ($propertyColumns as $column => $propertyDef) {
                        $value = trim((string) ($dataRow[$column] ?? ''));
                        if ($value === '' || $value === '-') continue;
                        $this->assignPropertyValue($properties, $propertyDef['key'], $propertyDef['sub_keys'], $value);
                    }

                    $result = null;
                    foreach ($resultColumns as $column => $value) {
                        $cellText = trim((string) ($dataRow[$column] ?? ''));
                        if ($cellText === '') continue;
                        // dynamic (value===null): pass the raw per-row code
                        // through as-is (e.g. "UD") - FireSuppressionUnified
                        // Normalizer::normalizeResult() downstream already
                        // recognizes these short U/UD/N-style codes.
                        $result = $value !== null ? $value : $this->cleanValue($cellText);
                        break;
                    }

                    $note = null;
                    if ($noteColumn !== null) {
                        $noteValue = trim((string) ($dataRow[$noteColumn] ?? ''));
                        if ($noteValue !== '' && $noteValue !== '-') $note = $this->cleanValue($noteValue);
                    }

                    if (!$properties && $result === null && $note === null) continue;

                    $items[] = [
                        'code' => $this->cleanValue($identityValue),
                        'name' => $this->string($template['equipment_name'] ?? null),
                        'system_name' => $this->string($template['system_name'] ?? null),
                        'properties' => $properties,
                        'result' => $result,
                        'note' => $note,
                        'source_pages' => array_values(array_unique(array_filter([(int) ($table['page'] ?? 0)]))),
                    ];
                    $found = true;
                }
                if ($found) break;
            }
        }

        return $items;
    }

    // instance_axis=none: a single piece of equipment, declared as
    // property/note roles only (no identity, no repetition).
    private function extractShapeSingle(array $tables, array $roleColumns, array $template): array
    {
        $propertyPatterns = [];
        $notePatterns = [];
        foreach ($roleColumns as $roleDef) {
            if (!is_array($roleDef)) continue;
            $patterns = $this->patterns($roleDef['header_patterns'] ?? []);
            if (!$patterns) continue;
            if (($roleDef['role'] ?? '') === 'property') $propertyPatterns[] = ['patterns' => $patterns, 'key' => $this->string($roleDef['key'] ?? null), 'sub_keys' => $this->patterns($roleDef['sub_keys'] ?? [])];
            elseif (($roleDef['role'] ?? '') === 'note') $notePatterns = array_merge($notePatterns, $patterns);
        }
        if (!$propertyPatterns) return [];

        $properties = [];
        $note = null;
        $sourcePages = [];
        foreach ($tables as $table) {
            foreach ($this->matrix($table) as $row) {
                if (!$row) continue;
                foreach ($row as $labelColumn => $cellValue) {
                    foreach ($propertyPatterns as $propertyDef) {
                        if (!$this->matchesAny((string) $cellValue, $propertyDef['patterns'])) continue;
                        $value = $this->nextNonEmpty($row, (int) $labelColumn + 1);
                        if ($value === null) break;
                        $key = $propertyDef['key'] ?? $this->cleanValue((string) $cellValue);
                        $this->assignPropertyValue($properties, $key, $propertyDef['sub_keys'], $value);
                        $sourcePages[] = (int) ($table['page'] ?? 0);
                        break;
                    }
                    if ($notePatterns && $this->matchesAny((string) $cellValue, $notePatterns)) {
                        $value = $this->nextNonEmpty($row, (int) $labelColumn + 1);
                        if ($value !== null) $note = $this->cleanValue($value);
                    }
                }
            }
        }
        if (!$properties) return [];

        return [[
            'code' => null,
            'name' => $this->string($template['equipment_name'] ?? null),
            'system_name' => $this->string($template['system_name'] ?? null),
            'properties' => $properties,
            'result' => null,
            'note' => $note,
            'source_pages' => array_values(array_unique(array_filter($sourcePages))),
        ]];
    }

    // instance_axis=columns: equipment instances sit side by side across
    // COLUMNS of a shared table (e.g. "No / Kod | | YD1 | YD2 | ... |
    // YD10"), with each subsequent ROW being a property/result/note that
    // applies to every instance at once (e.g. a "Kat" row, a "Marka" row).
    // The axes are the transpose of instance_axis=rows: here $roleColumns
    // entries are located by ROW (their own label cell matched via
    // resolveColumnRole(), no column_index needed - row labels like "Kat"/
    // "Marka" aren't short overlapping codes the way "U."/"U.D."/"N.U."
    // column headers are), and each instance's value is read from ITS OWN
    // column, found once from the header row.
    private function extractEquipmentFromColumns(array $tables, array $shape, array $roleColumns, array $template): array
    {
        $headerPatterns = $this->patterns($shape['header_row_patterns'] ?? []);
        if (!$headerPatterns) return [];

        // The header row can repeat multiple times (e.g. a new 5-or-10-wide
        // block of instances starting fresh on each page) - accumulate every
        // block found instead of stopping at the first, or later blocks
        // silently go missing.
        $allItems = [];
        foreach ($tables as $table) {
            $grid = $this->matrix($table);
            if (!$grid) continue;

            foreach ($grid as $headerRowIndex => $row) {
                if (!$this->matchesAny($this->rowText($row), $headerPatterns)) continue;

                // The first cell matching header_row_patterns is this row's
                // OWN label (e.g. "No / Kod"); every OTHER non-empty cell in
                // the SAME row is one equipment instance's identity, at that
                // column position.
                $labelColumn = null;
                $instanceColumns = [];
                foreach ($row as $column => $cellValue) {
                    $cellValue = trim((string) $cellValue);
                    if ($cellValue === '') continue;
                    if ($labelColumn === null && $this->matchesAny($cellValue, $headerPatterns)) { $labelColumn = (int) $column; continue; }
                    $instanceColumns[(int) $column] = $cellValue;
                }
                if ($labelColumn === null || !$instanceColumns) continue;

                $items = [];
                foreach ($instanceColumns as $column => $identity) {
                    $items[$column] = [
                        'code' => $this->cleanValue($identity),
                        'name' => $this->string($template['equipment_name'] ?? null),
                        'system_name' => $this->string($template['system_name'] ?? null),
                        'properties' => [],
                        'result' => null,
                        'note' => null,
                        'source_pages' => [(int) ($table['page'] ?? 0)],
                    ];
                }

                for ($r = $headerRowIndex + 1; $r < count($grid); $r++) {
                    $dataRow = $grid[$r];
                    $rowLabel = null;
                    foreach ($dataRow as $cellValue) {
                        $cellValue = trim((string) $cellValue);
                        if ($cellValue !== '') { $rowLabel = $cellValue; break; }
                    }
                    if ($rowLabel === null) continue;
                    // A later occurrence of the header row (a new block, e.g.
                    // the next page's set of instances) ends this block.
                    if ($this->matchesAny($rowLabel, $headerPatterns)) break;

                    $roleDef = $this->resolveColumnRole($rowLabel, $roleColumns);
                    if ($roleDef === null) continue;
                    $role = (string) ($roleDef['role'] ?? '');
                    if (!in_array($role, ['property', 'result', 'note'], true)) continue;

                    foreach ($instanceColumns as $column => $identity) {
                        $value = trim((string) ($dataRow[$column] ?? ''));
                        if ($value === '' || $value === '-') continue;
                        if ($role === 'property') {
                            $key = $this->string($roleDef['key'] ?? null) ?? $rowLabel;
                            $this->assignPropertyValue($items[$column]['properties'], $key, $this->patterns($roleDef['sub_keys'] ?? []), $value);
                        } elseif ($role === 'result' && $items[$column]['result'] === null) {
                            $fixedValue = $this->string($roleDef['value'] ?? null);
                            $items[$column]['result'] = $fixedValue ?? $this->cleanValue($value);
                        } elseif ($role === 'note' && $items[$column]['note'] === null) {
                            $items[$column]['note'] = $this->cleanValue($value);
                        }
                    }
                }

                foreach ($items as $item) {
                    if ($item['properties'] || $item['result'] !== null || $item['note'] !== null) {
                        $allItems[] = $item;
                    }
                }
            }
        }

        return $allItems;
    }

private function extractControls(array $tables, array $controlTemplates, array $resultAxis = []): array
{
    $out = [];

    // Some reports mark the result by WHICH of several fixed columns (e.g. "U" /
    // "U.D" / "N.U.") holds a mark, using the SAME generic glyph (e.g. "✓") in
    // every column - the glyph itself carries no information about which result
    // it means, only its column position does. Gemini's result_axis tells us
    // this is the case (location=columns, patterns=the distinguishing column
    // labels in left-to-right order) - when so, precompute each table's result
    // column groups ONCE so the per-code scan below can resolve the real
    // column-specific label instead of accepting the ambiguous glyph as-is.
    $columnLabels = array_values(array_filter(array_map('strval', (array) ($resultAxis['patterns'] ?? []))));
    $resultColumnsByTable = [];
    if (($resultAxis['location'] ?? null) === 'columns' && count($columnLabels) >= 2) {
        foreach ($tables as $tableIndex => $table) {
            $resultColumnsByTable[$tableIndex] = $this->resultColumnBlocks($this->matrix($table), $columnLabels);
        }
    }

    foreach ($controlTemplates as $control) {
        if (!is_array($control)) continue;

        $codePatterns = array_values(array_filter(
            array_map('strval', (array) ($control['control_code_patterns'] ?? []))
        ));

        $resultPatterns = array_values(array_filter(
            array_map('strval', (array) ($control['result_patterns'] ?? []))
        ));

        if (!$codePatterns) continue;

        foreach ($tables as $tableIndex => $table) {

            // EKLENDİ
            $cells = (array) ($table['cells'] ?? []);
            $resultColumns = $resultColumnsByTable[$tableIndex] ?? [];

            // rowIndex EKLENDİ
            foreach ($this->matrix($table) as $rowIndex => $row) {

                // columnIndex EKLENDİ
                foreach ($row as $columnIndex => $value) {

                    $code = $this->matchControlCode(
                        (string) $value,
                        $codePatterns
                    );

                    if ($code === null) continue;

                    // Column-position result takes priority over the plain
                    // rightward text scan below - it disambiguates a shared
                    // glyph, while the scan below would just grab whichever
                    // column happens to come first.
                    $result = $resultColumns ? $this->resultFromColumnBlock($row, (int) $columnIndex, $resultColumns) : null;

                    // Scan forward from this code's own column only - a row can hold
                    // more than one code/criterion/result group side by side (e.g.
                    // "5.19 | Borular | U | 5.37 ... | | N"), and scanning the whole
                    // row from index 0 would grab a NEIGHBORING code's result instead
                    // of this one's. Stop as soon as another cell looks like a control
                    // code itself - that means we've crossed into the next group
                    // without finding this code's own result.
                    for ($resultIndex = (int) $columnIndex + 1; $result === null && $resultIndex < count($row); $resultIndex++) {
                        $candidate = (string) ($row[$resultIndex] ?? '');

                        if ($this->matchControlCode($candidate, $codePatterns) !== null) {
                            break;
                        }

                        $matched = $this->matchResultValue($candidate, $resultPatterns);

                        if ($matched !== null) {
                            $result = $matched;
                            break;
                        }
                    }

                    /*
                     * Kriteri Gemini'den değil,
                     * Camelot'un PDF hücrelerinden bul.
                     */
                    $criterion = $this->findCriterionFromCells(
                        $cells,
                        (int) $rowIndex,
                        (int) $columnIndex,
                        $code,
                        $codePatterns,
                        $resultPatterns
                    );

                    $out[$this->normalizeCode($code)] = [
                        'code' => $code,
                        'criterion' => $criterion,
                        'result' => $result,
                        'source_pages' => array_values(
                            array_unique(
                                array_filter([
                                    (int) ($table['page'] ?? 0)
                                ])
                            )
                        ),
                    ];
                }
            }
        }
    }

    return array_values($out);
}

// Finds every occurrence of a "result columns" header in the grid (a cell
// whose text is exactly the FIRST column label, e.g. "U") and, from each,
// claims the next count($columnLabels) columns as that block's result
// columns in the declared left-to-right order. A report can lay out several
// such blocks side by side on the same rows (e.g. two checklist halves) -
// each becomes its own independent block rather than one shared column set.
private function resultColumnBlocks(array $grid, array $columnLabels): array
{
    $blocks = [];
    $seenStarts = [];
    foreach ($grid as $row) {
        foreach ($row as $column => $value) {
            if ($this->normalizeLabel((string) $value) !== $this->normalizeLabel($columnLabels[0])) continue;
            if (isset($seenStarts[$column])) continue;
            $seenStarts[$column] = true;
            $columns = [];
            foreach ($columnLabels as $offset => $label) $columns[(int) $column + $offset] = $label;
            $blocks[] = ['start' => (int) $column, 'columns' => $columns];
        }
    }
    usort($blocks, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
    return $blocks;
}

// Resolves a control code's actual result by picking the block whose result
// columns sit immediately to the right of the code (the block that row
// segment belongs to, when several blocks share the same rows) and
// returning whichever of ITS columns actually holds a mark.
private function resultFromColumnBlock(array $row, int $codeColumn, array $blocks): ?string
{
    $chosen = null;
    foreach ($blocks as $block) {
        if ($block['start'] <= $codeColumn) continue;
        if ($chosen === null || $block['start'] < $chosen['start']) $chosen = $block;
    }
    if ($chosen === null) return null;

    foreach ($chosen['columns'] as $column => $label) {
        if (trim((string) ($row[$column] ?? '')) !== '') return $label;
    }
    return null;
}

private function findCriterionFromCells(
    array $cells,
    int $controlRow,
    int $controlColumn,
    string $code,
    array $codePatterns,
    array $resultPatterns
): ?string {
    $controlCell = $cells[$controlRow][$controlColumn] ?? null;

    if (!is_array($controlCell)) {
        return null;
    }

    $controlText = trim((string) ($controlCell['text'] ?? ''));

    if ($controlText === '') {
        return null;
    }

    $controlX1 = (float) ($controlCell['x1'] ?? 0);
    $controlX2 = (float) ($controlCell['x2'] ?? 0);
    $controlY1 = (float) ($controlCell['y1'] ?? 0);
    $controlY2 = (float) ($controlCell['y2'] ?? 0);

    /*
     * Önce kontrol hücresinin içinde criterion var mı?
     *
     * Örnek:
     * 5.1 Proje varlığı ve onayı
     */
    $sameCellCriterion = trim(
        preg_replace(
            '/^' . preg_quote($code, '/') . '\s*[:.)-]?\s*/iu',
            '',
            $controlText
        ) ?? $controlText
    );

    if (
        $sameCellCriterion !== ''
        && $sameCellCriterion !== $controlText
        && $this->normalizeCode($sameCellCriterion) !== $this->normalizeCode($code)
    ) {
        return $sameCellCriterion;
    }

    /*
     * ============================================================
     * KOORDİNAT TABANLI KRİTER ARAMA
     * ============================================================
     */

    $horizontalCandidates = [];
    $verticalCandidates = [];

    foreach ($cells as $rowIndex => $rowCells) {
        foreach ($rowCells as $columnIndex => $cell) {

            if (!is_array($cell)) {
                continue;
            }

            $text = trim((string) ($cell['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            /*
             * Kontrol hücresinin kendisi
             */
            if (
                (int) $rowIndex === $controlRow
                && (int) $columnIndex === $controlColumn
            ) {
                continue;
            }

            /*
             * Başka bir kontrol kodunu criterion olarak alma.
             */
            $isControlCode = false;

            foreach ($codePatterns as $pattern) {
                if (
                    $this->matchControlCode(
                        $text,
                        [$pattern]
                    ) !== null
                ) {
                    $isControlCode = true;
                    break;
                }
            }

            if ($isControlCode) {
                continue;
            }

            /*
             * Sonuç hücresini criterion olarak alma.
             */
            if (
                $this->matchResultValue(
                    $text,
                    $resultPatterns
                ) !== null
            ) {
                continue;
            }

            $x1 = (float) ($cell['x1'] ?? 0);
            $x2 = (float) ($cell['x2'] ?? 0);
            $y1 = (float) ($cell['y1'] ?? 0);
            $y2 = (float) ($cell['y2'] ?? 0);

            /*
             * ====================================================
             * 1. YATAY KOMŞULUK
             *
             * [5.1] [Proje varlığı ve onayı] [UD]
             *
             * Kontrolün sağındaki hücreleri değerlendir.
             * ====================================================
             */

            $verticalOverlap = min($controlY2, $y2)
                - max($controlY1, $y1);

            if (
                $verticalOverlap > 0
                && $x1 >= $controlX2
            ) {
                $distance = $x1 - $controlX2;

                $horizontalCandidates[] = [
                    'text' => $text,
                    'distance' => $distance,
                    'overlap' => $verticalOverlap,
                ];

                continue;
            }

            /*
             * ====================================================
             * 2. DİKEY KOMŞULUK
             *
             * [5.1]
             * [Proje varlığı ve onayı]
             *
             * X ekseninde hizalıysa değerlendir.
             * ====================================================
             */

            $horizontalOverlap = min($controlX2, $x2)
                - max($controlX1, $x1);

            if ($horizontalOverlap <= 0) {
                continue;
            }

            /*
             * Kontrolün hemen altında
             */
            if ($y1 >= $controlY2) {

                $verticalCandidates[] = [
                    'text' => $text,
                    'distance' => $y1 - $controlY2,
                    'overlap' => $horizontalOverlap,
                ];

                continue;
            }

            /*
             * Kontrolün hemen üstünde
             */
            if ($y2 <= $controlY1) {

                $verticalCandidates[] = [
                    'text' => $text,
                    'distance' => $controlY1 - $y2,
                    'overlap' => $horizontalOverlap,
                ];
            }
        }
    }

    /*
     * ============================================================
     * YATAY ADAYLAR
     *
     * En yakın hücreyi seç.
     * ============================================================
     */

    if ($horizontalCandidates !== []) {

        usort(
            $horizontalCandidates,
            function (array $a, array $b): int {

                /*
                 * Önce yakınlık.
                 * Eşitse daha fazla dikey örtüşme.
                 */
                if ($a['distance'] == $b['distance']) {
                    return $b['overlap'] <=> $a['overlap'];
                }

                return $a['distance'] <=> $b['distance'];
            }
        );

        return trim(
            $horizontalCandidates[0]['text']
        );
    }

    /*
     * ============================================================
     * DİKEY ADAYLAR
     * ============================================================
     */

    if ($verticalCandidates !== []) {

        usort(
            $verticalCandidates,
            function (array $a, array $b): int {

                if ($a['distance'] == $b['distance']) {
                    return $b['overlap'] <=> $a['overlap'];
                }

                return $a['distance'] <=> $b['distance'];
            }
        );

        return trim(
            $verticalCandidates[0]['text']
        );
    }

    return null;
}












/*
    private function extractControls(array $tables, array $controlTemplates): array
    {
        $out = [];
        foreach ($controlTemplates as $control) {
            if (!is_array($control)) continue;
            $codePatterns = array_values(array_filter(array_map('strval', (array) ($control['control_code_patterns'] ?? []))));
            $resultPatterns = array_values(array_filter(array_map('strval', (array) ($control['result_patterns'] ?? []))));
            if (!$codePatterns) continue;
            foreach ($tables as $table) {
                foreach ($this->matrix($table) as $row) {
                    foreach ($row as $index => $value) {
                        $code = $this->matchControlCode((string) $value, $codePatterns);
                        if ($code === null) continue;
                        $result = null;
                        foreach ($row as $resultIndex => $candidate) {
                            if ((int) $resultIndex === (int) $index) continue;
                            $matched = $this->matchResultValue((string) $candidate, $resultPatterns);
                            if ($matched !== null) { $result = $matched; break; }
                        }


                                /*
                            * Kriteri Gemini'den değil,
                            * Camelot'un PDF hücrelerinden bul.

                           $criterion = $this->findCriterionFromCells(
                            $cells,
                            (int) $rowIndex,
                            (int) $columnIndex,
                            $code,
                            $codePatterns,
                            $resultPatterns
                            );

                            $out[$this->normalizeCode($code)] = [
                            'code' => $code,
                            'criterion' => $criterion,
                            'result' => $result,
                            'source_pages' => array_values(
                                array_unique(
                                    array_filter([
                                        (int) ($table['page'] ?? 0)
                                    ])
                                )
                            ),
                            ];





                        /*
                        $out[$this->normalizeCode($code)] = [
                            'code' => $code,
                            'result' => $result,
                            'source_pages' => array_values(array_unique(array_filter([(int) ($table['page'] ?? 0)]))),
                        ];

                    }
                }
            }
        }
        return array_values($out);
    }
  */


     private function matchControlCode(string $value, array $patterns): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $normalizedValue = $this->normalizeCode($value);

        $extractPrefix = static function (string $text): ?string {
            if (preg_match('/^\s*([A-Za-zÇĞİÖŞÜ]{0,8}[ .\-]?\d+(?:[.\-]\d+)*)\b/u', $text, $matches) === 1) {
                return trim($matches[1]);
            }
            return null;
        };
        $valueCode = $extractPrefix($value);

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') continue;

            $normalizedPattern = $this->normalizeCode($pattern);

            if ($normalizedPattern === $normalizedValue) return $value;

            $isRegexPattern = str_contains($pattern, '^') || str_contains($pattern, '$') || str_contains($pattern, '\\') || str_contains($pattern, '[');
            if (!$isRegexPattern) continue;

            // Gemini's pattern may already be self-anchored (e.g. "^A\.[1-9]$").
            // Strip its own ^/$ before wrapping it in ours below, otherwise the
            // pattern's own "$" blocks matching anything after it - including our
            // own optional trailing punctuation - even when the code itself matches.
            $core = preg_replace('/^\^|\$$/u', '', $pattern) ?? $pattern;

            // Code alone in its own cell (with optional trailing punctuation).
            if (@preg_match('~^(?:' . $core . ')[.:\)]?$~iu', $value) === 1) return rtrim($value, '.:)');

            // Code + criterion combined in one cell (e.g. "5.12 Dizel pompa") - the
            // whole-cell check above fails since there is trailing criterion text
            // after the code. Validate just the extracted leading code against the
            // same declared pattern.
            if ($valueCode !== null && $valueCode !== $value && @preg_match('~^(?:' . $core . ')$~iu', $valueCode) === 1) return $valueCode;
        }

        return null;
    }

    private function matchResultValue(string $value, array $patterns): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $normalizedValue = $this->normalizeLabel($value);
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') continue;
            if ($normalizedValue === $this->normalizeLabel($pattern)) return $value;
            if ((str_contains($pattern, '^') || str_contains($pattern, '$') || str_contains($pattern, '\\') || str_contains($pattern, '[')) && @preg_match($pattern, $value) === 1) return $value;
            if ((str_contains($pattern, '^') || str_contains($pattern, '$') || str_contains($pattern, '\\') || str_contains($pattern, '[')) && @preg_match('~' . $pattern . '~iu', $value) === 1) return $value;
        }
        return null;
    }

    private function rowContainsControlCode(array $row, array $patterns): bool
    {
        foreach ($row as $value) {
            if ($this->matchControlCode((string) $value, $patterns) !== null) return true;
        }
        return false;
    }

    private function extractReportInformation(array $tables, array $fieldTemplates): array
    {
        // Report header info (Rapor No, Ünvanı, ...) lives in a 'stream' table for
        // some reports but in a ruled 'lattice' table for others (report-specific
        // layout, not a rule) - scan both rather than assuming one flavor, or the
        // whole block silently comes back empty for reports that use the other one.
        $result = [];
        foreach ($tables as $table) {
            $rows = $this->matrix($table);
            foreach ($rows as $rowIndex => $row) {
                $this->applyColumnAlignedFields($result, $rows, $rowIndex, $fieldTemplates);
                foreach ($fieldTemplates as $field) {
                    if (!is_array($field)) continue;
                    $key = $this->string($field['key'] ?? null);
                    $patterns = array_values(array_filter(array_map('strval', (array) ($field['label_patterns'] ?? []))));
                    if ($key === null || !$patterns || array_key_exists($key, $result)) continue;
                    $labelColumn = $this->findPatternColumn($row, $patterns, true);
                    if ($labelColumn === null) continue;
                    $value = $this->nextNonEmpty($row, $labelColumn + 1);
                    if ($value === null) $value = $this->valueFromSameCell((string) ($row[$labelColumn] ?? ''), $patterns);
                    if ($value !== null) $result[$key] = $this->cleanValue($value);
                }
            }
        }
        return $result;
    }

    private function extractFacilityInformation(array $tables, array $template): array
    {
        $result = [];
        $sectionPatterns = array_values(array_filter(array_map('strval', (array) ($template['section_heading_patterns'] ?? []))));
        $fields = (array) ($template['fields'] ?? []);
        $sectionFound = !$sectionPatterns;
        // Same reasoning as extractReportInformation() - don't assume one table
        // flavor holds this section.
        foreach ($tables as $table) {
            $rows = $this->matrix($table);
            foreach ($rows as $rowIndex => $row) {
                $text = $this->rowText($row);
                if ($sectionPatterns && $this->matchesAny($text, $sectionPatterns)) { $sectionFound = true; continue; }
                if (!$sectionFound) continue;
                $this->applyColumnAlignedFields($result, $rows, $rowIndex, $fields);
                foreach ($fields as $field) {
                    if (!is_array($field)) continue;
                    $key = $this->string($field['key'] ?? null);
                    $patterns = array_values(array_filter(array_map('strval', (array) ($field['label_patterns'] ?? []))));
                    if ($key === null || !$patterns || array_key_exists($key, $result)) continue;
                    $labelColumn = $this->findPatternColumn($row, $patterns, true);
                    if ($labelColumn === null) continue;
                    $value = $this->nextNonEmpty($row, $labelColumn + 1);
                    if ($value === null) $value = $this->valueFromSameCell((string) ($row[$labelColumn] ?? ''), $patterns);
                    if ($value !== null) $result[$key] = $this->cleanValue($value);
                }
            }
        }
        return $result;
    }

    /**
     * Some reports print report/facility fields as a header ROW (multiple field
     * labels across columns) with the actual values in a SEPARATE row directly
     * below at the same column positions - a spreadsheet-like shape, not the
     * "label | value" pairs within one row that the caller's own scan handles.
     * If this row matches 2+ of our own fields at once, treat it as that header
     * and pull values from the next row by column position instead.
     */
    private function applyColumnAlignedFields(array &$result, array $rows, int $rowIndex, array $fieldTemplates): void
    {
        if (!isset($rows[$rowIndex + 1])) return;
        $row = $rows[$rowIndex];

        // A genuine header row (values live in the NEXT row, not this one) never
        // itself contains an actual value - and report/facility fields always
        // include at least one date (report_date/control_date/validity_date). If
        // THIS row already has a date-looking cell, it's a normal "label | value"
        // row with several pairs packed into one line (e.g. "Ünvanı | 4A
        // LOJİSTİK... | Rapor Tarihi | 10.04.2026"), which the caller's own
        // same-row scan already handles - don't reinterpret it as a header, or
        // the next unrelated row's labels get grabbed as "values" instead.
        foreach ($row as $cell) {
            if (preg_match('/\b\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}\b/u', (string) $cell) === 1) return;
        }

        $headerMatches = [];
        foreach ($fieldTemplates as $field) {
            if (!is_array($field)) continue;
            $key = $this->string($field['key'] ?? null);
            $patterns = array_values(array_filter(array_map('strval', (array) ($field['label_patterns'] ?? []))));
            if ($key === null || !$patterns || array_key_exists($key, $result)) continue;
            $column = $this->findPatternColumn($row, $patterns, true);
            if ($column !== null) $headerMatches[$column] = $key;
        }

        if (count($headerMatches) < 2) return;

        $valueRow = $rows[$rowIndex + 1];
        foreach ($headerMatches as $column => $key) {
            $value = trim((string) ($valueRow[$column] ?? ''));
            if ($value !== '' && $value !== '-') $result[$key] = $this->cleanValue($value);
        }
    }

    /**
     * A label and its value can be printed in the same cell, e.g. "Rapor No:
     * PK.239.00026.01" with nothing but blank cells after it - nextNonEmpty()
     * never finds a value in a later column in that case. Strip the matched
     * label pattern (with optional trailing punctuation) as a prefix instead.
     */
    private function valueFromSameCell(string $cellText, array $patterns): ?string
    {
        $cellText = trim($cellText);
        if ($cellText === '') return null;

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') continue;

            $remaining = preg_replace(
                '/^\s*' . preg_quote($pattern, '/') . '\s*[:.\-]?\s*/iu',
                '',
                $cellText
            );

            if ($remaining !== null && $remaining !== '' && $remaining !== $cellText) {
                return trim($remaining);
            }
        }

        return null;
    }

    private function extractOverallResult(array $tables, array $template): array
    {
        $sectionPatterns = array_values(array_filter(array_map('strval', (array) ($template['section_heading_patterns'] ?? $template['camelot_extraction']['section_patterns'] ?? []))));
        // These two pattern lists serve different purposes and must stay separate:
        // - boundaryPatterns mark where the conclusion text ENDS (the next section's
        //   heading/label, e.g. "9. ONAY").
        // - extractionPatterns identify the status SENTENCE itself (e.g. ".*UYGUN
        //   DEĞİLDİR.*"), used only after collecting all candidate rows, to trim the
        //   collected text down to the relevant portion.
        // Previously both were read into one variable, with extractionPatterns taking
        // priority via ??. Since extractionPatterns matches the row that contains the
        // real status text, that row was mistaken for the START of the next section
        // and the scan broke before ever appending it - silently truncating the
        // collected text to whatever came just before the actual conclusion.
        $boundaryPatterns = array_values(array_filter(array_map('strval', (array) ($template['overall_text']['text_boundary_patterns'] ?? []))));
        $extractionPatterns = array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['text_patterns'] ?? []))));
        $statusPatterns = array_values(array_filter(array_map('strval', (array) ($template['camelot_extraction']['status_patterns'] ?? $template['overall_status']['status_patterns'] ?? []))));
        $active = !$sectionPatterns;
        $textParts = [];
        $status = null;
        foreach ($tables as $table) {
            if (($table['flavor'] ?? '') !== 'stream') continue;
            foreach ($this->matrix($table) as $row) {
                $text = $this->rowText($row);
                if ($sectionPatterns && $this->matchesAny($text, $sectionPatterns)) { $active = true; continue; }
                if (!$active) continue;
                if ($this->isOverallEndBoundary($text, $boundaryPatterns)) break 2;
                if ($text !== '') $textParts[] = $text;
                $matchedStatus = $this->matchResultValue($text, $statusPatterns);
                if ($matchedStatus !== null) $status = $matchedStatus;
            }
        }
        $fullText = trim(implode(' ', $textParts));
        if ($extractionPatterns) {
            foreach ($extractionPatterns as $pattern) {
                $match = @preg_match('~' . $pattern . '~iu', $fullText, $m);
                if ($match === 1 && !empty($m[0])) { $fullText = trim($m[0]); break; }
            }
        }
        $result = [];
        if ($fullText !== '') $result['text'] = $this->cleanValue($fullText);
        if ($status !== null) $result['status'] = $status;
        return $result;
    }

    private function isOverallEndBoundary(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesAny($text, [$pattern])) return true;
        }
        if (preg_match('/^\d+\.\s+/u', trim($text)) === 1 && !preg_match('/^8\.\s+/u', trim($text))) return true;
        return false;
    }

    private function findPatternColumn(array $row, array $patterns, bool $preferExact = false): ?int
    {
        if ($preferExact) {
            foreach ($row as $index => $value) {
                $normalizedValue = $this->normalizeLabel((string) $value);
                foreach ($patterns as $pattern) {
                    $normalizedPattern = $this->normalizeLabel((string) $pattern);
                    if ($normalizedValue === $normalizedPattern) return (int) $index;
                    // The ligature artifact above can swallow trailing letters
                    // rather than just a control character (e.g. "saati" ->
                    // "saa"), so an exact match can legitimately come up one or
                    // two characters short. Accept a close prefix match too,
                    // rather than only a byte-for-byte equal string.
                    if ($normalizedValue !== '' && $normalizedPattern !== ''
                        && mb_strlen($normalizedPattern, 'UTF-8') - mb_strlen($normalizedValue, 'UTF-8') <= 2
                        && str_starts_with($normalizedPattern, $normalizedValue)
                    ) return (int) $index;
                }
            }
        }
        foreach ($row as $index => $value) if ($this->matchesAny((string) $value, $patterns)) return (int) $index;
        return null;
    }

    private function matchPatternValue(string $value, array $patterns): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        return $this->matchesAny($value, $patterns) ? $value : null;
    }

    private function rowText(array $row): string
    {
        return trim(implode(' ', array_values(array_filter(array_map('strval', $row), fn ($v) => trim($v) !== ''))));
    }

    private function matrix(array $table): array
    {
        return array_values(array_map(fn ($row) => array_map(fn ($value) => trim((string) $value), (array) $row), (array) ($table['data'] ?? [])));
    }

    // table_shape header-role fallback for a roleDef with no column_index
    // (older capture). No regex anywhere: both tiers compare plain,
    // collapsed (regex-escapes and decorative punctuation like the
    // abbreviation dots in "U." / "U.D." / "N.U." stripped out) text.
    // Tier 1 - EXACT match - resolves short, mutually-substring-like codes
    // unambiguously (otherwise "U." would look "contained in" "U.D."/"N.U."
    // and wrongly bind to whichever is declared first). Only if NO roleDef
    // exactly matches does tier 2 - plain str_contains() - run, which longer
    // compound headers (e.g. "Dolap Bilgileri (Makarası - Tipi - ...)"
    // matched by a shorter declared pattern) still need.
    private function resolveColumnRole(string $cellValue, array $roleColumns): ?array
    {
        $collapsedValue = $this->collapseForMatch($cellValue);
        if ($collapsedValue === '') return null;

        foreach ($roleColumns as $roleDef) {
            if (!is_array($roleDef)) continue;
            foreach ($this->patterns($roleDef['header_patterns'] ?? []) as $pattern) {
                if ($this->collapseForMatch($pattern) === $collapsedValue) return $roleDef;
            }
        }
        // One direction only: the CELL text contains the (shorter) declared
        // pattern - the real compound-header case. Never the reverse (a
        // short cell "containing" a longer pattern is exactly the "U."
        // inside "U.D."/"N.U." trap tier 1 exists to avoid).
        foreach ($roleColumns as $roleDef) {
            if (!is_array($roleDef)) continue;
            foreach ($this->patterns($roleDef['header_patterns'] ?? []) as $pattern) {
                $collapsedPattern = $this->collapseForMatch($pattern);
                if ($collapsedPattern === '' || mb_strlen($collapsedPattern, 'UTF-8') > mb_strlen($collapsedValue, 'UTF-8')) continue;
                if (str_contains($collapsedValue, $collapsedPattern)) return $roleDef;
            }
        }
        return null;
    }

    // Reduces a header cell OR its regex-flavored declared pattern down to
    // its bare comparable letters - no pattern search, only fixed literal
    // replacement/removal. "\s*"/"\s+"/"\s" stands for a literal space in
    // the real text (not the letter "s" - stripping just the backslash
    // would leave a stray "s" with no counterpart in the real cell), so it
    // is swapped for a space FIRST. Remaining regex escape/metacharacters
    // and decorative punctuation (the abbreviation dots in "U.D."/"N.U.")
    // are then removed, and finally ALL whitespace is dropped too, so
    // "Dolap No." and "Dolap\s*No\." collapse to the identical "dolapno".
    private function collapseForMatch(string $value): string
    {
        $value = str_replace(['\\s*', '\\s+', '\\s'], ' ', $value);
        $value = str_replace(['\\', '^', '$', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '.'], '', $value);
        return str_replace(' ', '', $this->normalizeLabel($value));
    }

    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) if ($this->regexMatches($pattern, $value)) return true;
        return false;
    }

    private function regexMatches(string $pattern, string $value): bool
    {
        if (@preg_match($pattern, $value) === 1) return true;
        if (@preg_match('~' . $pattern . '~iu', $value) === 1) return true;
        return $this->normalizeLabel($pattern) === $this->normalizeLabel($value);
    }

    private function normalizeLabel(string $value): string
    {
        // Some PDFs render a "ti"/"fi"-style ligature as a stray control
        // character (observed as \x00) that swallows those letters entirely
        // (e.g. "Saati" -> "Saa" + \x00) - strip it so it doesn't block an
        // otherwise-exact label match.
        $value = preg_replace('/[\x00-\x1F]/u', '', $value) ?? $value;
        // A "label" passed in here is often actually a self-anchored regex
        // pattern (e.g. "^Muayene Tarihi ve Saati$") rather than a plain string -
        // strip the literal anchor characters so an exact/prefix comparison
        // against real (unanchored) cell text isn't defeated by them.
        $value = preg_replace('/^\^|\$$/u', '', trim($value)) ?? $value;
        $value = mb_strtolower(trim($value), 'UTF-8');
        return rtrim(preg_replace('/\s+/u', ' ', $value) ?? $value, ':');
    }

    private function normalizeCode(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($value)) ?? '', 'UTF-8');
    }

    private function nextNonEmpty(array $row, int $start): ?string
    {
        for ($i = $start; $i < count($row); $i++) {
            $value = trim((string) ($row[$i] ?? ''));
            if ($value === '') continue;
            // A lone "-" is the report's own explicit "no value recorded" marker
            // for THIS field - stop here and report no value, rather than
            // skipping past it into the NEXT field's label/value (e.g. "İmal
            // Yılı | - | Su Kaynağı | DEPO" wrongly returning "Su Kaynağı" as
            // the manufacture year).
            if ($value === '-') return null;
            return $value;
        }
        return null;
    }

    private function cleanValue(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(["\n", "\r"], ' ', $value)) ?? $value);
    }

    private function sanitizeUtf8(mixed $value): mixed
    {
        if (is_string($value)) {
            if (mb_check_encoding($value, 'UTF-8')) return $value;
            $clean = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            return $clean === false ? '' : $clean;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) $out[is_string($key) ? $this->sanitizeUtf8($key) : $key] = $this->sanitizeUtf8($item);
            return $out;
        }
        return $value;
    }

    private function findInvalidUtf8Path(mixed $value, string $path = '$'): ?string
    {
        if (is_string($value)) return mb_check_encoding($value, 'UTF-8') ? null : $path;
        if (!is_array($value)) return null;
        foreach ($value as $key => $item) {
            if (is_string($key) && !mb_check_encoding($key, 'UTF-8')) return $path . '[key]';
            $child = is_int($key) ? $path . '[' . $key . ']' : $path . '[' . json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ']';
            $bad = $this->findInvalidUtf8Path($item, $child);
            if ($bad !== null) return $bad;
        }
        return null;
    }

    private function string(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function patterns(mixed $value): array
    {
        return array_values(array_filter(array_map('strval', (array) $value)));
    }

    // property role with declared sub_keys: some reports pack several
    // distinct properties into ONE compound column (e.g. "Makarası - Tipi -
    // Makara Bağlantısı - Vana Tipi"). Split the cell text by its
    // dash/newline separators and assign each part to its sub-key IN
    // ORDER - but only when the split actually produces exactly as many
    // non-empty parts as declared; otherwise this particular row's value
    // doesn't really match the expected shape and forcing a split would
    // silently scramble it (e.g. a serial number that itself contains a
    // dash), so keep it as one blob under the base key instead.
    private function assignPropertyValue(array &$properties, string $key, array $subKeys, string $value): void
    {
        if (!$subKeys) {
            $properties[$key] = $this->cleanValue($value);
            return;
        }

        $normalized = preg_replace('/[\r\n]+/u', ' - ', $value) ?? $value;
        $parts = array_values(array_filter(
            array_map('trim', preg_split('/\s*[-–—]\s*/u', $normalized) ?: []),
            fn ($part) => $part !== ''
        ));

        if (count($parts) !== count($subKeys)) {
            $properties[$key] = $this->cleanValue($value);
            return;
        }

        foreach ($subKeys as $index => $subKey) {
            $properties[$subKey] = $this->cleanValue($parts[$index]);
        }
    }

    // Converts extracted_data.report_information / .facility_information
    // (an array of {key, value} pairs, Gemini's real-value output) into the
    // flat assoc array the rest of the pipeline expects. A key with a null
    // or blank value is dropped, not kept as an empty string.
    private function extractedFieldValues(mixed $fields): array
    {
        $result = [];
        foreach ((array) $fields as $field) {
            if (!is_array($field)) continue;
            $key = $this->string($field['key'] ?? null);
            $value = $this->string($field['value'] ?? null);
            if ($key === null || $value === null) continue;
            $result[$key] = $value;
        }
        return $result;
    }
}
