<?php

namespace App\Services\Ai\PkTakip;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * pktakip rapor okuma için yerel sunucu istemcisi (vLLM / Ollama / LM Studio): PkGeminiReportClient ile aynı istem ve
 * aynı çıktı şeması (OpenAI uyumlu chat/completions + response_format json_schema). Adres .env'den (PKTAKIP_LOCAL_*),
 * model ve anahtar Ayarlar → Yapay zeka'dan (yoksa .env). Diğer istemciler değiştirilmez. Tek istek; tekrar deneme yok.
 */
class PkLocalReportClient
{
    // Başka bir yerel model / sunucu (örn. bölüm haritası için okuma modeli); null: Ayarlar'daki yerel sunucu ve model.
    private ?string $baseUrl = null;
    private ?string $model = null;

    public function __construct(private PkGeminiReportClient $gemini)
    {
    }

    public function using(?string $baseUrl, ?string $model): static
    {
        $client = clone $this;
        [$client->baseUrl, $client->model] = [$baseUrl, $model];

        return $client;
    }

    // $schema: verilmezse rapor şeması (tür bulma gibi küçük istekler kendi şemasını verir).
    public function extract(string $systemPrompt, string $userContent, int $maxTokens = 50000, ?array $schema = null): array
    {
        $baseUrl = rtrim((string) ($this->baseUrl ?: PkAiProvider::localUrl()), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Yerel sunucu adresi tanımlı değil (Ayarlar → Yapay zeka → Yerel sunucu ya da .env: PKTAKIP_LOCAL_BASE_URL).');
        }

        $model = $this->model ?: PkAiProvider::model('local');
        $payload = [
            'model' => $model,
            // Talimat önde. Metni öne almak (önek önbelleği) ardışık adımları hızlandırıyor ama küçük modelde tablo yapısı ve
            // asansör okumasını bozuyor (23 Eki 2026 ölçümü); doğruluk önce.
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            // 0: aynı rapor her okumada aynı sonuç (0.1'de tablo türü koşudan koşuya değişiyordu, #18 tablo 5); NuExtract için önerilen.
            'temperature' => 0,
            'max_tokens' => min($maxTokens, (int) config('pktakip.local.max_tokens')),
            // Akış: cevap parça parça gelir; arada vekil sunucu (Cloudflare tüneli gibi) 100 sn sessiz bağlantıyı kesmez.
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'response_format' => config('pktakip.local.json_schema')
                ? ['type' => 'json_schema', 'json_schema' => ['name' => 'pk_report', 'schema' => $this->capArrays(PkJsonSchema::strict($schema ?? $this->gemini->responseSchema())), 'strict' => true]]
                : ['type' => 'json_object'],
        ];
        if (filled($effort = config('pktakip.local.reasoning_effort'))) {
            $payload['reasoning_effort'] = $effort;
        }

        // Anahtarsız sunucu (Ollama) için başlık gönderilmez.
        $request = Http::connectTimeout(10)->timeout((int) config('pktakip.local.timeout'))
            ->withOptions(['verify' => (bool) config('pktakip.local.verify_ssl'), 'stream' => true]);
        if (filled($apiKey = PkAiProvider::apiKey('local'))) {
            $request = $request->withToken($apiKey);
        }

        $startedAt = microtime(true);
        try {
            $response = $request->post($baseUrl . '/chat/completions', $payload);
            // Aradaki vekil (Cloudflare tüneli) bağlantıyı ara ara düşürüyor: yerel sunucuya bir kez daha (ücretli değil).
            if (in_array($response->status(), [502, 520, 522, 524], true)) {
                sleep(3);
                $response = $request->post($baseUrl . '/chat/completions', $payload);
            }
        } catch (\Throwable $exception) {
            Log::error('Yerel sunucu rapor okuma bağlantı hatası', ['duration_s' => round(microtime(true) - $startedAt, 1), 'message' => $exception->getMessage()]);
            throw new RuntimeException('Yerel sunucu isteği başarısız oldu: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('Yerel sunucu isteği başarısız oldu (HTTP ' . $response->status() . '): ' . $response->body());
        }

        [$content, $finish, $usage] = $this->collect($response->toPsrResponse()->getBody());
        $decoded = $this->decode($content);
        if (!is_array($decoded)) {
            Log::warning('Yerel sunucu rapor okuma: çıktı JSON değil', ['finish_reason' => $finish, 'content_length' => mb_strlen($content), 'content_tail' => mb_substr($content, -500)]);
            throw new RuntimeException($finish === 'length'
                ? 'Yerel sunucu yanıtı yarım kaldı (çıktı sınırı); PKTAKIP_LOCAL_MAX_TOKENS artırılabilir.'
                : 'Yerel sunucu çıktısı geçerli JSON değil.');
        }

        Log::info('Yerel sunucu rapor okuma tamamlandı', [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'model' => $model,
            'input_tokens' => $usage['prompt_tokens'] ?? null,
            'output_tokens' => $usage['completion_tokens'] ?? null,
            'output_length' => mb_strlen($content),
        ]);
        PkAiUsage::record($usage['prompt_tokens'] ?? null, $usage['completion_tokens'] ?? null);

        return $decoded;
    }

    /**
     * Akışın (SSE "data: {...}" satırları) içerik parçalarını birleştirir.
     *
     * @return array{0: string, 1: ?string, 2: array}
     */
    private function collect(\Psr\Http\Message\StreamInterface $body): array
    {
        $content = '';
        $finish = null;
        $usage = [];
        $buffer = '';
        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 1);
                $chunk = str_starts_with($line, 'data:') ? json_decode(trim(substr($line, 5)), true) : null;
                if (!is_array($chunk)) {
                    continue;
                }
                $content .= (string) ($chunk['choices'][0]['delta']['content'] ?? '');
                $finish = $chunk['choices'][0]['finish_reason'] ?? $finish;
                $usage = $chunk['usage'] ?? $usage;
            }
        }

        return [$content, $finish, (array) $usage];
    }

    // Her listeye üst sınır: küçük model bir öğeyi döngüye sokup sonsuz tekrar edebiliyor (NuExtract3, #126 / #128 "Yapı Türü"
    // tekrarı, cevap sınırı aşıldı). Gerçek listeler bunun çok altında; sınır yalnız döngüyü keser.
    private function capArrays(array $schema): array
    {
        if (($schema['type'] ?? null) === 'array' || (is_array($schema['type'] ?? null) && in_array('array', $schema['type'], true))) {
            // İç içe listede (alan → etiketler) döngü çarpılır: metin listesi 20, kayıt listesi 50 (gerçekte 1-3 ve en çok ~20).
            $schema['maxItems'] ??= in_array(($schema['items']['type'] ?? null), ['object', ['object', 'null']], true) ? 50 : 20;
        }
        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->capArrays($value);
            }
        }

        return $schema;
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
