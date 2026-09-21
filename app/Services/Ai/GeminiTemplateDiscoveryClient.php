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

        // Raw text as literally seen in the PDF cell (e.g. "U", "UD",
        // "UYGUN DEĞİL") PLUS Gemini's own best-effort human label - but
        // 'raw' is the authoritative one. PHP already has a proven,
        // battle-tested normalizer that maps many different real-world
        // symbol sets (U/UD/N, U./U.D./N.U., U/U.D/U.Y/G, ✔/✘...) to the
        // same 3 outcomes using plain text comparison (no regex) - Gemini
        // does NOT need to (and must NOT try to) normalize the value
        // itself, just copy the cell's real text faithfully. This also
        // means a genuine Gemini misreading only breaks 'label', never
        // silently reinterprets 'raw' into the wrong outcome.
        $resultValue = [
            'type' => 'object',
            'properties' => [
                'raw' => $nullableString,
                'label' => $nullableString,
            ],
            'required' => ['raw', 'label'],
        ];

        // Structure only for the UNLIMITED case (equipment_axis=rows/
        // columns): 'code'/'text' are the criterion's own real, fixed
        // identity (e.g. "5.38" / "Hortumda TSE standardı varlığı") - safe
        // to read once since it does NOT repeat once per equipment instance
        // the way a result cell does. 'result' stays {raw: null, label:
        // null} in that case - with potentially hundreds of equipment
        // instances, each one's OWN answer to this SAME criterion differs
        // (real example: 20 different "Yangın Dolabı" instances, each its
        // own U/UD/N for criterion "5.38" - there is no single value that
        // could go here), so Camelot reads every real per-instance answer
        // instead. ONLY when equipment_axis="none" (exactly one instance,
        // no repetition) does a single real answer genuinely exist - fill
        // 'result' with it directly then, exactly like system_criteria.
        $criterionDef = [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
                'text' => ['type' => 'string'],
                'result' => $resultValue,
            ],
            'required' => ['code', 'text', 'result'],
        ];

        // One real property definition - 'field' is the property's own name
        // (as it will be stored), 'source_pattern' is the EXACT label text
        // Gemini saw for it in the PDF (e.g. "Kat", "Marka"), used to find
        // that row/column by plain text match (no coordinates, no regex).
        // 'value' follows the SAME rule as equipment_control_criteria's
        // result above: stays null when equipment_axis="rows"/"columns"
        // (many instances, each with its OWN real value - Camelot reads
        // them), only filled with the real text when equipment_axis="none"
        // (exactly one instance, one real value genuinely exists).
        $attributeDef = [
            'type' => 'object',
            'properties' => [
                'field' => ['type' => 'string'],
                'source_pattern' => ['type' => 'string'],
                'value' => $nullableString,
            ],
            'required' => ['field', 'source_pattern', 'value'],
        ];

        // Replaces the old table_shape.columns role list. Two real,
        // observed shapes:
        // - equipment_axis="rows": one equipment instance per table ROW,
        //   properties/criteria are named COLUMNS (e.g. a "No. | Dolap No.
        //   | Lokasyon | ... | U. | U.D. | N.U." header row, one dolap per
        //   row below it).
        // - equipment_axis="columns": one equipment instance per table
        //   COLUMN - several equipment side by side (e.g. "No / Kod | YD1 |
        //   YD2 | ... | YD10"), each property/criterion its OWN row below,
        //   applying across every instance column at once. Real reports
        //   repeat this in fixed-width BLOCKS (e.g. 5 or 10 equipment per
        //   block, a fresh block starting every N columns/pages) -
        //   group_width is that fixed width, so the parser knows where one
        //   block ends and the next begins without guessing.
        // - equipment_axis="none": exactly one instance, no repetition.
        //
        // A per-instance table often has columns that are NOT ordinary
        // properties and NOT independent criteria either - two specific,
        // recurring shapes:
        // - A checkbox-style outcome split across 2-3 columns that together
        //   express ONE judgment (classic "U. | U.D. | N.U." triplet - which
        //   column has the mark IS the result, the other two are blank).
        //   Declaring these explicitly (kind="fixed_value", value=the
        //   normalized outcome that column's mark means) collapses them into
        //   ONE result per instance instead of 3 separate criteria.
        // - A free-text remarks/açıklama column (e.g. "AÇIKLAMALAR") holding
        //   a human note, not a pass/fail judgment. Declaring it
        //   (kind="note") keeps that text out of the criteria list.
        // Leave result_columns=[] for the (equally real and common) opposite
        // case - N columns that are genuinely N separate/independent
        // criteria (e.g. a per-tüp 7-question matrix) - the parser already
        // reads every undeclared column as its own dynamic criterion, so
        // nothing needs to be declared there.
        $resultColumnDef = [
            'type' => 'object',
            'properties' => [
                'header_pattern' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['note', 'fixed_value']],
                // Required (non-null) only when kind="fixed_value": the
                // normalized outcome a MARK in this column means, e.g.
                // "uygun" / "uygun_degil" / "uygulanamiyor". Always null when
                // kind="note".
                'value' => $nullableString,
            ],
            'required' => ['header_pattern', 'kind', 'value'],
        ];

        $instanceStructure = [
            'type' => 'object',
            'properties' => [
                // The row/column label that identifies EACH instance (e.g.
                // "Dolap No.", "No / Kod", "Tüp No"). This is the ONE
                // required anchor - the parser locates the real table by
                // finding this exact label in Camelot's cells.
                'identity_field' => ['type' => 'string'],
                // The real identity VALUE (e.g. an actual serial number) -
                // stays null when equipment_axis="rows"/"columns" (many
                // instances, Camelot reads each real code). Only filled when
                // equipment_axis="none" AND this one instance genuinely has
                // its own code/serial visible in the PDF.
                'identity_value' => $nullableString,
                // OPTIONAL sanity-check hint, e.g. pattern "YD*" when every
                // real code you saw started with "YD". validation is always
                // "soft": this NEVER filters or rejects a real value that
                // doesn't match - Camelot reads whatever text is actually in
                // the identity cell regardless, this is a hint for review
                // only. pattern null when codes don't share an obvious shape.
                'identity_hint' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => $nullableString,
                        'validation' => ['type' => 'string', 'enum' => ['soft']],
                    ],
                    'required' => ['pattern', 'validation'],
                ],
                'equipment_axis' => ['type' => 'string', 'enum' => ['rows', 'columns', 'none']],
                // ONLY meaningful when equipment_axis="columns" - how many
                // equipment columns make up ONE repeating block (e.g. 5, 10).
                // null for "rows"/"none" (rows simply continue until the
                // table ends, no fixed block width applies there).
                'group_width' => ['type' => ['integer', 'null']],
                // The row/column text that marks where a NEW block/table
                // starts (e.g. ["No / Kod"], or ["Soru / Kriter", "Dolap
                // No"] when the block title and the identity label are two
                // separate lines). Include every distinct label you saw
                // serving this purpose.
                'header_patterns' => $stringArray,
                // Explicit note/fixed-outcome column declarations - see the
                // comment above this array's definition. [] when every
                // result-bearing column here is a genuinely independent
                // criterion (the normal, more common case).
                'result_columns' => ['type' => 'array', 'items' => $resultColumnDef],
                // true when you could not confidently determine the axis,
                // the identity field, or where instances start/end for this
                // table - Camelot/PHP will not guess past this point, so an
                // honest "I could not tell" (with ambiguous_reason
                // explaining what was unclear) is far more useful than a
                // wrong guess.
                'ambiguous' => ['type' => 'boolean'],
                'ambiguous_reason' => $nullableString,
            ],
            'required' => ['identity_field', 'identity_value', 'identity_hint', 'equipment_axis', 'group_width', 'header_patterns', 'result_columns', 'ambiguous', 'ambiguous_reason'],
        ];

        $equipmentDefinition = [
            'type' => 'object',
            'properties' => [
                'equipment_name' => ['type' => 'string'],
                'instance_structure' => $instanceStructure,
                // Real, fixed-per-report properties (Kat, Marka, Uzunluk...)
                // - NOT the criteria/results (those go in
                // equipment_control_criteria below). Compound headers that
                // pack several properties into one column/row separated by
                // dashes (e.g. "Dolap Bilgileri (Makarası - Tipi - Makara
                // Bağlantısı - Vana Tipi)") should be split into that many
                // separate attribute entries here, one per real sub-property,
                // in the order they appear - never left as one blob.
                'attributes' => ['type' => 'array', 'items' => $attributeDef],
                'equipment_control_criteria' => [
                    'type' => 'object',
                    'properties' => [
                        // false when this equipment group is pure inventory
                        // with no per-instance pass/fail criteria at all
                        // (a real observed case: a "Dolap No / Marka /
                        // Bulunduğu Yer / Basınç / Uzunluk" list with NO
                        // result column anywhere) - criteria stays [].
                        'present' => ['type' => 'boolean'],
                        // List every criterion you can actually see labeled
                        // in the table (its own code + text), but you do NOT
                        // need to count them precisely or worry about
                        // missing a few in a very long list - the parser
                        // treats any remaining unlabeled row/column within
                        // the same block as an additional criterion
                        // automatically. What matters is not leaving this
                        // empty when criteria clearly exist.
                        'criteria' => ['type' => 'array', 'items' => $criterionDef],
                    ],
                    'required' => ['present', 'criteria'],
                ],
            ],
            'required' => ['equipment_name', 'instance_structure', 'attributes', 'equipment_control_criteria'],
        ];

        // Sistem seviyeli (bir ekipmana bağlı OLMAYAN) kontrol kriterleri -
        // örn. bir "Genel Tespit" veya "Belge ve Kayıt Kontrolleri"
        // bölümünün 10-40 arası maddesi. Bu liste HER raporda sabit/sınırlı
        // boyutludur (rapor uzunluğuyla BÜYÜMEZ - ekipman sayısı büyüyebilir
        // ama sistem başına kriter sayısı büyümez), bu yüzden GERÇEK
        // değerlerle (result dahil) okunur - pattern sözleşmesine ihtiyaç
        // yoktur. Ekipmana bağlı kriterler BURAYA YAZILMAZ (onlar
        // equipment_definitions[].equipment_control_criteria'nın YAPISINI
        // tarif eder, gerçek sonuçları Camelot okur).
        $systemCriterion = [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
                'text' => ['type' => 'string'],
                'result' => $resultValue,
            ],
            'required' => ['code', 'text', 'result'],
        ];

        $system = [
            'type' => 'object',
            'properties' => [
                'system_name' => ['type' => 'string'],
                'section_heading_patterns' => $stringArray,
                'section_detection' => [
                    'type' => 'object',
                    'properties' => [
                        'start_heading_patterns' => $stringArray,
                        'continuation_patterns' => $stringArray,
                        'end_detection_patterns' => $stringArray,
                    ],
                    'required' => ['start_heading_patterns', 'continuation_patterns', 'end_detection_patterns'],
                ],
                'system_criteria' => ['type' => 'array', 'items' => $systemCriterion],
                // equipment_axis="none" entries here ALSO cover what used to
                // be a separate "direct read" list (a small, idiosyncratic
                // equipment group like 2-4 pumps, or a single-equipment
                // report) - same structure either way, differentiated only
                // by whether a real value or null sits in each result/value
                // field (see equipmentDefinition/instanceStructure/
                // attributeDef/criterionDef comments above).
                'equipment_definitions' => ['type' => 'array', 'items' => $equipmentDefinition],
            ],
            'required' => ['system_name', 'section_heading_patterns', 'section_detection', 'system_criteria', 'equipment_definitions'],
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
                        // Which of the 4 known report shapes this PDF actually
                        // is - decided ONCE, up front, so the save step (not
                        // this extraction schema) can route the SAME
                        // extracted data to the right target system without
                        // the user picking a separate upload screen per type:
                        //  - tekli_ekipman: ONE piece of equipment, whole
                        //    report is its own inspection (forklift,
                        //    transpalet, vinç, basınçlı kap...).
                        //  - ysc: taşınabilir yangın söndürücü (tüp) report -
                        //    can run into the hundreds of tüp, always needs
                        //    table_shape (never a flat AI-read list).
                        //  - yangin_tesisati: fire-suppression INSTALLATION
                        //    report with multiple systems (dolap, pompa,
                        //    hidrant, sprinkler, gazlı söndürme...).
                        //  - yangin_algilama: fire detection/alarm system -
                        //    its own category, never folded into
                        //    yangin_tesisati even though it can also use
                        //    table_shape/control_items.
                        'report_category' => [
                            'type' => 'string',
                            'enum' => ['tekli_ekipman', 'ysc', 'yangin_tesisati', 'yangin_algilama'],
                        ],
                        // How this WHOLE report should be read, decided ONCE:
                        //  - "structured": a mixed/tesisat-style report with
                        //    real repeating dolap/tüp/pompa/hidrant tables -
                        //    Gemini describes STRUCTURE only (equipment_axis=
                        //    "rows"/"columns"), Camelot reads the real
                        //    per-instance cells. The default, and by far the
                        //    more common case.
                        //  - "single_equipment": the WHOLE report is about
                        //    ONE real piece of equipment (forklift/
                        //    transpalet/vinç/basınçlı kap/tek bir tank...),
                        //    with no repeating equipment table at all. Gemini
                        //    reads EVERYTHING directly with real values -
                        //    every equipment_definitions entry here uses
                        //    equipment_axis="none" (see instance_structure),
                        //    and every system_criteria/finding is the real
                        //    PDF text/result, exactly like report_information
                        //    already works. Camelot is not required to find
                        //    any bordered table at all in this mode - it only
                        //    still runs if the report ALSO happens to contain
                        //    a large/mixed table somewhere (rare).
                        'extraction_mode' => ['type' => 'string', 'enum' => ['structured', 'single_equipment']],
                        'findings' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'system_name' => $nullableString,
                                    'description' => ['type' => 'string'],
                                    'source_pages' => $integerArray,
                                    // How serious this finding is, ONLY when
                                    // the PDF itself marks it that way (e.g.
                                    // a "(*)" işareti meaning "majör
                                    // uygunsuzluk" next to some items) - null
                                    // when the report doesn't distinguish.
                                    'severity' => $nullableString,
                                    // true when this finding's own system/
                                    // scope could not be confidently
                                    // determined from the PDF - never guess a
                                    // system_name just to fill the field.
                                    'ambiguous' => ['type' => 'boolean'],
                                ],
                                'required' => ['id', 'system_name', 'description', 'source_pages', 'severity', 'ambiguous'],
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
                        // Sistem seviyeli kriterler (system_criteria) VE
                        // ekipman yapı tarifi (equipment_definitions) artık
                        // BURADA DEĞİL - template.fire_systems.systems[]
                        // içine taşındı (her ikisi de zaten sistem başına
                        // tanımlanıyordu, tek bir yerde tutmak "aynı sistemin
                        // yapısı ile gerçek değerlerini iki ayrı JSON
                        // dalında senkron tutma" riskini ortadan kaldırıyor).
                        //
                        // Her raporun kendi sembol/kısaltma lejantı olabilir
                        // (U/UD/N, U./U.D./N.U., U/U.D/U.Y/G gibi farklı
                        // setler) - PDF'de GERÇEKTEN yazan lejant cümlesini
                        // (genelde tablonun hemen üstünde/altında, örn. "U:
                        // Uygun; UD: Uygun Değil; N: Uygulaması Yok") bul ve
                        // buraya kopyala. Lejant cümlesi yoksa boş dizi
                        // bırak - kendi tahmininle uydurma.
                        'result_legend' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'code' => ['type' => 'string'],
                                    'meaning' => ['type' => 'string'],
                                ],
                                'required' => ['code', 'meaning'],
                            ],
                        ],
                    ],
                    'required' => ['report_category', 'extraction_mode', 'findings', 'report_information', 'facility_information', 'overall_result', 'result_legend'],
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
