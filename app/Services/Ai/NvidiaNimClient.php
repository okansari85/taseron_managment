<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
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

    public function extractStructuredJson(string $systemPrompt, string $userContent, int $maxTokens = 8000): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('NVIDIA_NIM_API_KEY tanımlı değil — AI destekli rapor analizi kullanılamıyor.');
        }

        // NVIDIA NIM'in ücretsiz katmanındaki ağ geçidi, model büyük
        // raporlarda yanıtı yetiştiremezse 502/503/504 ile kesiyor — bizim
        // istemci timeout'umuzdan (aşağıda sınırsız) bağımsız, onların
        // tarafında. Gerçekten geçici olabilecek bu hatalarda kısa bir
        // bekleme ile 2 kez daha deneniyor.
        $attempts = 0;
        $maxAttempts = 3;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $response = Http::withToken(config('services.nvidia_nim.api_key'))
                    ->timeout(0)
                    ->post($this->endpoint(), $this->payload($systemPrompt, $userContent, $maxTokens));

                if ($response->serverError() && $attempts < $maxAttempts) {
                    sleep($attempts * 3);

                    continue;
                }

                if ($response->failed()) {
                    throw new RuntimeException('NVIDIA NIM isteği başarısız oldu (HTTP ' . $response->status() . '): ' . $response->body());
                }

                $decoded = $this->decodeContent($response);

                if ($decoded === null) {
                    throw new RuntimeException('AI çıktısı geçerli bir JSON olarak ayrıştırılamadı.');
                }

                return $decoded;
            } catch (\Illuminate\Http\Client\ConnectionException $exception) {
                $lastException = $exception;
                if ($attempts < $maxAttempts) {
                    sleep($attempts * 3);
                }
            }
        }

        throw new RuntimeException('NVIDIA NIM isteği tekrar denemelere rağmen başarısız oldu: ' . ($lastException?->getMessage() ?? 'bilinmeyen bağlantı hatası'));
    }

    // NOT: Http::pool ile sayfaları paralel gönderme denendi (daha hızlı
    // olması için) ama NVIDIA NIM ücretsiz katmanı eşzamanlı isteklerde
    // bozuk/eksik JSON döndürdü (muhtemelen concurrency limiti) — gerçek
    // testte 7 dakika bekleyip "geçerli JSON değil" hatası verdi. Bu yüzden
    // kasıtlı olarak SIRALI (extractStructuredJson, tek tek) kullanılıyor;
    // paralel varyant kaldırıldı.
    private function endpoint(): string
    {
        return rtrim((string) config('services.nvidia_nim.base_url'), '/') . '/chat/completions';
    }

    private function payload(string $systemPrompt, string $userContent, int $maxTokens): array
    {
        return [
            'model' => config('services.nvidia_nim.text_model'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => 0.1,
            // max_tokens verilmezse büyük ekipman listeli raporlarda JSON
            // yarıda kesilip parse edilemez.
            'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_object'],
            // Nemotron 3.5 Lightning gibi "reasoning" modelleri thinking
            // kapatılmazsa JSON'dan önce chain-of-thought metni ekliyor —
            // desteklemeyen modeller bu alanı yok sayar.
            'chat_template_kwargs' => ['thinking' => false],
        ];
    }

    private function decodeContent(Response $response): ?array
    {
        $content = $response->json('choices.0.message.content');
        $decoded = json_decode((string) $content, true);

        return is_array($decoded) ? $decoded : null;
    }
}
