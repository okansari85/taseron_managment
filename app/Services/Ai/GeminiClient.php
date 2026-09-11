<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Gemini Interactions API client for structured report extraction.
 *
 * Keeps the existing AI extraction contract so the report analyzer does not
 * need to know which provider is being used.
 */
class GeminiClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.gemini.api_key'));
    }

    public function extractStructuredJson(string $systemPrompt, string $userContent, int $maxTokens = 8000): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('GEMINI_API_KEY tanımlı değil — Gemini destekli rapor analizi kullanılamıyor.');
        }

        $url = rtrim((string) config('services.gemini.base_url'), '/') . '/interactions';
        $startedAt = microtime(true);

        $payload = [
            'model' => config('services.gemini.text_model'),
            'system_instruction' => $systemPrompt,
            'input' => $userContent,
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $this->responseSchema(),
            ],
            'generation_config' => [
                'temperature' => 0.1,
                'thinking_level' => 'minimal',
                'max_output_tokens' => max($maxTokens, 12000),
            ],
            'store' => false,
        ];

        Log::info('Gemini: Interactions çağrısı başladı', [
            'model' => config('services.gemini.text_model'),
            'prompt_length' => mb_strlen($systemPrompt),
            'input_length' => mb_strlen($userContent),
            'max_tokens' => max($maxTokens, 12000),
            'thinking_level' => 'minimal',
            'structured_output' => true,
        ]);

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => config('services.gemini.api_key'),
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout(10)
                ->timeout(180)
                ->post($url, $payload);
        } catch (\Throwable $exception) {
            Log::error('Gemini: bağlantı hatası', [
                'duration_s' => round(microtime(true) - $startedAt, 1),
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Gemini isteği başarısız oldu: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            Log::error('Gemini: HTTP hatası', [
                'status' => $response->status(),
                'duration_s' => round(microtime(true) - $startedAt, 1),
                'body' => mb_substr($response->body(), -2000),
            ]);

            throw new RuntimeException('Gemini isteği başarısız oldu (HTTP ' . $response->status() . '): ' . $response->body());
        }

        $content = $this->extractOutputText($response->json());
        $decoded = json_decode($content, true);
        $status = $response->json('status');

        Log::info('Gemini: Interactions çağrısı bitti', [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'model' => config('services.gemini.text_model'),
            'output_length' => mb_strlen($content),
            'status' => $status,
        ]);

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'Gemini çıktısı geçerli JSON olarak ayrıştırılamadı (status: '
                . ($status ?? 'bilinmiyor')
                . ', içerik uzunluğu: ' . mb_strlen($content) . ' karakter).'
            );
        }

        return $decoded;
    }

    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'report' => [
                    'type' => 'object',
                    'properties' => [
                        'control_date' => ['type' => ['string', 'null']],
                        'next_control_date' => ['type' => ['string', 'null']],
                        'report_no' => ['type' => ['string', 'null']],
                        'company_name' => ['type' => ['string', 'null']],
                        'overall_result' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['control_date', 'next_control_date', 'report_no', 'company_name', 'overall_result'],
                ],
                'systems' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'control_count' => ['type' => ['integer', 'null']],
                            'nonconforming_count' => ['type' => ['integer', 'null']],
                            'components' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'code' => ['type' => ['string', 'null']],
                                        'name' => ['type' => ['string', 'null']],
                                        'location' => ['type' => ['string', 'null']],
                                        'brand' => ['type' => ['string', 'null']],
                                        'model' => ['type' => ['string', 'null']],
                                        'serial_no' => ['type' => ['string', 'null']],
                                        'result' => ['type' => ['string', 'null']],
                                    ],
                                    'required' => ['code', 'name', 'location', 'brand', 'model', 'serial_no', 'result'],
                                ],
                            ],
                        ],
                        'required' => ['name', 'category', 'control_count', 'nonconforming_count', 'components'],
                    ],
                ],
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'system_name' => ['type' => ['string', 'null']],
                            'component_codes' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'description' => ['type' => 'string'],
                        ],
                        'required' => ['system_name', 'component_codes', 'description'],
                    ],
                ],
            ],
            'required' => ['report', 'systems', 'findings'],
        ];
    }

    private function extractOutputText(array $response): string
    {
        foreach ((array) ($response['steps'] ?? []) as $step) {
            if (! is_array($step) || ($step['type'] ?? null) !== 'model_output') {
                continue;
            }

            foreach ((array) ($step['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text'])) {
                    return (string) $content['text'];
                }
            }
        }

        throw new RuntimeException(
            'Gemini Interactions yanıtında model çıktısı bulunamadı (status: '
            . ($response['status'] ?? 'bilinmiyor') . ').'
        );
    }
}
