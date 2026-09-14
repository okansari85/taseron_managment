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
                    // NVIDIA bazı modellerde istenen İngilizce JSON anahtarlarını
                    // Türkçeleştirebiliyor. Provider sınırında canonical Gemini
                    // sözleşmesine normalize ediyoruz; Gemini koduna dokunmuyoruz.
                    $decoded = $this->normalizeSemanticContract($decoded);

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

Sana biçimi önceden bilinmeyen bir yangın tesisatı/periyodik kontrol PDF'sinin TAMAMININ metni verilecek.
Firma şablonuna veya sabit bölüm sırasına güvenme. PDF'nin tamamını baştan sona değerlendir.

ÇOK ÖNEMLİ ÇIKTI SÖZLEŞMESİ:
- Çıktıdaki JSON ANAHTARLARI SADECE aşağıdaki İngilizce adlar olabilir: report, systems, findings, control_date, next_control_date, report_no, company_name, overall_result, name, category, system_name, description.
- RAPOR, SİSTEMLER, BULGULAR gibi Türkçe JSON anahtarları KULLANMA.
- Başka JSON anahtarı oluşturma.
- Çıktıyı Türkçe içerikle doldurabilirsin; ancak JSON anahtarları İngilizce ve birebir yukarıdaki adlarla kalmalıdır.
- Aşağıdaki örnek yapının dışına çıkma.

DOĞRU ÇIKTI YAPISI:
{
  "report": {
    "control_date": "YYYY-MM-DD veya null",
    "next_control_date": "YYYY-MM-DD veya null",
    "report_no": "string veya null",
    "company_name": "string veya null",
    "overall_result": "uygun | uygun_degil | null"
  },
  "systems": [
    {
      "name": "raporda gerçekten kontrol edilen sistem adı",
      "category": "kategori"
    }
  ],
  "findings": [
    {
      "system_name": "sistem adı veya null",
      "description": "ayrıntılı bulgu"
    }
  ]
}

1. RAPOR
- control_date
- next_control_date
- report_no
- company_name
- overall_result
- Tarihleri mümkünse YYYY-MM-DD formatına dönüştür.
- Raporda bulunan değerleri null yapma.
- overall_result için rapordaki anlamı koruyarak uygun_degil / uygun kullan.

2. SİSTEMLER
Raporda gerçekten kontrol edilen TÜM ayrı sistemleri/grupları belirle.
Her sistem yalnızca:
- name
- category
alanlarına sahip olmalıdır.

Sistem keşfi için PDF'nin tamamını değerlendir. Özellikle kontrol/değerlendirme bölümündeki ayrı başlıkları ve kontrol gruplarını incele.

ÖNEMLİ:
- Bir sistemde ekipman listesi bulunmaması o sistemi atlama nedeni değildir.
- Ayrı kontrol edilen Su Deposu, Yağmurlama/Sprinkler, Hidrant/İtfaiye Bağlantısı, Yangın Dolapları ve Yangın Pompa Bölmesi gibi grupları birbirine birleştirme.
- Bir sistemin bulgusu başka bir sisteme aitse o bulguyu yanlış sisteme yazma.
- Üst başlık altında ayrı fiziksel veya kontrol grupları varsa alt sistemleri tek bir üst sistem altında birleştirme.
- Aynı sistemi farklı isimlerle iki kez oluşturma.
- Raporda kontrol edildiği açıkça görülen her ayrı sistem systems içinde olmalıdır.

KATEGORİLER:
- yangin_dolabi
- yangin_pompasi
- hidrant
- sprinkler
- su_alma_verme
- su_deposu
- sabit_boru_tesisati
- gazli_sondurme
- diger

3. BULGULAR
Uygunsuzlukları sistem bazında çıkar.
Her kayıt yalnızca:
- system_name
- description
alanlarından oluşur.

Bulguda ekipman kodu açıkça geçiyorsa description içinde aynen koru.
Kodu kendin uydurma.
Aynı bulguyu bileşen bazında tekrar etme.

ÇOK ÖNEMLİ:
- Ekipman listesi oluşturma.
- Yangın dolabı kodlarını veya lokasyonlarını JSON'a ayrı alan olarak çıkarma.
- equipment_matrix oluşturma.
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

BULGU SİSTEM EŞLEŞTİRMESİ:
- Her bulguyu yalnızca ait olduğu sisteme bağla.
- Bir bulgu başka bir sistemden bahsediyorsa onu otomatik olarak mevcut sisteme kopyalama.
- Örneğin "Su deposu seviye göstergesi" bulgusu yalnızca su deposu sistemine aitse Yangın Pompa Bölmesi veya Yangın Dolapları altında tekrar etme.
- Sprinkler bulgusunu pompa sistemi altında tekrar etme.
- Pompa bulgusunu sprinkler sistemi altında tekrar etme.
- Emin olunamayan bulguyu başka sisteme taşımak yerine system_name = null kullan.
- Aynı açıklamanın farklı sistemlerde gerçekten ayrı bir bulgu olduğu açıkça anlaşılmıyorsa tekrar üretme.

BELGE / PROJE / KAYIT:
Fiziksel ekipman olmayan proje, belge veya kayıt kontrollerini fiziksel ekipman olarak üretme. Bunlara ilişkin önemli uygunsuzlukları findings içinde ilgili sistemle ilişkilendir veya sistem net değilse null kullan.

Rapor adını veya firma adını değiştirme/normalize etme.

SON KONTROL:
JSON'u döndürmeden önce şunları kontrol et:
1. En üst seviyede yalnızca report, systems, findings var mı?
2. systems elemanlarında yalnızca name ve category var mı?
3. findings elemanlarında yalnızca system_name ve description var mı?
4. components, equipment_matrix, control_count, nonconforming_count, U, UD, N, equipment_refs gibi alanlardan hiçbiri var mı? VARSA SİL.
5. RAPOR, SİSTEMLER, BULGULAR gibi Türkçe anahtarlar var mı? VARSA doğru İngilizce anahtarlara dönüştür.
6. Raporda bulunan report bilgileri null yapılmış mı? Yapma.
7. Ayrı kontrol edilen sistemlerden biri atlanmış mı? Varsa ekle.

Yalnızca geçerli JSON döndür. Markdown, açıklama, kod bloğu veya JSON dışı metin döndürme.
PROMPT;
    }

    private function normalizeSemanticContract(array $result): array
    {
        // NVIDIA/gpt-oss bazen anahtarları prompttaki anlamla Türkçeleştirebiliyor.
        // Provider sınırında canonical Gemini sözleşmesine çeviriyoruz.
        $result = $this->renameKey($result, 'RAPOR', 'report');
        $result = $this->renameKey($result, 'SİSTEMLER', 'systems');
        $result = $this->renameKey($result, 'SISTEMLER', 'systems');
        $result = $this->renameKey($result, 'BULGULAR', 'findings');

        $report = is_array($result['report'] ?? null) ? $result['report'] : [];
        $report = $this->renameKey($report, 'RAPOR', 'report');
        $report = $this->renameKey($report, 'kontrol_tarihi', 'control_date');
        $report = $this->renameKey($report, 'sonraki_kontrol_tarihi', 'next_control_date');
        $report = $this->renameKey($report, 'rapor_no', 'report_no');
        $report = $this->renameKey($report, 'firma_adi', 'company_name');
        $report = $this->renameKey($report, 'genel_sonuc', 'overall_result');

        $result['report'] = [
            'control_date' => $this->dateOrNull($report['control_date'] ?? null),
            'next_control_date' => $this->dateOrNull($report['next_control_date'] ?? null),
            'report_no' => $this->stringOrNull($report['report_no'] ?? null),
            'company_name' => $this->stringOrNull($report['company_name'] ?? null),
            'overall_result' => $this->normalizeResult($report['overall_result'] ?? null),
        ];

        $systems = is_array($result['systems'] ?? null) ? $result['systems'] : [];
        $normalizedSystems = [];
        foreach ($systems as $system) {
            if (! is_array($system)) {
                continue;
            }
            $system = $this->renameKey($system, 'isim', 'name');
            $system = $this->renameKey($system, 'ad', 'name');
            $system = $this->renameKey($system, 'kategori', 'category');

            $name = $this->stringOrNull($system['name'] ?? null);
            $category = $this->normalizeCategory($system['category'] ?? null);
            if ($name === null) {
                continue;
            }

            $normalizedSystems[] = [
                'name' => $name,
                'category' => $category ?? 'diger',
            ];
        }
        $result['systems'] = $normalizedSystems;

        $findings = is_array($result['findings'] ?? null) ? $result['findings'] : [];
        $normalizedFindings = [];
        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $finding = $this->renameKey($finding, 'sistem_adi', 'system_name');
            $finding = $this->renameKey($finding, 'sistem', 'system_name');
            $finding = $this->renameKey($finding, 'aciklama', 'description');
            $finding = $this->renameKey($finding, 'bulgu', 'description');

            $description = $this->stringOrNull($finding['description'] ?? null);
            if ($description === null) {
                continue;
            }

            $normalizedFindings[] = [
                'system_name' => $this->stringOrNull($finding['system_name'] ?? null),
                'description' => $description,
            ];
        }
        $result['findings'] = $normalizedFindings;

        return [
            'report' => $result['report'],
            'systems' => $result['systems'],
            'findings' => $result['findings'],
        ];
    }

    private function renameKey(array $array, string $from, string $to): array
    {
        if (! array_key_exists($from, $array)) {
            return $array;
        }

        if (! array_key_exists($to, $array)) {
            $array[$to] = $array[$from];
        }

        unset($array[$from]);

        return $array;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return $value;
    }

    private function normalizeResult(mixed $value): ?string
    {
        $value = mb_strtolower(trim((string) ($value ?? '')), 'UTF-8');
        if ($value === '') {
            return null;
        }
        if (str_contains($value, 'uygun değil') || str_contains($value, 'uygun degil') || str_contains($value, 'uygun değildir') || str_contains($value, 'uygun degildir')) {
            return 'uygun_degil';
        }
        if ($value === 'uygun' || str_contains($value, 'kullanılması uygundur') || str_contains($value, 'kullanilmasi uygundur')) {
            return 'uygun';
        }
        return $value;
    }

    private function normalizeCategory(mixed $value): ?string
    {
        $value = mb_strtolower(trim((string) ($value ?? '')), 'UTF-8');
        if ($value === '') {
            return null;
        }

        return match (true) {
            str_contains($value, 'dolap') => 'yangin_dolabi',
            str_contains($value, 'pompa') => 'yangin_pompasi',
            str_contains($value, 'hidrant') || str_contains($value, 'itfaiye bağlant') => 'hidrant',
            str_contains($value, 'sprink') || str_contains($value, 'yağmurlama') || str_contains($value, 'yagmurlama') => 'sprinkler',
            str_contains($value, 'depo') => 'su_deposu',
            str_contains($value, 'su alma') || str_contains($value, 'su verme') => 'su_alma_verme',
            str_contains($value, 'gazlı') || str_contains($value, 'gazli') => 'gazli_sondurme',
            default => $value,
        };
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
