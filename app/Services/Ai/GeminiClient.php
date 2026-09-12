<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Gemini Interactions API client for structured report extraction.
 *
 * Keeps provider transport separate from the report analysis flow while the
 * temporary fire-suppression extraction contract is intentionally compact.
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

        // Geçici kompakt analiz sözleşmesi:
        // Tablo/ekipman detaylarını Gemini üretmez. Bunlar daha sonra universal
        // table analyzer tarafından deterministik olarak çıkarılacaktır.
        $compactSystemPrompt = <<<'PROMPT'
Sen yangın tesisatı periyodik kontrol raporlarını anlayan bir veri çıkarma motorusun.

Sana biçimi önceden bilinmeyen bir yangın tesisatı/periyodik kontrol PDF'sinin tamamının metni verilecek.
Firma şablonuna veya sabit bölüm sırasına güvenme.

Yalnızca anlamsal olarak gerekli bilgileri çıkar:

1. RAPOR
- control_date
- next_control_date
- report_no
- company_name
- overall_result

2. SİSTEMLER
Raporda gerçekten kontrol edilen sistemleri/grupları belirle.
Her sistem için:
- name: rapordaki sistem adı
- category: mümkünse yangin_dolabi, yangin_pompasi, hidrant, sprinkler, su_alma_verme, su_deposu, sabit_boru_tesisati, gazli_sondurme veya diger
- control_count
- nonconforming_count

3. BULGULAR
Uygunsuzlukları sistem bazında çıkar.
Her kayıt yalnızca:
- system_name
- description

Bulguda ekipman kodu açıkça geçiyorsa description içinde koru.
Kodu kendin uydurma.
Aynı bulguyu bileşen bazında tekrar etme.

ÇOK ÖNEMLİ:
- Ekipman listesi oluşturma.
- Yangın dolabı kodlarını veya lokasyonlarını JSON'a çıkarma.
- Yangın dolabı equipment_matrix oluşturma.
- components oluşturma.
- Marka, model, seri no, basınç, hortum uzunluğu, ölçüler veya diğer teknik tablo kolonlarını çıkarma.
- U / UD / N değerlerini tek tek JSON'a aktarma.
- Kontrol kriterlerini JSON'a aktarma.
- Tabloyu yeniden yapılandırma.
- Tablo satırlarını özetleme.
- Ekipman sayısını bulgu olarak üretme.
- Raporda olmayan bilgi üretme.

Ekipman, kod, lokasyon, teknik değerler ve U/UD/N ilişkileri daha sonra ayrı bir universal table analyzer tarafından PDF metninden çıkarılacaktır.

BELGE / PROJE / KAYIT:
Fiziksel ekipman olmayan proje, belge veya kayıt kontrollerini fiziksel sistem/equipment olarak üretme. Bunlara ilişkin önemli uygunsuzlukları findings içinde belirt.

Rapor adını veya firma adını değiştirme/normalize etme.

SAYIM:
control_count ve nonconforming_count yalnızca rapor açıkça destekliyorsa çıkar. Emin olunmayan durumda 0 kullan.

Yalnızca geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.
PROMPT;

        $payload = [
            'model' => config('services.gemini.text_model'),
            'system_instruction' => $compactSystemPrompt,
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
            'prompt_length' => mb_strlen($compactSystemPrompt),
            'input_length' => mb_strlen($userContent),
            'max_tokens' => max($maxTokens, 12000),
            'thinking_level' => 'minimal',
            'structured_output' => true,
            'compact_extraction' => true,
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
            'compact_extraction' => true,
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
                        ],
                        'required' => ['name', 'category', 'control_count', 'nonconforming_count'],
                    ],
                ],
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'system_name' => ['type' => ['string', 'null']],
                            'description' => ['type' => 'string'],
                        ],
                        'required' => ['system_name', 'description'],
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
