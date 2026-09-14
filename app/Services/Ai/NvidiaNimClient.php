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

    private function payload(string $systemPrompt, string $userContent, int $maxTokens): array
    {
        // NVIDIA tarafında sistem keşfini özellikle güçlendiriyoruz. Bu ek
        // talimat Gemini promptunu değiştirmez; yalnızca NIM'e gönderilen
        // sistem mesajına eklenir.
        $nvidiaSystemDiscovery = "\nNVIDIA NIM EK KURALI - SİSTEM KEŞFİ:\n"
            . "- PDF'nin tamamını baştan sona değerlendir ve RAPORDA GERÇEKTEN KONTROL EDİLEN TÜM AYRI SİSTEMLERİ systems dizisine koy.\n"
            . "- Özellikle 5. TESPİT VE DEĞERLENDİRMELER bölümündeki kontrol matrisi/tablosunun bölüm başlıklarını sistem keşfi için birincil yapısal sinyal kabul et.\n"
            . "- Kontrol matrisi birden fazla harfli veya isimlendirilmiş grup içeriyorsa, her ayrı grup kendi başına bir sistemdir. O grubun altında en az bir kontrol maddesi bulunması, ekipman listesi bulunmasa bile sistemi systems içine almak için yeterlidir.\n"
            . "- Bir sistemin ekipmanı olmaması, o sistemi systems dizisinden çıkarma nedeni değildir. Sistem yalnızca kontrol maddelerinden oluşabilir.\n"
            . "- Kontrol kodlarının farklı aralıklara ayrılması da ayrı sistemleri gösterebilir; kodları sadece başka bir sistemin alt maddeleriymiş gibi birleştirme. Önce ilgili kontrol grubunun başlığını ve kapsamını değerlendir.\n"
            . "- Örneğin bir raporda \"Su Deposu Kontrolü\", \"Yağmurlama Sistemi Kontrolü\", \"Yangın Dolapları ... Kontrolü\" ve \"Hidrant ... Kontrolü\" ayrı başlıklarsa bunların her biri ayrı systems kaydıdır; ekipman tablosu yalnızca bazı sistemlerde bulunuyor olsa bile diğer sistemler atlanmaz.\n"
            . "- Bir sistem yalnızca bulgular içinde geçiyorsa onu otomatik olarak sistem sayma; fakat aynı sistem kontrol matrisi içinde ayrı bir başlıkla veya o sisteme ait ayrı kontrol grubuyla tanımlanmışsa mutlaka systems içine ekle.\n"
            . "- \"Yangın Tesisatı\" gibi üst başlıkları, altında ayrı kontrol grupları varsa tek sistem olarak kullanıp alt sistemleri birleştirme.\n"
            . "- Yangın Pompa Dairesi; pompa ekipmanlarının bulunduğu sistemdir. Sprinkler, Su Deposu, Hidrant, İtfaiye Su Alma/Verme gibi raporda ayrı kontrol edilen veya ayrı fiziksel sistem olarak tanımlanan grupları otomatik olarak Yangın Pompa Dairesi içine katma.\n"
            . "- Aynı fiziksel sistemi farklı adlarla tekrar etme; rapordaki en anlamlı sistem adını koru.\n"
            . "- systems dizisini oluşturmadan önce rapordaki tüm ayrı kontrol gruplarını çıkar ve hiçbir ayrı grubun atlanmadığını kontrol et.\n"
            . "- systems dizisi raporun kapsamını eksik bırakmamalıdır. Özellikle ekipmanı olmayan ancak kontrol maddeleri bulunan sistemleri de dahil et.\n";

        return [
            'model' => config('services.nvidia_nim.text_model'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt . $nvidiaSystemDiscovery],
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => 0.1,
            'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_object'],
            'chat_template_kwargs' => ['thinking' => false],
        ];
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
