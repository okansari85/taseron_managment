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
            ],
            'generation_config' => [
                'temperature' => 0.1,
                'max_output_tokens' => $maxTokens,
            ],
            'store' => false,
        ];

        Log::info('Gemini: Interactions çağrısı başladı', [
            'model' => config('services.gemini.text_model'),
            'prompt_length' => mb_strlen($systemPrompt),
            'input_length' => mb_strlen($userContent),
            'max_tokens' => $maxTokens,
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

        Log::info('Gemini: Interactions çağrısı bitti', [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'model' => config('services.gemini.text_model'),
            'output_length' => mb_strlen($content),
            'status' => $response->json('status'),
        ]);

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'Gemini çıktısı geçerli JSON olarak ayrıştırılamadı (status: '
                . ($response->json('status') ?? 'bilinmiyor')
                . ', içerik uzunluğu: ' . mb_strlen($content) . ' karakter).'
            );
        }

        return $decoded;
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
