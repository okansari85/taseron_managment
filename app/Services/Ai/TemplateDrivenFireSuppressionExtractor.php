<?php

namespace App\Services\Ai;

use RuntimeException;

class TemplateDrivenFireSuppressionExtractor
{
    public function __construct(private CamelotPdfTableExtractor $camelot) {}

    public function extract(string $pdfPath, array $semantic): array
    {
        // extraction_mode="single_equipment" (a genuinely single piece of
        // equipment - forklift/transpalet/crane/single tank, no repeating
        // dolap/tüp/pompa table at all): Gemini already reads everything
        // directly with real values (report_information/system_criteria/
        // equipment_definitions with equipment_axis="none"/findings), none
        // of which touch $latticeTables - such a report can legitimately
        // have NO bordered/lattice table anywhere in the PDF, so requiring
        // one here would reject a perfectly valid extraction. "structured"
        // (the default - a mixed/tesisat report with real dolap/tüp/pompa
        // tables) still requires at least one, since equipment_axis="rows"/
        // "columns" definitions genuinely need Camelot to read from.
        $extractionMode = mb_strtolower(trim((string) ($semantic['extracted_data']['extraction_mode'] ?? 'structured')), 'UTF-8');

        $camelot = $this->camelot->extract($pdfPath);
        $allTables = array_values(array_filter((array) ($camelot['tables'] ?? []), fn ($table) => is_array($table) && !empty($table['data'])));
        $latticeTables = array_values(array_filter($allTables, fn ($table) => ($table['flavor'] ?? '') === 'lattice'));
        if (!$latticeTables && $extractionMode !== 'single_equipment') {
            throw new RuntimeException('Camelot template extraction için kullanılabilir lattice tablo bulamadı.');
        }

        $template = is_array($semantic['template'] ?? null) ? $semantic['template'] : [];
        $systems = (array) ($template['fire_systems']['systems'] ?? []);
        $extractedSystems = [];

        // Cells a table-shape reader has already claimed as an equipment
        // instance's own identity/property/result/note column (keyed
        // tableIndex -> row -> column) - populated as equipment_definitions
        // are read below, consulted so two different equipment groups on
        // the SAME shared table never reinterpret each other's cells.
        $claimedCells = [];

        foreach ($systems as $system) {
            if (!is_array($system)) continue;
            $systemName = $this->string($system['system_name'] ?? null);
            if ($systemName === null) continue;

            // system_criteria: real code/text/result values Gemini read
            // directly for this system's OWN (equipment-independent)
            // checklist (e.g. "Genel Tespit"/"Belge ve Kayıt Kontrolleri").
            // 'result' here is {raw, label} - raw is the UNNORMALIZED cell
            // text (e.g. "U"/"UD"), passed straight through so the SAME
            // proven normalizeResult() further down the pipeline (which
            // already recognizes many real symbol sets - U/UD/N, U./U.D./
            // N.U., U/U.D/U.Y/G, ✔/✘...) interprets it, instead of trusting
            // Gemini's own (occasionally wrong) interpretation.
            $controlItems = [];
            foreach ((array) ($system['system_criteria'] ?? []) as $criterionEntry) {
                if (!is_array($criterionEntry)) continue;
                $code = $this->string($criterionEntry['code'] ?? null);
                $text = $this->string($criterionEntry['text'] ?? null);
                if ($code === null || $text === null) continue;
                $resultRaw = is_array($criterionEntry['result'] ?? null)
                    ? $this->string($criterionEntry['result']['raw'] ?? null)
                    : $this->string($criterionEntry['result'] ?? null);
                $controlItems[] = [
                    'code' => $code,
                    'criterion' => $text,
                    'result' => $resultRaw,
                    'source_pages' => [],
                ];
            }

            // equipment_definitions: one entry per equipment TYPE/GROUP, not
            // per instance. equipment_axis="none" (genuinely one real
            // instance) carries its own real values directly (identity_
            // value/attributes[].value/criteria[].result) - read here like
            // report_information. equipment_axis="rows"/"columns" (possibly
            // unbounded) carries STRUCTURE only - converted into the SAME
            // internal role-list shape extractEquipmentFromTableShape()
            // already reads (identity/property, no declared result roles at
            // all: the real per-instance criteria are recovered by that
            // function's own undeclared-row auto-detection, already proven
            // on real multi-hundred-equipment reports - Gemini is no longer
            // asked to enumerate them).
            $equipment = [];
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                if (!is_array($definition)) continue;
                $instanceStructure = (array) ($definition['instance_structure'] ?? []);
                $axis = mb_strtolower(trim((string) ($instanceStructure['equipment_axis'] ?? '')), 'UTF-8');

                if ($axis === 'none') {
                    $properties = [];
                    foreach ((array) ($definition['attributes'] ?? []) as $attribute) {
                        if (!is_array($attribute)) continue;
                        $field = $this->string($attribute['field'] ?? null);
                        $value = $this->string($attribute['value'] ?? null);
                        if ($field === null || $value === null) continue;
                        $properties[$field] = $this->cleanValue($value);
                    }
                    $directControlItems = [];
                    $criteria = (array) ($definition['equipment_control_criteria']['criteria'] ?? []);
                    foreach ($criteria as $criterionEntry) {
                        if (!is_array($criterionEntry)) continue;
                        $code = $this->string($criterionEntry['code'] ?? null);
                        if ($code === null) continue;
                        $resultRaw = is_array($criterionEntry['result'] ?? null)
                            ? $this->string($criterionEntry['result']['raw'] ?? null)
                            : null;
                        $directControlItems[] = [
                            'code' => $code,
                            'criterion' => $this->string($criterionEntry['text'] ?? null),
                            'result' => $resultRaw,
                        ];
                    }
                    $equipment[] = [
                        'code' => $this->string($instanceStructure['identity_value'] ?? null),
                        'name' => $this->string($definition['equipment_name'] ?? null),
                        'system_name' => $systemName,
                        'properties' => $properties,
                        'result' => null,
                        'note' => null,
                        'direct_control_items' => $directControlItems,
                        'source_pages' => [],
                    ];
                    continue;
                }

                $equipmentTemplate = $this->equipmentTemplateFromDefinition($definition, $systemName);
                if ($equipmentTemplate === null) continue;
                foreach ($this->extractEquipmentFromTableShape($latticeTables, $equipmentTemplate, $claimedCells) as $item) {
                    $equipment[] = $item;
                }
            }

            // A per-tüp criteria column's own header text is often a short,
            // physically-truncated excerpt of the real question (the PDF's
            // 7-column table header has no room for the full sentence) - the
            // SAME criterion usually also appears with its FULL wording as a
            // system-level control_item (declared separately in the report's
            // general checklist section, matched by control_code_patterns/
            // control_text_patterns above) sharing the SAME leading code
            // number. Prefer that longer text when synthesizing per-equipment
            // criteria below, purely as a display improvement - never changes
            // which result each criterion carries.
            $fullCriterionByCode = [];
            foreach ($controlItems as $existingItem) {
                $normalizedCode = $this->normalizeCode((string) ($existingItem['code'] ?? ''));
                $existingCriterion = $this->string($existingItem['criterion'] ?? null);
                if ($normalizedCode === '' || $existingCriterion === null) continue;
                if (isset($fullCriterionByCode[$normalizedCode]) && mb_strlen($fullCriterionByCode[$normalizedCode], 'UTF-8') >= mb_strlen($existingCriterion, 'UTF-8')) continue;
                $fullCriterionByCode[$normalizedCode] = $existingCriterion;
            }

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
            foreach ($equipment as &$item) {
                $code = $item['code'] ?? null;
                // Direct-read equipment (extracted_data.equipment) can carry
                // its OWN full per-criterion checklist (e.g. a single-
                // equipment report's real inspection list) - synthesize ONE
                // equipment-scoped control_item per criterion instead of the
                // single-aggregate-result fallback below, so each criterion
                // keeps its own code/text/result rather than collapsing into
                // one overall mark.
                $directControlItems = $item['direct_control_items'] ?? [];
                unset($item['direct_control_items']);
                if ($code !== null && $directControlItems) {
                    foreach ($directControlItems as $controlEntry) {
                        $controlCode = 'EQP-' . $code . '-' . $controlEntry['code'];
                        $occurrence = $codeOccurrences[$controlCode] ?? 0;
                        $codeOccurrences[$controlCode] = $occurrence + 1;
                        if ($occurrence > 0) $controlCode .= '#' . ($occurrence + 1);

                        $ownCriterion = $this->string($controlEntry['criterion'] ?? null);
                        $fullCriterion = $fullCriterionByCode[$this->normalizeCode((string) $controlEntry['code'])] ?? null;
                        $criterion = ($fullCriterion !== null && ($ownCriterion === null || mb_strlen($fullCriterion, 'UTF-8') > mb_strlen($ownCriterion, 'UTF-8'))) ? $fullCriterion : $ownCriterion;

                        $controlItems[] = [
                            'code' => $controlCode,
                            'criterion' => $criterion,
                            'scope' => 'equipment',
                            'equipment' => $code,
                            'result' => $controlEntry['result'],
                            'source_pages' => $item['source_pages'] ?? [],
                        ];
                    }
                    continue;
                }

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
            unset($item);

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

    // Converts a NEW-schema equipment_definitions[] entry (instance_
    // structure + attributes, both structural - no per-instance values for
    // an unbounded rows/columns group) into the internal table_shape shape
    // extractEquipmentFromTableShape() already reads, so that function and
    // everything it calls (row/column readers, the header-row anchor
    // safety check, the axis fallback, the undeclared-row auto-criterion
    // detection - all proven on real multi-hundred-equipment reports) stay
    // completely untouched. Deliberately declares NO "result" roles at
    // all: Gemini is no longer asked to enumerate criteria columns/rows one
    // by one (real reports have shown this both undercounts wildly-long
    // criteria lists AND over-declares when Gemini feels forced to invent
    // structure it isn't sure of) - every row/column not claimed by
    // identity/property here is automatically treated as its own dynamic
    // criterion by the existing reader. Returns null when there is no
    // usable identity_field (nothing to anchor on).
    private function equipmentTemplateFromDefinition(array $definition, string $systemName): ?array
    {
        $instanceStructure = (array) ($definition['instance_structure'] ?? []);
        $axis = mb_strtolower(trim((string) ($instanceStructure['equipment_axis'] ?? '')), 'UTF-8');
        $identityField = $this->string($instanceStructure['identity_field'] ?? null);
        if ($identityField === null || !in_array($axis, ['rows', 'columns'], true)) return null;

        $headerPatterns = $this->patterns($instanceStructure['header_patterns'] ?? []);
        if (!$headerPatterns) $headerPatterns = [$identityField];

        $roleColumns = [
            ['role' => 'identity', 'header_patterns' => [$identityField]],
        ];
        foreach ((array) ($definition['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute)) continue;
            $field = $this->string($attribute['field'] ?? null);
            $sourcePattern = $this->string($attribute['source_pattern'] ?? null) ?? $field;
            if ($field === null || $sourcePattern === null) continue;
            $roleColumns[] = ['role' => 'property', 'header_patterns' => [$sourcePattern], 'key' => $field];
        }

        // Explicit note/fixed-outcome declarations (see instance_structure.
        // result_columns in GeminiTemplateDiscoveryClient) - reuses the
        // SAME 'note'/'result' roles the deep rows/columns engines already
        // understand (collapsed exact/substring text matching via
        // resolveColumnRole()/collapseForMatch(), never regex). A column NOT
        // declared here still falls through to the engines' own undeclared-
        // column auto-detection (each becomes its own dynamic criterion),
        // which stays correct for genuinely independent criteria.
        foreach ((array) ($instanceStructure['result_columns'] ?? []) as $resultColumnDef) {
            if (!is_array($resultColumnDef)) continue;
            $headerPattern = $this->string($resultColumnDef['header_pattern'] ?? null);
            if ($headerPattern === null) continue;
            $kind = mb_strtolower(trim((string) ($resultColumnDef['kind'] ?? '')), 'UTF-8');
            if ($kind === 'note') {
                $roleColumns[] = ['role' => 'note', 'header_patterns' => [$headerPattern]];
                continue;
            }
            if ($kind === 'fixed_value') {
                $value = $this->string($resultColumnDef['value'] ?? null);
                if ($value === null) continue;
                $roleColumns[] = ['role' => 'result', 'header_patterns' => [$headerPattern], 'value' => $value];
            }
        }

        return [
            'equipment_name' => $this->string($definition['equipment_name'] ?? null),
            'system_name' => $systemName,
            'table_shape' => [
                'instance_axis' => $axis,
                'header_row_patterns' => $headerPatterns,
                'columns' => $roleColumns,
            ],
        ];
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
    private function extractEquipmentFromTableShape(array $tables, array $template, array &$claimedCells = []): array
    {
        $shape = (array) ($template['table_shape'] ?? []);
        $axis = mb_strtolower(trim((string) ($shape['instance_axis'] ?? '')), 'UTF-8');
        $roleColumns = (array) ($shape['columns'] ?? []);

        // Gemini sometimes recognizes that a per-unit equipment table exists
        // (it names the header row via header_row_patterns, e.g. "Soru /
        // Kriter" / "Dolap No") without managing to name a single column
        // role for it (columns: []). Reverse-engineering the role list from
        // Camelot's raw cells alone was tried here and repeatedly produced
        // wrong data on real reports (a generic block-title row like "Soru
        // / Kriter" is indistinguishable from a genuine header without
        // knowing which system it belongs to, and page-scoping it well
        // enough to be safe needs more context than this method has). The
        // reliable fix is upstream: TemplateDiscoveryFireSuppressionAnalyzer's
        // prompt must get Gemini to always enumerate identity/property
        // roles for a table it already recognized, never leaving columns:
        // [] - not something this extractor should guess around.
        if ($axis === '' || !$roleColumns) return [];

        if ($axis === 'none') return $this->extractShapeSingle($tables, $roleColumns, $template);

        // Gemini's declared axis for a transposed equipment matrix (rows of
        // equipment vs. columns of equipment) is not always reliable - the
        // SAME real "Yangın Dolapları" table produced "columns" on one real
        // Gemini call and "rows" on a separate real call for the identical
        // PDF, while the underlying Camelot table never changes. Both axis
        // extractors are cheap, read-only structural scans (nothing is
        // written until one of them actually finds instances), so when the
        // declared axis yields nothing, trying the other one before giving
        // up recovers the real equipment instead of silently returning
        // empty over one flaky field.
        if ($axis === 'columns') {
            $items = $this->extractEquipmentFromColumns($tables, $shape, $roleColumns, $template);
            return $items ?: $this->extractEquipmentFromRows($tables, $shape, $roleColumns, $template, $claimedCells);
        }
        if ($axis === 'rows') {
            $items = $this->extractEquipmentFromRows($tables, $shape, $roleColumns, $template, $claimedCells);
            return $items ?: $this->extractEquipmentFromColumns($tables, $shape, $roleColumns, $template);
        }
        return [];
    }

    private function extractEquipmentFromRows(array $tables, array $shape, array $roleColumns, array $template, array &$claimedCells = []): array
    {
        $headerPatterns = $this->patterns($shape['header_row_patterns'] ?? []);
        if (!$headerPatterns) return [];

        $items = [];
        foreach ($tables as $tableIndex => $table) {
            $grid = $this->matrix($table);
            if (!$grid) continue;

            foreach ($grid as $headerRowIndex => $row) {
                if (!$this->isHeaderRow($row, $headerPatterns)) continue;

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
                $resultColumnHeaders = [];
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
                    if ($role === 'result') {
                        $resultColumns[(int) $column] = $this->string($roleDef['value'] ?? null);
                        $resultColumnHeaders[(int) $column] = $this->patterns($roleDef['header_patterns'] ?? [])[0] ?? null;
                        continue;
                    }
                    if ($role === 'note' && $noteColumn === null) { $noteColumn = (int) $column; continue; }
                }

                // Any column in the SAME header row that no declared role
                // (identity/property) claimed is automatically treated as
                // its own dynamic result column - the NEW schema no longer
                // asks Gemini to enumerate result/note columns one by one
                // (U./U.D./N.U., a "Durum" column, a numbered per-criterion
                // set, "AÇIKLAMALAR"...), only identity + real fixed
                // properties. Without this, a real report's whole
                // compliance data (every U/UD/N mark) would be silently
                // dropped - the columns-axis reader already does the
                // equivalent for transposed tables (see
                // extractEquipmentFromColumns()), this mirrors it for the
                // rows axis. A column's own header cell text becomes its
                // criterion label (leadingNumber() below still recovers a
                // real code like "5.38" from it when present).
                if ($identityColumn !== null) {
                    foreach ($row as $column => $cellValue) {
                        $column = (int) $column;
                        if (array_key_exists($column, $columnRoles)) continue;
                        $headerLabel = trim((string) $cellValue);
                        if ($headerLabel === '') continue;
                        $resultColumns[$column] = null;
                        $resultColumnHeaders[$column] = $headerLabel;
                    }
                }

                if ($identityColumn === null || (!$propertyColumns && !$resultColumns)) continue;

                // Three genuinely different shapes can all declare 2+ "result"
                // columns, and only the real report distinguishes them:
                // - Classic U./U.D./N.U. checkbox columns each stand for a
                //   DIFFERENT outcome of the SAME one judgment (fixed values
                //   differ across columns) - collapse to one aggregate result
                //   per row (existing behaviour below, kept unchanged).
                // - A per-tüp 7-criteria matrix (real AKTAŞ report) declares N
                //   columns that ALL share the SAME fixed value (e.g. every
                //   column says "uygun" - a mark in THAT column means THAT
                //   specific criterion, from its own header text, is
                //   compliant) - the only way to tell this apart from a
                //   genuine 3-way outcome block is that a REAL outcome
                //   block's values are never all identical.
                // - A per-criterion matrix where each criterion's OWN column
                //   has a DYNAMIC (null) per-row code instead of a fixed mark
                //   (real OKCO "Yangın Dolapları" report: 15 criteria ROWS,
                //   each already independently one column per equipment in
                //   the transposed columns-axis case, but the SAME dynamic-
                //   per-cell shape can appear on the rows axis too - N result
                //   columns ALL declared value=null, each one its own
                //   criterion, cell text read as-is per row). There is no
                //   schema field for "this is N separate criteria" in either
                //   sub-case, so Gemini expresses it as N result columns that
                //   are either all-same-fixed or all-dynamic.
                $fixedCriterionColumns = array_filter($resultColumns, fn ($value) => $value !== null);
                $distinctFixedValues = array_unique(array_values($fixedCriterionColumns));
                $isMultiCriterionResultBlock =
                    (count($fixedCriterionColumns) >= 2 && count($distinctFixedValues) === 1)
                    || (count($resultColumns) >= 2 && count($fixedCriterionColumns) === 0);

                $found = false;
                for ($r = $headerRowIndex + 1; $r < count($grid); $r++) {
                    $dataRow = $grid[$r];

                    // Claim every declared column for this row UP FRONT
                    // (before the empty/header-break checks below can skip
                    // the rest of the loop body) - a control-item scan that
                    // later walks the SAME tables must never reinterpret this
                    // equipment table's own identity/property/result/note
                    // cells as an unrelated system-level control code.
                    $claimedCells[$tableIndex][$r][$identityColumn] = true;
                    foreach ($propertyColumns as $propertyColumn => $ignored) $claimedCells[$tableIndex][$r][$propertyColumn] = true;
                    foreach ($resultColumns as $resultColumnIndex => $ignored) $claimedCells[$tableIndex][$r][$resultColumnIndex] = true;
                    if ($noteColumn !== null) $claimedCells[$tableIndex][$r][$noteColumn] = true;

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

                    // Per-criterion breakdown (only when the same-fixed-value
                    // signal above says this really is N separate criteria,
                    // not a 3-way outcome block) - one entry per declared
                    // result column, code/criterion taken from its OWN header
                    // text, result = the column's fixed value if this row's
                    // cell has a mark, 'uygun_degil' if blank (an unmarked
                    // per-criterion checkbox means that criterion was not
                    // confirmed compliant for THIS specific tüp/equipment -
                    // never silently assumed compliant). Reuses the SAME
                    // direct_control_items → equipment-scoped control_items
                    // synthesis already built for Gemini-direct-read
                    // single-equipment checklists (see extract() below) - no
                    // new downstream plumbing needed.
                    $criteriaResults = [];
                    if ($isMultiCriterionResultBlock) {
                        $criterionPosition = 0;
                        foreach ($resultColumns as $column => $fixedValue) {
                            $criterionPosition++;
                            $headerText = $resultColumnHeaders[$column] ?? null;
                            $code = ($headerText !== null ? $this->leadingNumber($headerText) : null) ?? (string) $criterionPosition;
                            $criterionText = $headerText !== null ? $this->stripLeadingCode($headerText, $code) : null;
                            if ($criterionText === null || $criterionText === '') $criterionText = $headerText;
                            $cellText = trim((string) ($dataRow[$column] ?? ''));
                            $criteriaResults[] = [
                                'code' => $code,
                                'criterion' => $criterionText,
                                // Fixed-value sub-case (AKTAŞ-style, e.g. every
                                // criterion column says "uygun"): the column's
                                // declared value is only a DEFAULT for "this
                                // criterion has a mark" - a report can (and
                                // does) sometimes write the real negative
                                // code/glyph directly into the SAME column
                                // instead of leaving it blank, so the cell's
                                // ACTUAL text is checked first.
                                // Dynamic sub-case (OKCO-style, e.g. every
                                // criterion column/row's own cell independently
                                // varies U/UD/N per equipment): there is no
                                // fixed reference value to fall back to, so the
                                // raw per-cell code is passed straight through
                                // (blank -> unknown/null, never guessed) -
                                // FireSuppressionUnifiedNormalizer::normalizeResult()
                                // downstream already recognizes these short codes.
                                'result' => $fixedValue !== null
                                    ? $this->interpretCriterionCell($cellText, $fixedValue)
                                    : ($cellText !== '' ? $this->cleanValue($cellText) : null),
                            ];
                        }
                    }

                    if (!$properties && $result === null && $note === null && !$criteriaResults) continue;

                    $items[] = [
                        'code' => $this->cleanValue($identityValue),
                        'name' => $this->string($template['equipment_name'] ?? null),
                        'system_name' => $this->string($template['system_name'] ?? null),
                        'direct_control_items' => $criteriaResults,
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
        // A new instance block is anchored ONLY by the declared IDENTITY
        // row's own header pattern (e.g. "No / Kod") - NOT by the full
        // shape['header_row_patterns'] list, which can also contain every
        // property row's label (Gemini sometimes lists "No / Kod", "Kat",
        // "Marka", ... together as if they jointly describe one header row,
        // even though each is its OWN separate row in a columns-axis table).
        // Anchoring on any of those too meant a plain "Marka" row anywhere in
        // ANY table on the page could be mistaken for the start of a brand
        // new (bogus) equipment block, turning that row's own values into
        // fake equipment identities. The identity role is unambiguous and
        // unique to this equipment, so it is the only safe anchor.
        $headerPatterns = [];
        foreach ($roleColumns as $roleDef) {
            if (is_array($roleDef) && ($roleDef['role'] ?? '') === 'identity') {
                $headerPatterns = array_merge($headerPatterns, $this->patterns($roleDef['header_patterns'] ?? []));
            }
        }
        if (!$headerPatterns) return [];

        // Transpose of the rows-axis case (see extractEquipmentFromTableShape):
        // there, N criteria sharing the SAME fixed result value show up as N
        // COLUMNS; here (equipment side-by-side in columns) the same real
        // shape shows up as N ROWS instead, each row's role still resolved by
        // its own label text. Same discriminator: a genuine 3-way outcome
        // block (U/U.D./N.U.-style rows) declares DIFFERENT fixed values per
        // row - N rows sharing the IDENTICAL fixed value can only mean N
        // separate criteria, one per row, each independently checked for
        // EVERY equipment column.
        // Real OKCO "Yangın Dolapları" report: 15 criteria rows (5.38-5.52),
        // each its OWN row, each cell independently U/UD/N PER equipment
        // column - no single fixed value at all (the DYNAMIC sub-case,
        // mirrored from extractEquipmentFromTableShape's rows-axis version).
        $resultRoleDefCount = 0;
        $fixedResultRoleDefs = [];
        foreach ($roleColumns as $roleDef) {
            if (!is_array($roleDef) || ($roleDef['role'] ?? '') !== 'result') continue;
            $resultRoleDefCount++;
            $fixedValue = $this->string($roleDef['value'] ?? null);
            if ($fixedValue !== null) $fixedResultRoleDefs[] = $fixedValue;
        }
        $isMultiCriterionResultBlock =
            (count($fixedResultRoleDefs) >= 2 && count(array_unique($fixedResultRoleDefs)) === 1)
            || ($resultRoleDefCount >= 2 && $fixedResultRoleDefs === []);

        // The header row can repeat multiple times (e.g. a new 5-or-10-wide
        // block of instances starting fresh on each page) - accumulate every
        // block found instead of stopping at the first, or later blocks
        // silently go missing.
        $allItems = [];
        foreach ($tables as $table) {
            $grid = $this->matrix($table);
            if (!$grid) continue;

            foreach ($grid as $headerRowIndex => $row) {
                if (!$this->isHeaderRow($row, $headerPatterns)) continue;

                // The first cell matching header_row_patterns is this row's
                // OWN label (e.g. "No / Kod"); every OTHER non-empty cell
                // AFTER it in the SAME row is one equipment instance's
                // identity, at that column position. Found in its OWN pass
                // first (rather than inline while scanning left-to-right) -
                // a real report can prefix the label with a purely
                // decorative row-reference cell of its own (observed: an
                // Excel-style row letter "A"/"G"/"M"... sitting in column 0
                // before "Dolap No" itself in column 1) that carries no real
                // equipment identity at all. Only cells found AFTER the
                // label column are real per-instance data - anything before
                // it is auxiliary/reference content, never an instance.
                $labelColumn = null;
                foreach ($row as $column => $cellValue) {
                    $cellValue = trim((string) $cellValue);
                    if ($cellValue === '') continue;
                    if ($this->matchesAny($cellValue, $headerPatterns)) { $labelColumn = (int) $column; break; }
                }
                if ($labelColumn === null) continue;

                $instanceColumns = [];
                foreach ($row as $column => $cellValue) {
                    $column = (int) $column;
                    if ($column <= $labelColumn) continue;
                    $cellValue = trim((string) $cellValue);
                    if ($cellValue === '') continue;
                    $instanceColumns[$column] = $cellValue;
                }
                if ($labelColumn === null || !$instanceColumns) continue;

                // Every column BEFORE the first equipment instance column is
                // part of this row's OWN label region - a real report can
                // split a criterion's code and its text across TWO separate
                // cells there (e.g. code alone in one cell, criterion text in
                // the next) instead of fusing them into one, depending on how
                // the PDF wraps that particular row. Concatenating the whole
                // region (not just the first non-empty cell) means
                // resolveColumnRole() below sees the FULL text either way.
                $labelRegionEnd = min(array_keys($instanceColumns));

                // Special case, one real report observed: instead of
                // widening the table further, a single identity cell can
                // cram SEVERAL equipment codes together, hyphen-joined
                // (e.g. "48-49-50-...-61") when that many share the exact
                // same recorded properties/criteria answers. Only a cell
                // that is ENTIRELY hyphen-joined plain digit codes expands
                // this way (a real single code that happens to contain a
                // hyphen, e.g. "A-12", is left alone) - every expanded code
                // gets its OWN equipment entry, all reading from this SAME
                // shared column position (so identical properties/criteria
                // answers, which is exactly what the compressed cell means).
                $items = [];
                $criteriaResults = [];
                foreach ($instanceColumns as $column => $identity) {
                    $items[$column] = [];
                    foreach ($this->expandCompressedIdentity($identity) as $code) {
                        $items[$column][] = [
                            'code' => $this->cleanValue($code),
                            'name' => $this->string($template['equipment_name'] ?? null),
                            'system_name' => $this->string($template['system_name'] ?? null),
                            'properties' => [],
                            'result' => null,
                            'note' => null,
                            'source_pages' => [(int) ($table['page'] ?? 0)],
                        ];
                    }
                    $criteriaResults[$column] = [];
                }

                // Gemini declares WHERE this block starts (header_row_patterns)
                // and WHICH rows are identity/property - it is never asked how
                // many rows the block spans, or to enumerate every criterion
                // row individually (real-world gap observed: a Gemini call can
                // declare the identity+property rows correctly and simply stop
                // there, never mentioning the criteria rows beneath them even
                // though Camelot's raw table clearly has them - see OKCO
                // "Yangın Dolapları" fixture, 15 undeclared 5.38-5.52 rows).
                // The block's END is instead found structurally, from the
                // SAME Camelot data Gemini already saw: either the header row
                // repeats (a fresh instance block, e.g. next page) or a row is
                // completely empty across every instance column (never a real
                // per-equipment answer row - always a section heading or a
                // legend line). A first pass locates that boundary and counts
                // how many in-range rows Gemini left undeclared; the real
                // extraction pass below then treats any such undeclared row as
                // an additional dynamic (per-column) criterion, exactly like
                // the OKCO-style declared-dynamic case, instead of dropping it.
                $blockEnd = count($grid);
                $undeclaredRowCount = 0;
                for ($r = $headerRowIndex + 1; $r < count($grid); $r++) {
                    $dataRow = $grid[$r];
                    $labelParts = [];
                    for ($labelColumnIndex = 0; $labelColumnIndex < $labelRegionEnd; $labelColumnIndex++) {
                        $cellValue = trim((string) ($dataRow[$labelColumnIndex] ?? ''));
                        if ($cellValue !== '') $labelParts[] = $cellValue;
                    }
                    $rowLabel = $labelParts ? implode(' ', $labelParts) : null;
                    if ($rowLabel === null) continue;
                    if ($this->matchesAny($rowLabel, $headerPatterns)) { $blockEnd = $r; break; }

                    $hasAnyInstanceValue = false;
                    foreach ($instanceColumns as $column => $identity) {
                        if (trim((string) ($dataRow[$column] ?? '')) !== '') { $hasAnyInstanceValue = true; break; }
                    }
                    if (!$hasAnyInstanceValue) { $blockEnd = $r; break; }

                    if ($this->resolveColumnRole($rowLabel, $roleColumns) === null) $undeclaredRowCount++;
                }
                $blockIsMultiCriterion = $isMultiCriterionResultBlock || $undeclaredRowCount >= 1;

                $criterionPosition = 0;
                for ($r = $headerRowIndex + 1; $r < $blockEnd; $r++) {
                    $dataRow = $grid[$r];
                    $labelParts = [];
                    for ($labelColumnIndex = 0; $labelColumnIndex < $labelRegionEnd; $labelColumnIndex++) {
                        $cellValue = trim((string) ($dataRow[$labelColumnIndex] ?? ''));
                        if ($cellValue !== '') $labelParts[] = $cellValue;
                    }
                    $rowLabel = $labelParts ? implode(' ', $labelParts) : null;
                    if ($rowLabel === null) continue;

                    $roleDef = $this->resolveColumnRole($rowLabel, $roleColumns);
                    if ($roleDef === null) {
                        // Undeclared but inside the structurally-bounded block -
                        // Gemini simply never named this row; treat it as its
                        // own dynamic criterion (no shared fixed value, since
                        // none was ever declared for it).
                        $roleDef = ['role' => 'result', 'value' => null];
                    }
                    $role = (string) ($roleDef['role'] ?? '');
                    if (!in_array($role, ['property', 'result', 'note'], true)) continue;

                    // One criterion ROW applies to EVERY equipment column at
                    // once - derive its code/text ONCE per row, not per
                    // column (same criterion, different equipment answers).
                    $fixedValue = $role === 'result' ? $this->string($roleDef['value'] ?? null) : null;
                    $criterionCode = null;
                    $criterionText = null;
                    if ($role === 'result' && $blockIsMultiCriterion) {
                        $criterionPosition++;
                        $criterionCode = $this->leadingNumber($rowLabel) ?? (string) $criterionPosition;
                        $criterionText = $this->stripLeadingCode($rowLabel, $criterionCode);
                        if ($criterionText === '') $criterionText = $rowLabel;
                    }

                    foreach ($instanceColumns as $column => $identity) {
                        $value = trim((string) ($dataRow[$column] ?? ''));
                        if ($role === 'result' && $blockIsMultiCriterion) {
                            // Every equipment column gets its OWN answer for
                            // THIS criterion row, whether marked or blank -
                            // unlike property/note, silence here is itself
                            // meaningful (not confirmed compliant), so this
                            // does not skip on empty like the branch below.
                            // Fixed sub-case (AKTAŞ-style): interpret the mark
                            // against the column's own declared value. Dynamic
                            // sub-case (OKCO-style, real "Yangın Dolapları"
                            // report: 15 criteria rows, each cell independently
                            // U/UD/N per dolap column): no fixed reference
                            // exists, pass the raw per-cell code straight
                            // through - the downstream normalizer already
                            // recognizes these short codes.
                            $criteriaResults[$column][] = [
                                'code' => $criterionCode,
                                'criterion' => $criterionText,
                                'result' => $fixedValue !== null
                                    ? $this->interpretCriterionCell($value, $fixedValue)
                                    : ($value !== '' ? $this->cleanValue($value) : null),
                            ];
                            continue;
                        }
                        if ($value === '' || $value === '-') continue;
                        // A column can now hold MORE THAN ONE equipment entry
                        // (see the compressed-identity expansion above) - the
                        // SAME cell value applies to every expanded instance
                        // at this column, since a compressed cell means they
                        // all share the identical recorded answer.
                        if ($role === 'property') {
                            $key = $this->string($roleDef['key'] ?? null) ?? $rowLabel;
                            foreach ($items[$column] as &$instanceItem) {
                                $this->assignPropertyValue($instanceItem['properties'], $key, $this->patterns($roleDef['sub_keys'] ?? []), $value);
                            }
                            unset($instanceItem);
                        } elseif ($role === 'result') {
                            foreach ($items[$column] as &$instanceItem) {
                                if ($instanceItem['result'] === null) $instanceItem['result'] = $fixedValue ?? $this->cleanValue($value);
                            }
                            unset($instanceItem);
                        } elseif ($role === 'note') {
                            foreach ($items[$column] as &$instanceItem) {
                                if ($instanceItem['note'] === null) $instanceItem['note'] = $this->cleanValue($value);
                            }
                            unset($instanceItem);
                        }
                    }
                }

                foreach ($items as $column => $itemList) {
                    foreach ($itemList as $item) {
                        $item['direct_control_items'] = $criteriaResults[$column] ?? [];
                        if ($item['properties'] || $item['result'] !== null || $item['note'] !== null || $item['direct_control_items']) {
                            $allItems[] = $item;
                        }
                    }
                }
            }
        }

        return $allItems;
    }

private function extractControls(array $tables, array $controlTemplates, array $resultAxis = [], array $claimedCells = []): array
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

        $textPatterns = array_values(array_filter(
            array_map('strval', (array) ($control['control_text_patterns'] ?? []))
        ));

        $resultPatterns = array_values(array_filter(
            array_map('strval', (array) ($control['result_patterns'] ?? []))
        ));

        // A code can be printed fused into the SAME cell as its own question
        // text ("1. Portatif söndürücülerin ..."), with no cell holding the
        // bare code "1." on its own anywhere in the table - matchControlCode()
        // above never fires for these. control_text_patterns already carries
        // the real question text in the SAME order as control_code_patterns,
        // so find the cell by that real text (collapseForMatch: plain string
        // comparison, no regex) and take the code positionally rather than
        // trying to parse it back out of the cell.
        if ($textPatterns && $codePatterns) {
            foreach ($tables as $tableIndex => $table) {
                $resultColumns = $resultColumnsByTable[$tableIndex] ?? [];
                foreach ($this->matrix($table) as $rowIndex => $row) {
                    foreach ($row as $columnIndex => $value) {
                        if (isset($claimedCells[$tableIndex][$rowIndex][$columnIndex])) continue;
                        $textIndex = $this->matchControlText((string) $value, $textPatterns);
                        if ($textIndex === null || !isset($codePatterns[$textIndex])) continue;

                        $code = $this->displayCodeFromPattern($codePatterns[$textIndex]);
                        if ($code === '') continue;

                        $criterion = $this->stripLeadingCode((string) $value, $code);

                        $result = $resultColumns ? $this->resultFromColumnBlock($row, (int) $columnIndex, $resultColumns) : null;
                        for ($resultIndex = (int) $columnIndex + 1; $result === null && $resultIndex < count($row); $resultIndex++) {
                            $candidate = (string) ($row[$resultIndex] ?? '');
                            if ($this->matchControlText($candidate, $textPatterns) !== null) break;
                            $matched = $this->matchResultValue($candidate, $resultPatterns);
                            if ($matched !== null) { $result = $matched; break; }
                        }

                        $out[$this->normalizeCode($code)] = [
                            'code' => $code,
                            'criterion' => $criterion,
                            'result' => $result,
                            'source_pages' => array_values(array_unique(array_filter([(int) ($table['page'] ?? 0)]))),
                        ];
                    }
                }
            }
        }

        if (!$codePatterns) continue;

        foreach ($tables as $tableIndex => $table) {

            // EKLENDİ
            $cells = (array) ($table['cells'] ?? []);
            $resultColumns = $resultColumnsByTable[$tableIndex] ?? [];

            // rowIndex EKLENDİ
            foreach ($this->matrix($table) as $rowIndex => $row) {

                // columnIndex EKLENDİ
                foreach ($row as $columnIndex => $value) {
                    if (isset($claimedCells[$tableIndex][$rowIndex][$columnIndex])) continue;

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

    // Detects the ONE real header/instance-anchor row for an equipment
    // table_shape - deliberately NOT the same loose matchesAny() used for
    // per-cell role resolution elsewhere. matchesAny() wraps a declared
    // fragment as a live, unanchored regex search (see regexMatches()),
    // which is safe for a short SPECIFIC cell value but dangerous here: a
    // short declared header fragment like "U." or "No." would otherwise
    // match "U" + ANY character or "no" ANYWHERE inside a totally
    // unrelated, much longer sentence sitting elsewhere in the same PDF
    // (confirmed on a real report: a repeated page-header info block's
    // "KKD'lerin Kullanımı: ..." sentence contains "Ku" and got mistaken
    // for the "U." result column header; a signature block's "MMO /
    // DİPLOMA NO" line matched "No." the same way) - each occurrence
    // fabricated a fake "equipment" out of that unrelated row.
    // A genuine header row instead has MULTIPLE of the declared fragments
    // sitting as their OWN, EXACT (collapsed) cell content side by side -
    // an unrelated prose sentence never does. Requiring an EXACT per-cell
    // match (no substring containment) across at least two distinct
    // declared fragments (or the one available fragment, when only one was
    // declared) keeps this working for headers whose fragments only fill
    // SOME of the row's cells and leave the rest blank (real report: "No."/
    // "Lokasyon"/"U."/"U.D."/"N.U." each occupy their own cell while a
    // wrapped compound label like "Dolap No." spills onto a neighbouring
    // row instead), without reopening the loose-regex hole.
    private function isHeaderRow(array $row, array $headerPatterns): bool
    {
        if (!$headerPatterns) return false;

        $collapsedPatterns = [];
        foreach ($headerPatterns as $pattern) {
            $collapsed = $this->collapseForMatch((string) $pattern);
            if ($collapsed !== '') $collapsedPatterns[] = $collapsed;
        }
        if (!$collapsedPatterns) return false;

        $matchedPatterns = [];
        foreach ($row as $cellValue) {
            $collapsedCell = $this->collapseForMatch((string) $cellValue);
            if ($collapsedCell === '') continue;
            foreach ($collapsedPatterns as $index => $collapsedPattern) {
                if ($collapsedCell === $collapsedPattern) $matchedPatterns[$index] = true;
            }
        }

        return count($matchedPatterns) >= min(2, count($collapsedPatterns));
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

    // Finds which declared control_text_patterns entry a cell's real text
    // belongs to - exact match first, then one-directional "cell contains the
    // (shorter) declared question text" (a code-fused cell like "1. Portatif
    // söndürücülerin ..." is always longer than the bare declared question).
    // No preg_match anywhere in this path - same collapseForMatch()-based
    // plain string comparison as resolveColumnRole() uses for table_shape.
    private function matchControlText(string $cellValue, array $textPatterns): ?int
    {
        $collapsedCell = $this->collapseForMatch($cellValue);
        if ($collapsedCell === '') return null;

        foreach ($textPatterns as $index => $pattern) {
            if ($this->collapseForMatch($pattern) === $collapsedCell) return $index;
        }

        foreach ($textPatterns as $index => $pattern) {
            $collapsedPattern = $this->collapseForMatch($pattern);
            if ($collapsedPattern === '' || mb_strlen($collapsedPattern, 'UTF-8') > mb_strlen($collapsedCell, 'UTF-8')) continue;
            if (str_contains($collapsedCell, $collapsedPattern)) return $index;
        }

        return null;
    }

    // Turns a declared control_code_patterns entry (e.g. "^1\.$") into the
    // plain code it names ("1.") via fixed literal stripping only - no
    // pattern engine involved, the anchors/backslashes are just characters
    // this particular declaration style always wraps the code in.
    private function displayCodeFromPattern(string $pattern): string
    {
        $pattern = trim($pattern);
        $pattern = ltrim($pattern, '^');
        $pattern = rtrim($pattern, '$');
        $pattern = str_replace('\\', '', $pattern);
        return rtrim(trim($pattern), '.');
    }

    // A per-criterion checkbox column's cell can hold either a generic mark
    // (✔/✓, an X, anything with no meaning of its own beyond "present") OR
    // the real negative code/glyph written directly into it instead of being
    // left blank (e.g. "UD", "✘", "Uygun Değil") - never trust "non-empty" as
    // "the column's declared value applies". Blank -> not confirmed
    // compliant for THIS criterion ('uygun_degil'), same as an explicit
    // negative mark. Regex-free (plain str_contains), matching this file's
    // and FireSuppressionUnifiedNormalizer's established glyph/keyword set.
    private function interpretCriterionCell(string $cellText, string $fixedValue): string
    {
        if ($cellText === '' || $cellText === '-' || $cellText === '—') return 'uygun_degil';

        if (in_array($cellText, ['✘', '✗', '×'], true)) return 'uygun_degil';
        if (in_array($cellText, ['✔', '✓'], true)) return $fixedValue;

        $normalized = mb_strtolower($cellText, 'UTF-8');
        if (str_contains($normalized, 'değil') || str_contains($normalized, 'degil')) return 'uygun_degil';
        if ($normalized === 'ud' || $normalized === 'u.d.') return 'uygun_degil';
        if (str_contains($normalized, 'uygulanamıyor') || str_contains($normalized, 'uygulanamiyor')) return 'uygulanamiyor';

        return $fixedValue;
    }

    // Plain leading-digit scan (no regex) - "1- Yangın söndürme..." -> "1".
    // Returns null when the text doesn't start with a digit at all.
    // Also accepts a DOTTED multi-part code ("5.38 Hortumda..." -> "5.38",
    // not just "5") - a dot only extends the code when it sits BETWEEN two
    // digit groups; a trailing dot with no digit after it ("5. Kriter metni")
    // is ordinary punctuation, not part of the code, and is trimmed off.
    private function leadingNumber(string $text): ?string
    {
        $text = ltrim($text);
        $end = 0;
        $length = strlen($text);
        $sawDigit = false;
        while ($end < $length) {
            if (ctype_digit($text[$end])) { $end++; $sawDigit = true; continue; }
            if ($text[$end] === '.' && $sawDigit && $end + 1 < $length && ctype_digit($text[$end + 1])) { $end++; continue; }
            break;
        }
        $code = rtrim(substr($text, 0, $end), '.');
        return $code !== '' ? $code : null;
    }

    // Removes a code fused into the front of its own cell ("1. Portatif ..."
    // -> "Portatif ...") via plain substring/ltrim only, so the real question
    // text (not the code) becomes the stored criterion.
    private function stripLeadingCode(string $cellText, string $code): string
    {
        $cellText = trim($cellText);
        $code = trim($code);
        if ($code === '' || !str_starts_with($cellText, $code)) return $cellText;
        return ltrim(substr($cellText, strlen($code)), " .:)-");
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

    // "48-49-50-...-61" -> ['48','49','50',...,'61'] when EVERY hyphen-
    // separated part is a plain digit code (a real report can cram several
    // equipment codes into one identity cell this way when they all share
    // identical recorded properties/criteria answers, instead of widening
    // the table). Plain string split, no regex. Anything else (a single
    // code, a code that itself contains a hyphen like "A-12", an empty
    // part) is left as ONE unchanged identity - only an unambiguous, fully
    // numeric hyphen list expands.
    private function expandCompressedIdentity(string $identity): array
    {
        if (!str_contains($identity, '-')) return [$identity];

        $parts = array_map('trim', explode('-', $identity));
        if (count($parts) < 2) return [$identity];

        foreach ($parts as $part) {
            if ($part === '' || !ctype_digit($part)) return [$identity];
        }

        return $parts;
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
