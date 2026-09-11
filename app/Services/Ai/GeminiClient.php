<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Gemini API client for structured report extraction.
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

        $url = rtrim((string) config('services.gemini.base_url'), '/')
            . '/models/' . config('services.gemini.text_model') . ':generateContent';

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userContent],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => $maxTokens,
                'responseMimeType' => 'application/json',
            ],
        ];

        $startedAt = microtime(true);

        Log::info('Gemini: çağrı başladı', [
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

        $content = $response->json('candidates.0.content.parts.0.text');
        $decoded = json_decode((string) $content, true);

        Log::info('Gemini: çağrı bitti', [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'model' => config('services.gemini.text_model'),
            'output_length' => mb_strlen((string) $content),
            'finish_reason' => $response->json('candidates.0.finishReason'),
        ]);

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'Gemini çıktısı geçerli JSON olarak ayrıştırılamadı (finish_reason: '
                . ($response->json('candidates.0.finishReason') ?? 'bilinmiyor')
                . ', içerik uzunluğu: ' . mb_strlen((string) $content) . ' karakter).'
            );
        }

        return $decoded;
    }
}
