<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Google Gemini Interactions API client for structured report extraction. */
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
- control_items: yalnızca o sisteme ait kontrol maddeleri

Her system.control_items kaydı:
- code: kontrol/madde kodu
- description: kontrol maddesinin metni
- status: raporda o sistem için görülen durum; U, UD, N veya PDF'de açıkça kullanılan başka kısa durum değeri

ÇOK ÖNEMLİ:
- Kontrol maddelerini doğru sisteme bağla. PDF'de aynı sayfada yan yana iki veya daha fazla sistem tablosu varsa her kontrol maddesini bulunduğu sistem sütununa/başlığına göre ilgili sisteme koy.
- Bir kontrol maddesini başka bir sistemin altına kopyalama.
- Fiziksel ekipman listesi oluşturma.
- Ekipman kodlarını sistem.control_items içine equipment_refs olarak ekleme.
- Yangın dolabı ekipman matrisi oluşturma.
- components oluşturma.
- Marka, model, seri no, basınç, hortum uzunluğu, ölçüler veya diğer teknik tablo kolonlarını çıkarma.
- Ekipman bazlı U / UD / N ilişkisini JSON'a çıkarma.
- Ekipman sayısı hesaplama.
- control_count veya nonconforming_count hesaplama.
- Tabloyu yeniden yapılandırma.

Kontrol maddeleri sistem seviyesinde anlamsal bağlam içindir. Fiziksel ekipman kodu ile U/UD/N arasındaki matris ilişkisi daha sonra ayrı deterministic coordinate/table analyzer tarafından çıkarılacaktır.

3. BULGULAR
Uygunsuzlukları sistem bazında çıkar.
Her kayıt yalnızca:
- system_name
- description

Bulguda ekipman kodu açıkça geçiyorsa description içinde koru.
Kodu kendin uydurma.
Aynı bulguyu bileşen bazında tekrar etme.

BELGE / PROJE / KAYIT:
Fiziksel ekipman olmayan proje, belge veya kayıt kontrollerini fiziksel ekipman olarak üretme. Bunlara ilişkin önemli uygunsuzlukları findings içinde belirt.

Rapor adını veya firma adını değiştirme/normalize etme.

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
            ])->connectTimeout(10)->timeout(180)->post($url, $payload);
        } catch (\Throwable $exception) {
            Log::error('Gemini: bağlantı hatası', ['duration_s' => round(microtime(true) - $startedAt, 1), 'message' => $exception->getMessage()]);
            throw new RuntimeException('Gemini isteği başarısız oldu: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            Log::error('Gemini: HTTP hatası', ['status' => $response->status(), 'duration_s' => round(microtime(true) - $startedAt, 1), 'body' => mb_substr($response->body(), -2000)]);
            throw new RuntimeException('Gemini isteği başarısız oldu (HTTP ' . $response->status() . '): ' . $response->body());
        }

        $content = $this->extractOutputText($response->json());
        $decoded = json_decode($content, true);
        $status = $response->json('status');

        Log::info('Gemini: Interactions çağrısı bitti', ['duration_s' => round(microtime(true) - $startedAt, 1), 'model' => config('services.gemini.text_model'), 'output_length' => mb_strlen($content), 'status' => $status, 'compact_extraction' => true]);

        if (! is_array($decoded)) {
            throw new RuntimeException('Gemini çıktısı geçerli JSON olarak ayrıştırılamadı (status: ' . ($status ?? 'bilinmiyor') . ', içerik uzunluğu: ' . mb_strlen($content) . ' karakter).');
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
                            'control_items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'code' => ['type' => 'string'],
                                        'description' => ['type' => 'string'],
                                        'status' => ['type' => ['string', 'null']],
                                    ],
                                    'required' => ['code', 'description', 'status'],
                                ],
                            ],
                        ],
                        'required' => ['name', 'category', 'control_items'],
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
            if (! is_array($step) || ($step['type'] ?? null) !== 'model_output') continue;
            foreach ((array) ($step['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text'])) return (string) $content['text'];
            }
        }
        throw new RuntimeException('Gemini Interactions yanıtında model çıktısı bulunamadı (status: ' . ($response['status'] ?? 'bilinmiyor') . ').');
    }
}
