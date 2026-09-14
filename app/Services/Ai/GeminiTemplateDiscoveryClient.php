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

        $controlItem = [
            'type' => 'object',
            'properties' => [
                'control_code_patterns' => $stringArray,
                'control_text_patterns' => $stringArray,
                'result_patterns' => $stringArray,
            ],
            'required' => ['control_code_patterns', 'control_text_patterns', 'result_patterns'],
        ];

        $equipment = [
            'type' => 'object',
            'properties' => [
                'equipment_name' => ['type' => 'string'],
                'system_name' => ['type' => 'string'],
                'equipment_identity' => [
                    'type' => 'object',
                    'properties' => [
                        'header_patterns' => $stringArray,
                        'identity_patterns' => $stringArray,
                    ],
                    'required' => ['header_patterns', 'identity_patterns'],
                ],
                'table_structure' => [
                    'type' => 'object',
                    'properties' => [
                        'orientation' => ['type' => 'string'],
                        'repeating_block' => ['type' => 'boolean'],
                        'left_column' => [
                            'type' => 'object',
                            'properties' => [
                                'header_patterns' => $stringArray,
                                'label_patterns' => $stringArray,
                                'cell_patterns' => $stringArray,
                            ],
                            'required' => ['header_patterns', 'label_patterns', 'cell_patterns'],
                        ],
                        'right_column' => [
                            'type' => 'object',
                            'properties' => [
                                'header_patterns' => $stringArray,
                                'value_patterns' => $stringArray,
                                'cell_patterns' => $stringArray,
                            ],
                            'required' => ['header_patterns', 'value_patterns', 'cell_patterns'],
                        ],
                    ],
                    'required' => ['orientation', 'repeating_block', 'left_column', 'right_column'],
                ],
                'camelot_extraction' => [
                    'type' => 'object',
                    'properties' => [
                        'equipment_header_patterns' => $stringArray,
                        'system_section_patterns' => $stringArray,
                        'left_column_patterns' => $stringArray,
                        'right_column_patterns' => $stringArray,
                        'value_location' => ['type' => 'string'],
                        'block_detection' => ['type' => 'string'],
                        'scope' => ['type' => 'string'],
                    ],
                    'required' => ['equipment_header_patterns', 'system_section_patterns', 'left_column_patterns', 'right_column_patterns', 'value_location', 'block_detection', 'scope'],
                ],
            ],
            'required' => ['equipment_name', 'system_name', 'equipment_identity', 'table_structure', 'camelot_extraction'],
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
                    ],
                    'required' => ['findings'],
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
