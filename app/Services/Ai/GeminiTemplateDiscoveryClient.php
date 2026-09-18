<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Gemini client dedicated to report template discovery. */
class GeminiTemplateDiscoveryClient
{
    public function extract(string $systemPrompt, string $userContent, int $maxTokens = 50000): array
    {
        $apiKey = config('services.gemini.api_key');
        if (!filled($apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY tanımlı değil.');
        }

        $url = rtrim((string) config('services.gemini.base_url'), '/') . '/interactions';
        $payload = [
            'model' => config('services.gemini.text_model'),
            'system_instruction' => $systemPrompt,
            'input' => $userContent,
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $this->schema(),
            ],
            'generation_config' => [
                'temperature' => 0.1,
                'thinking_level' => 'minimal',
                'max_output_tokens' => max($maxTokens, 16000),
            ],
            'store' => false,
        ];

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->connectTimeout(10)->timeout(180)->post($url, $payload);
        } catch (\Throwable $exception) {
            Log::error('Gemini Template Discovery bağlantı hatası', [
                'duration_s' => round(microtime(true) - $startedAt, 1),
                'message' => $exception->getMessage(),
            ]);
            throw new RuntimeException('Gemini Template Discovery isteği başarısız oldu: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('Gemini Template Discovery başarısız oldu (HTTP ' . $response->status() . '): ' . $response->body());
        }

        $content = $this->extractOutputText($response->json());
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Gemini Template Discovery çıktısı geçerli JSON değil.');
        }

        Log::info('Gemini Template Discovery tamamlandı', [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'model' => config('services.gemini.text_model'),
            'output_length' => mb_strlen($content),
        ]);

        return $decoded;
    }

    private function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];
        $integerArray = ['type' => 'array', 'items' => ['type' => 'integer']];

        $reportField = [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string'],
                'label_patterns' => $stringArray,
            ],
            'required' => ['key', 'label_patterns'],
        ];

        $facilityField = [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string'],
                'label_patterns' => $stringArray,
            ],
            'required' => ['key', 'label_patterns'],
        ];

        // extracted_data.report_information / .facility_information: 'key'
        // must be one of the keys already declared in the matching
        // template.*.fields[].key list; 'value' is the REAL text read off
        // the page for that field (null if genuinely absent/illegible).
        $extractedField = [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string'],
                'value' => $nullableString,
            ],
            'required' => ['key', 'value'],
        ];

        $controlItem = [
            'type' => 'object',
            'properties' => [
                'control_code_patterns' => $stringArray,
                'control_text_patterns' => $stringArray,
                'result_patterns' => $stringArray,
            ],
            'required' => ['control_code_patterns', 'control_text_patterns', 'result_patterns'],
        ];

        // A single, closed vocabulary for "what role does this column/row
        // play" - unlike table_structure.orientation / camelot_extraction.
        // block_detection below (plain free-text strings), Gemini has used
        // DIFFERENT wording for the SAME underlying shape across different
        // reports ("table_grid" vs "repeating_horizontal_matrix" vs
        // "table_rows" all meaning "equipment repeats"), which made those
        // fields unusable as a dispatch key. `enum` here constrains Gemini to
        // these exact 4 role names, so ONE generic parser can place cells
        // correctly regardless of the report's own layout - Camelot still
        // does all the actual cell reading, Gemini only describes the shape,
        // so its own JSON output stays small even for a 300-row equipment
        // table (it never lists the equipment itself, only the column plan).
        $tableShapeColumn = [
            'type' => 'object',
            'properties' => [
                'role' => ['type' => 'string', 'enum' => ['identity', 'property', 'result', 'note']],
                // The AUTHORITATIVE way to find this column: its 0-based
                // physical position in the header row, counting every column
                // left to right (first column = 0). Short header labels like
                // "U.", "U.D.", "N.U." are text-substrings of each other
                // (matching "U." against a "U.D." cell would wrongly succeed)
                // - position never has that ambiguity, so the parser locates
                // columns by this index FIRST and only falls back to
                // header_patterns text search when column_index is missing
                // (older captures).
                'column_index' => ['type' => 'integer'],
                'header_patterns' => $stringArray,
                // property: the key this value should be stored under.
                // result: which normalized status this column/row stands for.
                // identity/note: leave both null.
                'key' => ['type' => ['string', 'null']],
                // result role only:
                // - one of the 3 fixed values: this column is its OWN
                //   dedicated status (checkbox-style, e.g. separate "U." /
                //   "U.D." / "N.U." columns - a mark anywhere in THIS column
                //   means that fixed status).
                // - null: this is the ONLY result column and its cell TEXT
                //   itself varies per row (e.g. a single "Durum" column
                //   containing the literal text "U" / "UD" / "N" per row) -
                //   the parser reads the raw cell text per row instead of a
                //   fixed value; the existing result-normalizer already
                //   recognizes these short codes.
                'value' => ['type' => ['string', 'null'], 'enum' => ['uygun', 'uygun_degil', 'uygulanamiyor', null]],
                // property only: some reports pack SEVERAL distinct properties
                // into one compound column header (e.g. "Dolap Bilgileri
                // (Makarası - Tipi - Makara Bağlantısı - Vana Tipi)" - one
                // header, one cell per row, but 4 real properties separated by
                // dashes). List the sub-property names here, in the SAME order
                // they appear in the header text, and the generic parser splits
                // each row's cell by its dash/newline separators into that many
                // parts. Leave empty when the column is a single plain
                // property (the common case).
                'sub_keys' => $stringArray,
            ],
            'required' => ['role', 'column_index', 'header_patterns', 'key', 'value', 'sub_keys'],
        ];
        $tableShape = [
            'type' => 'object',
            'properties' => [
                // rows: one equipment instance per table ROW, named columns.
                // columns: one equipment instance per table COLUMN (a shared
                //   header row lists the instances, e.g. several Dolap side by
                //   side), named rows.
                // separate_blocks: each instance is its OWN small lattice
                //   table (e.g. one pump's own label:value grid), not a
                //   shared table at all.
                // none: exactly one piece of equipment, no repetition.
                'instance_axis' => ['type' => 'string', 'enum' => ['rows', 'columns', 'separate_blocks', 'none']],
                'header_row_patterns' => $stringArray,
                'columns' => ['type' => 'array', 'items' => $tableShapeColumn],
            ],
            'required' => ['instance_axis', 'header_row_patterns', 'columns'],
        ];

        // equipment_identity / table_structure / camelot_extraction (the
        // pre-table_shape pattern-guessing fields) were removed once
        // table_shape (rows/none axis) and extracted_data.equipment
        // (direct-read, for small/idiosyncratic groups) together proved
        // sufficient for every report format tested - keeping them around
        // only cost Gemini output tokens for fields nothing reads anymore.
        $equipment = [
            'type' => 'object',
            'properties' => [
                'equipment_name' => ['type' => 'string'],
                'system_name' => ['type' => 'string'],
                'table_shape' => $tableShape,
            ],
            'required' => ['equipment_name', 'system_name', 'table_shape'],
        ];

        $matrix = [
            'type' => 'object',
            'properties' => [
                'present' => ['type' => 'boolean'],
                'orientation' => ['type' => 'string'],
                'axis_detection' => [
                    'type' => 'object',
                    'properties' => [
                        'enabled' => ['type' => 'boolean'],
                        'equipment_axis' => [
                            'type' => 'object',
                            'properties' => [
                                'axis' => ['type' => 'string'],
                                'header_patterns' => $stringArray,
                                'identity_patterns' => $stringArray,
                                'detection_patterns' => $stringArray,
                            ],
                            'required' => ['axis', 'header_patterns', 'identity_patterns', 'detection_patterns'],
                        ],
                        'control_axis' => [
                            'type' => 'object',
                            'properties' => [
                                'axis' => ['type' => 'string'],
                                'header_patterns' => $stringArray,
                                'code_patterns' => $stringArray,
                                'label_patterns' => $stringArray,
                                'detection_patterns' => $stringArray,
                            ],
                            'required' => ['axis', 'header_patterns', 'code_patterns', 'label_patterns', 'detection_patterns'],
                        ],
                        'result_axis' => [
                            'type' => 'object',
                            'properties' => [
                                'location' => ['type' => 'string'],
                                'patterns' => $stringArray,
                                'detection_patterns' => $stringArray,
                            ],
                            'required' => ['location', 'patterns', 'detection_patterns'],
                        ],
                    ],
                    'required' => ['enabled', 'equipment_axis', 'control_axis', 'result_axis'],
                ],
                'matrix_relationship' => [
                    'type' => 'object',
                    'properties' => [
                        'equipment_vs_control' => ['type' => 'string'],
                        'equipment_position' => ['type' => 'string'],
                        'control_position' => ['type' => 'string'],
                        'result_position' => ['type' => 'string'],
                    ],
                    'required' => ['equipment_vs_control', 'equipment_position', 'control_position', 'result_position'],
                ],
                'camelot_extraction' => [
                    'type' => 'object',
                    'properties' => [
                        'table_type' => ['type' => 'string'],
                        'system_section_patterns' => $stringArray,
                        'equipment_header_patterns' => $stringArray,
                        'equipment_identity_patterns' => $stringArray,
                        'control_code_patterns' => $stringArray,
                        'control_label_patterns' => $stringArray,
                        'result_cell_patterns' => $stringArray,
                        'equipment_axis' => ['type' => 'string'],
                        'control_axis' => ['type' => 'string'],
                        'result_binding' => ['type' => 'string'],
                    ],
                    'required' => ['table_type', 'system_section_patterns', 'equipment_header_patterns', 'equipment_identity_patterns', 'control_code_patterns', 'control_label_patterns', 'result_cell_patterns', 'equipment_axis', 'control_axis', 'result_binding'],
                ],
            ],
            'required' => ['present', 'orientation', 'axis_detection', 'matrix_relationship', 'camelot_extraction'],
        ];

        $system = [
            'type' => 'object',
            'properties' => [
                'system_name' => ['type' => 'string'],
                'section_heading_patterns' => $stringArray,
                'control_items' => ['type' => 'array', 'items' => $controlItem],
                'equipment' => ['type' => 'array', 'items' => $equipment],
                'control_matrix' => $matrix,
                'section_detection' => [
                    'type' => 'object',
                    'properties' => [
                        'start_heading_patterns' => $stringArray,
                        'continuation_patterns' => $stringArray,
                        'end_detection_patterns' => $stringArray,
                    ],
                    'required' => ['start_heading_patterns', 'continuation_patterns', 'end_detection_patterns'],
                ],
            ],
            'required' => ['system_name', 'section_heading_patterns', 'control_items', 'equipment', 'control_matrix', 'section_detection'],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'template' => [
                    'type' => 'object',
                    'properties' => [
                        'template_type' => ['type' => 'string'],
                        'template_version' => ['type' => 'string'],
                        'report_information' => [
                            'type' => 'object',
                            'properties' => [
                                'fields' => ['type' => 'array', 'items' => $reportField],
                            ],
                            'required' => ['fields'],
                        ],
                        'facility_or_project_information' => [
                            'type' => 'object',
                            'properties' => [
                                'section_heading_patterns' => $stringArray,
                                'fields' => ['type' => 'array', 'items' => $facilityField],
                            ],
                            'required' => ['section_heading_patterns', 'fields'],
                        ],
                        'fire_systems' => [
                            'type' => 'object',
                            'properties' => [
                                'systems' => ['type' => 'array', 'items' => $system],
                            ],
                            'required' => ['systems'],
                        ],
                        'overall_result' => [
                            'type' => 'object',
                            'properties' => [
                                'section_heading_patterns' => $stringArray,
                                'overall_text' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'label_patterns' => $stringArray,
                                        'value_location_patterns' => $stringArray,
                                        'text_boundary_patterns' => $stringArray,
                                    ],
                                    'required' => ['label_patterns', 'value_location_patterns', 'text_boundary_patterns'],
                                ],
                                'overall_status' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'label_patterns' => $stringArray,
                                        'status_patterns' => $stringArray,
                                        'value_location_patterns' => $stringArray,
                                    ],
                                    'required' => ['label_patterns', 'status_patterns', 'value_location_patterns'],
                                ],
                                'camelot_extraction' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'section_patterns' => $stringArray,
                                        'text_patterns' => $stringArray,
                                        'status_patterns' => $stringArray,
                                        'status_extraction' => ['type' => 'string'],
                                    ],
                                    'required' => ['section_patterns', 'text_patterns', 'status_patterns', 'status_extraction'],
                                ],
                            ],
                            'required' => ['section_heading_patterns', 'overall_text', 'overall_status', 'camelot_extraction'],
                        ],
                        'findings_structure' => [
                            'type' => 'object',
                            'properties' => [
                                'system_assignment' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'required' => ['type' => 'boolean'],
                                        'source' => $stringArray,
                                        'fallback' => $nullableString,
                                    ],
                                    'required' => ['required', 'source', 'fallback'],
                                ],
                                'deduplication' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'enabled' => ['type' => 'boolean'],
                                        'duplicate_finding_rule' => ['type' => 'string'],
                                    ],
                                    'required' => ['enabled', 'duplicate_finding_rule'],
                                ],
                                'finding_fields' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'key' => ['type' => 'string'],
                                            'required' => ['type' => 'boolean'],
                                            'nullable' => ['type' => 'boolean'],
                                        ],
                                        'required' => ['key', 'required', 'nullable'],
                                    ],
                                ],
                            ],
                            'required' => ['system_assignment', 'deduplication', 'finding_fields'],
                        ],
                    ],
                    'required' => ['template_type', 'template_version', 'report_information', 'facility_or_project_information', 'fire_systems', 'overall_result', 'findings_structure'],
                ],
                'extracted_data' => [
                    'type' => 'object',
                    'properties' => [
                        'findings' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'system_name' => $nullableString,
                                    'description' => ['type' => 'string'],
                                    'source_pages' => $integerArray,
                                ],
                                'required' => ['id', 'system_name', 'description', 'source_pages'],
                            ],
                        ],
                        // Report/company/facility info is always a handful of
                        // fixed fields regardless of report length (never grows
                        // with row count the way equipment/control_items can) -
                        // safe for Gemini to read and report the REAL value
                        // directly, instead of only a pattern for Camelot to
                        // chase through an ambiguous grid (label/value column
                        // adjacency has proven unreliable there). 'key' must
                        // match one of the keys already declared in
                        // template.report_information.fields / .facility_or_
                        // project_information.fields.
                        'report_information' => [
                            'type' => 'array',
                            'items' => $extractedField,
                        ],
                        'facility_information' => [
                            'type' => 'array',
                            'items' => $extractedField,
                        ],
                        // Same reasoning as report_information above: the
                        // final verdict is always one short paragraph + one
                        // status word, never grows with report length - read
                        // it directly instead of a label/status pattern chase
                        // through the conclusion table (which has repeatedly
                        // broken on section-heading variance, e.g. "SONUÇ:"
                        // vs "SONUÇ VE KANAAT"). 'status' uses the SAME closed
                        // 3-value vocabulary as table_shape's result role.
                        'overall_result' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => $nullableString,
                                'status' => ['type' => ['string', 'null'], 'enum' => ['uygun', 'uygun_degil', 'uygulanamiyor', null]],
                            ],
                            'required' => ['text', 'status'],
                        ],
                        // Direct read for a SMALL, boundedly-countable equipment
                        // group (e.g. a pump room with 2-4 pumps) - Gemini
                        // actually looks at the table and reports the real
                        // values, the same trust level as report_information
                        // above. This is NOT for dolap/hidrant-style groups
                        // that can run into the hundreds - those MUST stay
                        // structure-only via equipment[].table_shape, or
                        // Gemini's own output stops being small regardless of
                        // report length. Use this specifically when a table's
                        // layout is too idiosyncratic for a generic structural
                        // rule to generalize (e.g. a "proje değeri / uygulama
                        // değeri" split column where only one sub-value is
                        // real) - something an AI reading the page can resolve
                        // instantly but no fixed column-role scheme can.
                        'equipment' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'system_name' => ['type' => 'string'],
                                    'equipment_name' => ['type' => 'string'],
                                    'code' => $nullableString,
                                    'properties' => ['type' => 'array', 'items' => $extractedField],
                                    'result' => ['type' => ['string', 'null'], 'enum' => ['uygun', 'uygun_degil', 'uygulanamiyor', null]],
                                    'source_pages' => $integerArray,
                                ],
                                'required' => ['system_name', 'equipment_name', 'code', 'properties', 'result', 'source_pages'],
                            ],
                        ],
                    ],
                    'required' => ['findings', 'report_information', 'facility_information', 'overall_result', 'equipment'],
                ],
            ],
            'required' => ['template', 'extracted_data'],
        ];
    }

    private function extractOutputText(array $response): string
    {
        foreach ((array) ($response['steps'] ?? []) as $step) {
            if (!is_array($step) || ($step['type'] ?? null) !== 'model_output') {
                continue;
            }

            foreach ((array) ($step['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text'])) {
                    return (string) $content['text'];
                }
            }
        }

        throw new RuntimeException('Gemini yanıtında model çıktısı bulunamadı.');
    }
}
