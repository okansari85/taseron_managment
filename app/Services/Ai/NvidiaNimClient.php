<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $lastDiagnostic = null;
        // Prod telemetri: her çağrının gerçek NIM gecikmesini ve retry
        // sayısını görebilmek için (senkron Tinker testleri yerine gerçek
        // kullanım verisi) — bkz. laravel.log "NVIDIA NIM: çağrı bitti".
        $callStart = microtime(true);

        while ($attempts < $maxAttempts) {
            $attempts++;

            Log::info('NVIDIA NIM: çağrı başladı', [
                'attempt' => $attempts,
                'prompt_length' => mb_strlen($systemPrompt),
                'input_length' => mb_strlen($userContent),
                'max_tokens' => $maxTokens,
            ]);

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

                if ($decoded !== null) {
                    Log::info('NVIDIA NIM: çağrı bitti', [
                        'duration_s' => round(microtime(true) - $callStart, 1),
                        'attempts' => $attempts,
                        'prompt_length' => mb_strlen($systemPrompt),
                        'input_length' => mb_strlen($userContent),
                        'max_tokens' => $maxTokens,
                    ]);

                    return $decoded;
                }

                // HTTP isteği başarılıydı ama içerik geçerli JSON değildi
                // (muhtemelen max_tokens'ta yarıda kesildi ya da model
                // bozuk çıktı üretti) — bunu da GEÇİCİ kabul edip tekrar
                // deniyoruz (önceden ilk denemede direkt vazgeçiyorduk).
                // Teşhis için ham çıktının kuyruğunu ve finish_reason'ı
                // logluyoruz — bir sonraki başarısızlıkta neyin kesildiğini
                // görebilelim diye.
                $lastDiagnostic = $this->diagnoseFailure($response) + ['duration_s_so_far' => round(microtime(true) - $callStart, 1)];
                Log::warning('NVIDIA NIM: AI çıktısı JSON olarak ayrıştırılamadı (deneme ' . $attempts . '/' . $maxAttempts . ')', $lastDiagnostic);

                if ($attempts < $maxAttempts) {
                    sleep($attempts * 3);
                }
            } catch (\Illuminate\Http\Client\ConnectionException $exception) {
                $lastException = $exception;
                if ($attempts < $maxAttempts) {
                    sleep($attempts * 3);
                }
            }
        }

        if ($lastDiagnostic !== null) {
            throw new RuntimeException(
                'AI çıktısı geçerli bir JSON olarak ayrıştırılamadı (finish_reason: '
                . ($lastDiagnostic['finish_reason'] ?? 'bilinmiyor') . ', içerik uzunluğu: '
                . $lastDiagnostic['content_length'] . ' karakter). Ayrıntılar için laravel.log.'
            );
        }

        throw new RuntimeException('NVIDIA NIM isteği tekrar denemelere rağmen başarısız oldu: ' . ($lastException?->getMessage() ?? 'bilinmeyen bağlantı hatası'));
    }

    // finish_reason "length" ise: model max_tokens sınırına çarpıp yarıda
    // kesildi demektir — çözümü daha büyük max_tokens ya da daha küçük
    // prompt/sayfa'dır. Başka bir finish_reason'la gelen bozuk JSON ise
    // modelin kendisinin ürettiği geçersiz bir çıktı demektir.
    private function diagnoseFailure(Response $response): array
    {
        $content = (string) $response->json('choices.0.message.content');

        return [
            'finish_reason' => $response->json('choices.0.finish_reason'),
            'content_length' => mb_strlen($content),
            'content_tail' => mb_substr($content, -800),
            'json_error' => json_last_error_msg(),
        ];
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
