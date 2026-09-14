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

        return [
            'type' => 'object',
            'properties' => [
                'template' => [
                    'type' => 'object',
                    'properties' => [
                        'template_type' => ['type' => 'string'],
                        'template_version' => ['type' => 'string'],
                        'report_structure' => ['type' => 'object'],
                        'report_information' => ['type' => 'object'],
                        'organization_information' => ['type' => 'object'],
                        'systems_structure' => ['type' => 'object'],
                        'equipment_structure' => ['type' => 'object'],
                        'table_structure' => [
                            'type' => 'object',
                            'properties' => [
                                'orientation' => ['type' => 'string'],
                                'row_structure' => ['type' => 'object'],
                                'column_structure' => ['type' => 'object'],
                                'binding' => ['type' => 'object'],
                            ],
                            'required' => ['orientation', 'row_structure', 'column_structure', 'binding'],
                        ],
                        'control_item_structure' => ['type' => 'object'],
                        'findings_structure' => ['type' => 'object'],
                        'extraction_rules' => ['type' => 'object'],
                        'table_hints' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'evidence' => ['type' => 'array', 'items' => ['type' => 'object']],
                    ],
                    'required' => [
                        'template_type',
                        'template_version',
                        'report_structure',
                        'report_information',
                        'organization_information',
                        'systems_structure',
                        'equipment_structure',
                        'table_structure',
                        'control_item_structure',
                        'findings_structure',
                        'extraction_rules',
                        'table_hints',
                        'evidence',
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
