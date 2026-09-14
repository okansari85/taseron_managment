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
        $dynamicObject = ['type' => 'object', 'additionalProperties' => true];

        return [
            'type' => 'object',
            'properties' => [
                'template' => [
                    'type' => 'object',
                    'properties' => [
                        'template_type' => ['type' => 'string'],
                        'template_version' => ['type' => 'string'],
                        'report_structure' => [
                            'type' => 'object',
                            'properties' => [
                                'page_scope' => ['type' => 'string'],
                                'section_count' => ['type' => 'integer'],
                                'sections' => ['type' => 'array', 'items' => $dynamicObject],
                                'table_continuation_across_pages' => ['type' => 'boolean'],
                                'repeating_tables_across_pages' => ['type' => 'boolean'],
                            ],
                            'required' => ['page_scope', 'section_count', 'sections', 'table_continuation_across_pages', 'repeating_tables_across_pages'],
                        ],
                        'report_information' => [
                            'type' => 'object',
                            'properties' => [
                                'discovery' => ['type' => 'string'],
                                'fields' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'field' => ['type' => 'string'],
                                            'section' => ['type' => 'string'],
                                            'label_patterns' => $stringArray,
                                            'value_position' => ['type' => 'string'],
                                            'table_or_text' => ['type' => 'string'],
                                            'page_hints' => $integerArray,
                                        ],
                                        'required' => ['field', 'section', 'label_patterns', 'value_position', 'table_or_text', 'page_hints'],
                                    ],
                                ],
                            ],
                            'required' => ['discovery', 'fields'],
                        ],
                        'organization_information' => [
                            'type' => 'object',
                            'properties' => [
                                'discovery' => ['type' => 'string'],
                                'section' => ['type' => 'string'],
                                'fields' => ['type' => 'array', 'items' => $dynamicObject],
                            ],
                            'required' => ['discovery', 'section', 'fields'],
                        ],
                        'systems_structure' => [
                            'type' => 'object',
                            'properties' => [
                                'discovery' => ['type' => 'string'],
                                'heading_patterns' => $stringArray,
                                'code_patterns' => $stringArray,
                                'systems' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => ['type' => 'string'],
                                            'category' => ['type' => 'string'],
                                            'heading_pattern' => ['type' => 'string'],
                                            'table_hints' => $stringArray,
                                        ],
                                        'required' => ['name', 'category', 'heading_pattern', 'table_hints'],
                                    ],
                                ],
                                'table_association' => ['type' => 'string'],
                                'section_continuation' => ['type' => 'string'],
                            ],
                            'required' => ['discovery', 'heading_patterns', 'code_patterns', 'systems', 'table_association', 'section_continuation'],
                        ],
                        'equipment_structure' => [
                            'type' => 'object',
                            'properties' => [
                                'discovery' => ['type' => 'string'],
                                'representation' => ['type' => 'string'],
                                'identity' => $dynamicObject,
                                'properties' => $dynamicObject,
                                'equipment_axis' => ['type' => 'string'],
                                'block_size' => $nullableString,
                                'continuation_across_pages' => ['type' => 'boolean'],
                            ],
                            'required' => ['discovery', 'representation', 'identity', 'properties', 'equipment_axis', 'block_size', 'continuation_across_pages'],
                        ],
                        'table_structure' => [
                            'type' => 'object',
                            'properties' => [
                                'orientation' => ['type' => 'string'],
                                'row_structure' => $dynamicObject,
                                'column_structure' => $dynamicObject,
                                'binding' => $dynamicObject,
                            ],
                            'required' => ['orientation', 'row_structure', 'column_structure', 'binding'],
                        ],
                        'control_item_structure' => [
                            'type' => 'object',
                            'properties' => [
                                'discovery' => ['type' => 'string'],
                                'code_pattern' => ['type' => 'string'],
                                'description_location' => ['type' => 'string'],
                                'result_location' => ['type' => 'string'],
                                'system_binding' => ['type' => 'string'],
                                'equipment_binding' => ['type' => 'string'],
                                'result_aliases' => $stringArray,
                            ],
                            'required' => ['discovery', 'code_pattern', 'description_location', 'result_location', 'system_binding', 'equipment_binding', 'result_aliases'],
                        ],
                        'findings_structure' => $dynamicObject,
                        'extraction_rules' => [
                            'type' => 'object',
                            'properties' => [
                                'report_information' => ['type' => 'string'],
                                'organization_information' => ['type' => 'string'],
                                'systems' => ['type' => 'string'],
                                'equipment' => ['type' => 'string'],
                                'components' => ['type' => 'string'],
                                'control_items' => ['type' => 'string'],
                                'results' => ['type' => 'string'],
                                'findings' => ['type' => 'string'],
                            ],
                            'required' => ['report_information', 'organization_information', 'systems', 'equipment', 'components', 'control_items', 'results', 'findings'],
                        ],
                        'table_hints' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'role' => ['type' => 'string'],
                                    'page_hints' => $integerArray,
                                    'title_patterns' => $stringArray,
                                    'header_patterns' => $stringArray,
                                    'structure_type' => ['type' => 'string'],
                                    'orientation' => ['type' => 'string'],
                                    'equipment_axis' => ['type' => 'string'],
                                    'control_axis' => ['type' => 'string'],
                                    'result_binding' => ['type' => 'string'],
                                    'repeat_block' => $dynamicObject,
                                    'continuation' => ['type' => 'string'],
                                    'camelot' => $dynamicObject,
                                ],
                                'required' => ['role', 'page_hints', 'title_patterns', 'header_patterns', 'structure_type', 'orientation', 'equipment_axis', 'control_axis', 'result_binding', 'repeat_block', 'continuation', 'camelot'],
                            ],
                        ],
                        'evidence' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'decision' => ['type' => 'string'],
                                    'reason' => ['type' => 'string'],
                                    'source_pages' => $integerArray,
                                ],
                                'required' => ['decision', 'reason', 'source_pages'],
                            ],
                        ],
                    ],
                    'required' => [
                        'template_type', 'template_version', 'report_structure', 'report_information',
                        'organization_information', 'systems_structure', 'equipment_structure', 'table_structure',
                        'control_item_structure', 'findings_structure', 'extraction_rules', 'table_hints', 'evidence',
                    ],
                ],
                'extracted_data' => [
                    'type' => 'object',
                    'properties' => [
                        'findings' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => $nullableString,
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
            if (!is_array($step) || ($step['type'] ?? null) !== 'model_output') continue;
            foreach ((array) ($step['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text'])) {
                    return (string) $content['text'];
                }
            }
        }

        throw new RuntimeException('Gemini yanıtında model çıktısı bulunamadı.');
    }
}
