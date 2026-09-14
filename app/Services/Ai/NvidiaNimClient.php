<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * NVIDIA NIM OpenAI-compatible client used by fire-suppression report analysis.
 * Gemini is intentionally not involved here.
 */
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

        $attempts = 0;
        $maxAttempts = 3;
        $lastException = null;
        $lastDiagnostic = null;
        $callStart = microtime(true);

        while ($attempts < $maxAttempts) {
            $attempts++;

            Log::info('NVIDIA NIM: çağrı başladı', [
                'attempt' => $attempts,
                'model' => config('services.nvidia_nim.text_model'),
                'prompt_length' => mb_strlen($this->semanticSystemPrompt()),
                'input_length' => mb_strlen($userContent),
                'max_tokens' => $maxTokens,
                'compact_extraction' => true,
                'gemini_contract_compatible' => true,
            ]);

            try {
                $response = Http::withToken(config('services.nvidia_nim.api_key'))
                    ->timeout(0)
                    ->post($this->endpoint(), $this->payload($userContent, $maxTokens));

                if ($response->serverError() && $attempts < $maxAttempts) {
                    sleep($attempts * 3);
                    continue;
                }

                if ($response->failed()) {
                    throw new RuntimeException(
                        'NVIDIA NIM isteği başarısız oldu (HTTP ' . $response->status() . '): ' . $response->body()
                    );
                }

                $decoded = $this->decodeContent($response);
                if ($decoded !== null) {
                    Log::info('NVIDIA NIM: çağrı bitti', [
                        'duration_s' => round(microtime(true) - $callStart, 1),
                        'attempts' => $attempts,
                        'model' => config('services.nvidia_nim.text_model'),
                        'compact_extraction' => true,
                        'gemini_contract_compatible' => true,
                    ]);

                    Log::info('FIRE_SUPPRESSION_NVIDIA_SEMANTIC_RAW', [
                        'analysis_id' => request()->attributes->get('analysis_id'),
                        'semantic' => $decoded,
                    ]);

                    return $decoded;
                }

                $lastDiagnostic = $this->diagnoseFailure($response);
                Log::warning('NVIDIA NIM: AI çıktısı JSON olarak ayrıştırılamadı', $lastDiagnostic + [
                    'attempt' => $attempts,
                ]);

                if ($attempts < $maxAttempts) {
                    sleep($attempts * 3);
                }
            } catch (ConnectionException $exception) {
                $lastException = $exception;
                if ($attempts < $maxAttempts) {
                    sleep($attempts * 3);
                }
            }
        }

        if ($lastDiagnostic !== null) {
            throw new RuntimeException(
                'AI çıktısı geçerli bir JSON olarak ayrıştırılamadı (finish_reason: '
                . ($lastDiagnostic['finish_reason'] ?? 'bilinmiyor')
                . ', içerik uzunluğu: ' . $lastDiagnostic['content_length']
                . ' karakter). Ayrıntılar için laravel.log.'
            );
        }

        throw new RuntimeException(
            'NVIDIA NIM isteği tekrar denemelere rağmen başarısız oldu: '
            . ($lastException?->getMessage() ?? 'bilinmeyen bağlantı hatası')
        );
    }

    private function endpoint(): string
    {
        return rtrim((string) config('services.nvidia_nim.base_url'), '/') . '/chat/completions';
    }

    private function payload(string $userContent, int $maxTokens): array
    {
        return [
            'model' => config('services.nvidia_nim.text_model'),
            'messages' => [
                ['role' => 'system', 'content' => $this->semanticSystemPrompt()],
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => 0.1,
            'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_object'],
            'chat_template_kwargs' => ['thinking' => false],
        ];
    }

    /**
     * NVIDIA must use the same compact semantic contract as Gemini.
     * Do not add equipment/control-matrix extraction here; those belong to
     * the deterministic universal table analyzer.
     */
    private function semanticSystemPrompt(): string
    {
        return <<<'PROMPT'
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
- control_count veya nonconforming_count hesaplama.
- Tabloyu yeniden yapılandırma.
- Tablo satırlarını özetleme.
- Ekipman sayısını bulgu olarak üretme.
- Raporda olmayan bilgi üretme.

Ekipman, kod, lokasyon, teknik değerler ve U/UD/N ilişkileri daha sonra ayrı bir universal table analyzer tarafından PDF metninden çıkarılacaktır.
Kontrol sayıları ve uygunsuz kontrol sayıları da aynı analiz katmanında, rapordaki kontrol matrisinden deterministik olarak hesaplanacaktır.

BELGE / PROJE / KAYIT:
Fiziksel ekipman olmayan proje, belge veya kayıt kontrollerini fiziksel sistem/equipment olarak üretme. Bunlara ilişkin önemli uygunsuzlukları findings içinde belirt.

Rapor adını veya firma adını değiştirme/normalize etme.

Yalnızca geçerli JSON döndür. Markdown veya JSON dışı metin döndürme.
PROMPT;
    }

    private function decodeContent(Response $response): ?array
    {
        $content = $response->json('choices.0.message.content');
        $decoded = json_decode((string) $content, true);

        return is_array($decoded) ? $decoded : null;
    }

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
}
