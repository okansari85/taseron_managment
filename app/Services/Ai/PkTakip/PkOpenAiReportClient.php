<?php

namespace App\Services\Ai\PkTakip;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * pktakip rapor okuma için OpenAI (ChatGPT) istemcisi: PkGeminiReportClient ile aynı istem ve aynı çıktı şeması
 * (Responses API, katı JSON şeması). Tek istek; tekrar deneme yok.
 */
class PkOpenAiReportClient
{
    public function __construct(private PkGeminiReportClient $gemini)
    {
    }

    // $file: görüntü PDF (PkReportText::read) — PDF'in kendisi gönderilir, model sayfaları görüntüden okur.
    public function extract(string $systemPrompt, string $userContent, int $maxTokens = 50000, ?array $file = null): array
    {
        // Anahtar ve model: Ayarlar → Yapay zeka (yoksa .env).
        $apiKey = PkAiProvider::apiKey('openai');
        if (!filled($apiKey)) {
            throw new RuntimeException('OpenAI API anahtarı tanımlı değil (Ayarlar → Yapay zeka).');
        }
        $model = PkAiProvider::model('openai');

        $payload = [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $file ? [
                    ['type' => 'input_file', 'filename' => $file['name'], 'file_data' => 'data:application/pdf;base64,' . base64_encode((string) file_get_contents($file['path']))],
                    ['type' => 'input_text', 'text' => $userContent],
                ] : $userContent],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'pk_report',
                    'schema' => PkJsonSchema::strict($this->gemini->responseSchema()),
                    'strict' => true,
                ],
            ],
            'max_output_tokens' => $maxTokens,
            'store' => false,
        ];
        if (filled($effort = config('pktakip.openai.reasoning_effort'))) {
            $payload['reasoning'] = ['effort' => $effort];
        }

        $startedAt = microtime(true);
        try {
            $response = Http::withToken($apiKey)
                ->connectTimeout(10)
                ->timeout(300)
                ->post(rtrim((string) config('pktakip.openai.base_url'), '/') . '/responses', $payload);
        } catch (\Throwable $exception) {
            Log::error('OpenAI rapor okuma bağlantı hatası', ['duration_s' => round(microtime(true) - $startedAt, 1), 'message' => $exception->getMessage()]);
            throw new RuntimeException('OpenAI isteği başarısız oldu: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('OpenAI isteği başarısız oldu (HTTP ' . $response->status() . '): ' . $response->body());
        }

        $json = (array) $response->json();
        if (($json['status'] ?? null) === 'incomplete') {
            throw new RuntimeException('OpenAI yanıtı yarım kaldı: ' . ($json['incomplete_details']['reason'] ?? 'bilinmiyor') . '.');
        }
        $content = $this->outputText($json);
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI çıktısı geçerli JSON değil.');
        }

        Log::info('OpenAI rapor okuma tamamlandı', [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'model' => $model,
            'image_pdf' => $file ? $file['page_count'] . ' sayfa' : null,
            'input_tokens' => $json['usage']['input_tokens'] ?? null,
            'output_tokens' => $json['usage']['output_tokens'] ?? null,
            'output_length' => mb_strlen($content),
        ]);
        PkAiUsage::record($json['usage']['input_tokens'] ?? null, $json['usage']['output_tokens'] ?? null);

        return $decoded;
    }

    private function outputText(array $response): string
    {
        foreach ((array) ($response['output'] ?? []) as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('OpenAI raporu okumayı reddetti: ' . ($content['refusal'] ?? ''));
                }
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    return (string) $content['text'];
                }
            }
        }

        throw new RuntimeException('OpenAI yanıtında model çıktısı bulunamadı.');
    }
}
