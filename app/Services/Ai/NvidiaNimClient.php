<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// build.nvidia.com (NVIDIA NIM) — ücretsiz katman, OpenAI-uyumlu
// /chat/completions uç noktası. Rapor PDF'lerinden yapısal veri çıkarımı için
// kullanılıyor (bkz. ReportDocumentAnalysisService). Anahtar yoksa
// isConfigured() false döner, çağıran servisler bunu zarifçe ele almalı —
// mevcut elle-giriş akışları bu servisten bağımsız çalışmaya devam eder.
class NvidiaNimClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.nvidia_nim.api_key'));
    }

    public function extractStructuredJson(string $systemPrompt, string $userContent): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('NVIDIA_NIM_API_KEY tanımlı değil — AI destekli rapor analizi kullanılamıyor.');
        }

        $response = Http::withToken(config('services.nvidia_nim.api_key'))
            ->timeout(90)
            ->post(rtrim((string) config('services.nvidia_nim.base_url'), '/') . '/chat/completions', [
                'model' => config('services.nvidia_nim.text_model'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('NVIDIA NIM isteği başarısız oldu (HTTP ' . $response->status() . '): ' . $response->body());
        }

        $content = $response->json('choices.0.message.content');
        $decoded = json_decode((string) $content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI çıktısı geçerli bir JSON olarak ayrıştırılamadı.');
        }

        return $decoded;
    }
}
