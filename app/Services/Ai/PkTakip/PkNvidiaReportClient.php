<?php

namespace App\Services\Ai\PkTakip;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * pktakip rapor okuma için NVIDIA NIM (build.nvidia.com, ücretsiz) istemcisi: PkGeminiReportClient ile aynı istem ve
 * aynı çıktı şeması (OpenAI uyumlu chat/completions + nvext.guided_json). Anahtar ve model Ayarlar → Yapay zeka'dan
 * (yoksa taseron'daki NVIDIA NIM .env ayarı), adres services.nvidia_nim'den; mevcut NvidiaNimClient kullanılmaz ve
 * değiştirilmez. Tek istek; tekrar deneme yok.
 */
class PkNvidiaReportClient
{
    public function __construct(private PkGeminiReportClient $gemini)
    {
    }

    public function extract(string $systemPrompt, string $userContent, int $maxTokens = 50000): array
    {
        // Anahtar ve model: Ayarlar → Yapay zeka (yoksa .env).
        $apiKey = PkAiProvider::apiKey('nvidia');
        if (!filled($apiKey)) {
            throw new RuntimeException('NVIDIA API anahtarı tanımlı değil (Ayarlar → Yapay zeka).');
        }

        $model = PkAiProvider::model('nvidia');
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => 0.1,
            // Barındırılan modellerin çıktı sınırı Gemini'den düşük olabilir.
            'max_tokens' => min($maxTokens, (int) config('pktakip.nvidia.max_tokens')),
            'chat_template_kwargs' => ['thinking' => false],
            'stream' => false,
        ];
        // Şema zorlaması (guided decoding); modeli desteklemiyorsa PKTAKIP_NVIDIA_GUIDED_JSON=false ile yalnızca JSON istenir.
        if (config('pktakip.nvidia.guided_json')) {
            $payload['nvext'] = ['guided_json' => PkJsonSchema::strict($this->gemini->responseSchema())];
        } else {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $startedAt = microtime(true);
        try {
            $response = Http::withToken($apiKey)
                ->connectTimeout(10)
                ->timeout(300)
                ->post(rtrim((string) config('services.nvidia_nim.base_url'), '/') . '/chat/completions', $payload);
        } catch (\Throwable $exception) {
            Log::error('NVIDIA rapor okuma bağlantı hatası', ['duration_s' => round(microtime(true) - $startedAt, 1), 'message' => $exception->getMessage()]);
            throw new RuntimeException('NVIDIA isteği başarısız oldu: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('NVIDIA isteği başarısız oldu (HTTP ' . $response->status() . '): ' . $response->body());
        }

        $content = (string) $response->json('choices.0.message.content');
        $finish = $response->json('choices.0.finish_reason');
        $decoded = $this->decode($content);
        if (!is_array($decoded)) {
            Log::warning('NVIDIA rapor okuma: çıktı JSON değil', ['finish_reason' => $finish, 'content_length' => mb_strlen($content), 'content_tail' => mb_substr($content, -500)]);
            throw new RuntimeException($finish === 'length'
                ? 'NVIDIA yanıtı yarım kaldı (çıktı sınırı); PKTAKIP_NVIDIA_MAX_TOKENS artırılabilir.'
                : 'NVIDIA çıktısı geçerli JSON değil.');
        }

        Log::info('NVIDIA rapor okuma tamamlandı', [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'model' => $model,
            'input_tokens' => $response->json('usage.prompt_tokens'),
            'output_tokens' => $response->json('usage.completion_tokens'),
            'output_length' => mb_strlen($content),
        ]);
        PkAiUsage::record($response->json('usage.prompt_tokens'), $response->json('usage.completion_tokens'));

        return $decoded;
    }

    // Şema zorlaması yoksa model JSON'u ```json bloğu ya da açıklama arasında verebilir: ilk { ile son } arası.
    private function decode(string $content): ?array
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded) && ($start = strpos($content, '{')) !== false && ($end = strrpos($content, '}')) > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
        }

        return is_array($decoded) ? $decoded : null;
    }
}
