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

        return [
            'type' => 'object',
            'properties' => [
                'template' => [
                    'type' => 'object',
                    'properties' => [
                        'template_type' => ['type' => 'string'],
                        'template_version' => ['type' => 'string'],
                        'report_information' => ['type' => 'object'],
                        'organization_information' => ['type' => 'object'],
                        'systems' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'findings_structure' => ['type' => 'object'],
                        'extraction_rules' => ['type' => 'object'],
                    ],
                    'required' => [
                        'template_type',
                        'template_version',
                        'report_information',
                        'organization_information',
                        'systems',
                        'findings_structure',
                        'extraction_rules',
                    ],
                ],
                'extracted_data' => [
                    'type' => 'object',
                    'properties' => [
                        'report' => [
                            'type' => 'object',
                            'properties' => [
                                'report_no' => $nullableString,
                                'company_name' => $nullableString,
                                'control_date' => $nullableString,
                                'next_control_date' => $nullableString,
                                'overall_result' => $nullableString,
                            ],
                            'required' => ['report_no', 'company_name', 'control_date', 'next_control_date', 'overall_result'],
                        ],
                        'covered_categories' => $stringArray,
                        'systems' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'category' => ['type' => 'string'],
                                    'equipment_count' => ['type' => 'integer'],
                                    'equipment_count_known' => ['type' => 'boolean'],
                                    'control_count' => ['type' => 'integer'],
                                    'components' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'code' => $nullableString,
                                                'name' => $nullableString,
                                                'location' => $nullableString,
                                                'brand' => $nullableString,
                                                'model' => $nullableString,
                                                'serial_no' => $nullableString,
                                                'properties' => ['type' => 'object'],
                                                'source_pages' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                            ],
                                            'required' => ['code', 'name', 'location', 'brand', 'model', 'serial_no', 'properties', 'source_pages'],
                                        ],
                                    ],
                                    'control_items' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'code' => ['type' => 'string'],
                                                'description' => $nullableString,
                                                'scope' => ['type' => 'string'],
                                                'equipment' => ['type' => 'string'],
                                                'results' => ['type' => 'object'],
                                                'source_pages' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                            ],
                                            'required' => ['code', 'description', 'scope', 'equipment', 'results', 'source_pages'],
                                        ],
                                    ],
                                ],
                                'required' => ['name', 'category', 'equipment_count', 'equipment_count_known', 'control_count', 'components', 'control_items'],
                            ],
                        ],
                        'findings' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => $nullableString,
                                    'system_name' => $nullableString,
                                    'description' => ['type' => 'string'],
                                    'affected_equipment' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'source_pages' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                ],
                                'required' => ['id', 'system_name', 'description', 'affected_equipment', 'source_pages'],
                            ],
                        ],
                        'matched_inventory_items' => ['type' => 'array'],
                        'candidate_inventory_items' => ['type' => 'array'],
                        'unmatched_codes' => $stringArray,
                        'analyzer' => ['type' => 'object'],
                        'fixture_id' => $nullableString,
                    ],
                    'required' => [
                        'report',
                        'covered_categories',
                        'systems',
                        'findings',
                        'matched_inventory_items',
                        'candidate_inventory_items',
                        'unmatched_codes',
                        'analyzer',
                        'fixture_id',
                    ],
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
