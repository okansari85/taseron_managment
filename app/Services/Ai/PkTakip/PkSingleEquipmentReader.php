<?php

namespace App\Services\Ai\PkTakip;

use App\Services\PeriodicEquipmentSpecCatalog;
use ReflectionMethod;
use RuntimeException;

/**
 * Tek ekipman raporunu kısa talimatla (PkSingleEquipmentPrompt) okur. Gerçek akışta PkShortPromptRouter'dan (sayfalar
 * hazır), test sayfası ve pk:kisa-talimat-dene komutunda PDF yolundan (metin pdfium ile) çağrılır. Tek istek; tekrar
 * deneme yok.
 */
class PkSingleEquipmentReader
{
    public function __construct(
        private PkReportText $text,
        private PkSingleEquipmentPrompt $prompt,
        private PeriodicEquipmentSpecCatalog $catalog
    ) {
    }

    /** @return array{semantic: array, duration_s: float, provider: string, model: ?string, page_count: int} */
    public function read(string $pdfPath, string $typeSlug, ?string $provider = null): array
    {
        // shortcut: PkReportText::pdfiumPages private; okuma sırası orada PkAiProvider::agents() ile seçiliyor.
        $pages = (array) (new ReflectionMethod(PkReportText::class, 'pdfiumPages'))->invoke($this->text, $pdfPath);
        $startedAt = microtime(true);
        $provider ??= PkAiProvider::name();
        try {
            $semantic = $this->readPages($pages, $typeSlug, $provider);
        } catch (RuntimeException $exception) {
            throw PkGeminiError::friendly($exception);
        }

        return [
            'semantic' => $semantic,
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'provider' => $provider,
            'model' => PkAiProvider::model($provider),
            'page_count' => count($pages),
        ];
    }

    // Ajanlarla okuma (yerel model, asansör dışı): rapor bilgisi (PkHeaderPass), ekipman (PkSingleEquipmentAgent) ve bulgular
    // (PkFindingsPass) ayrı küçük istekler; tek sistem, tek ekipman. Sözleşme readPages ile aynı.
    public function readPagesAgents(array $pages, string $typeSlug, ?string $provider = null, ?string $pdfPath = null): array
    {
        $text = collect(array_values($pages))->map(fn ($page, $index) => trim((string) $page) === '' ? null : '--- SAYFA ' . ($index + 1) . " ---\n" . trim((string) $page))->filter()->implode("\n\n");
        if ($text === '') {
            throw new RuntimeException('PDF\'te metin yok (taranmış olabilir); kısa talimat yalnızca metinli PDF okur.');
        }
        $catalog = $this->catalog->promptCatalog();
        $type = collect((array) ($catalog['types'] ?? []))->firstWhere('slug', $typeSlug);
        if (!$type) {
            throw new RuntimeException("Katalogda '{$typeSlug}' türü yok.");
        }
        $semantic = ['template' => ['fire_systems' => ['systems' => []]], 'extracted_data' => [
            'report_category' => 'tekli_ekipman', 'extraction_mode' => 'single_equipment', 'equipment_type' => ['slug' => $typeSlug, 'evidence' => null],
            'report_information' => [], 'facility_information' => [], 'result_legend' => [], 'findings' => [], 'overall_result' => null, 'inspection_body' => null,
            'equipment_tag' => ['label' => null, 'value' => null, 'evidence' => null], 'equipment_specs' => [],
        ]];
        // Asansör: sonuç sınıfları (UYGUN / HAFİF KUSURLU / KUSURLU / GÜVENSİZ) ve müşteri kuralı asansör talimatından; bulgular
        // sayfa sayfa renk sınıfıyla (elevatorFindings), müşteri ayrı küçük istekle (elevatorCustomer).
        $elevator = str_contains($typeSlug, 'asansor');
        $semantic = app(PkHeaderPass::class)->apply($semantic, $text, $provider, $elevator ? (string) str(PkSingleEquipmentPrompt::ELEVATOR_RULES)->after('GENEL SONUÇ (extracted_data.overall_result)') : '');
        $semantic = app(PkSingleEquipmentAgent::class)->apply($semantic, $text, $type, (array) ($catalog['common_specs'] ?? []), $provider, $pdfPath);
        if ($elevator) {
            $semantic = $this->elevatorResult($semantic, (string) (array_values($pages)[0] ?? ''), $provider);
            $semantic['extracted_data']['findings'] = $this->elevatorFindings($pages, $type['name'], $provider);
            $semantic['extracted_data']['report_information'] = $this->elevatorCustomer((array) $semantic['extracted_data']['report_information'], (string) (array_values($pages)[0] ?? ''), $provider);

            return $semantic;
        }

        return app(PkFindingsPass::class)->apply($semantic, $text, $provider);
    }

    // Sayfalar hazırken (gerçek akış): JSON sözleşmesindeki semantic.
    public function readPages(array $pages, string $typeSlug, ?string $provider = null): array
    {
        $parts = [];
        foreach (array_values($pages) as $index => $page) {
            if (trim((string) $page) !== '') {
                $parts[] = '--- SAYFA ' . ($index + 1) . " ---\n" . trim((string) $page);
            }
        }
        if (!$parts) {
            throw new RuntimeException('PDF\'te metin yok (taranmış olabilir); kısa talimat yalnızca metinli PDF okur.');
        }

        $catalog = $this->catalog->promptCatalog();
        $semantic = PkAiProvider::client($provider)->extract($this->prompt->build($catalog, $typeSlug), implode("\n\n", $parts));
        // Şema zorlaması kapalıyken model ara sıra boş / yarım JSON döndürebiliyor; boş sonuç kaydedilmez.
        if (blank($semantic['extracted_data']['report_category'] ?? null) || !is_array($semantic['extracted_data']['findings'] ?? null)) {
            throw new RuntimeException('Yapay zeka eksik yanıt verdi (rapor bilgisi yok); tekrar deneyin.');
        }
        // Teknik özellik: yalnız katalogdaki anahtarlar, her biri bir kez (model talimata rağmen uydurabiliyor).
        $type = collect((array) ($catalog['types'] ?? []))->firstWhere('slug', $typeSlug);
        $allowed = collect([...(array) ($catalog['common_specs'] ?? []), ...(array) ($type['specs'] ?? [])])->pluck('key')->all();
        $semantic['extracted_data']['equipment_specs'] = collect((array) ($semantic['extracted_data']['equipment_specs'] ?? []))
            ->filter(fn ($spec) => in_array($spec['key'] ?? null, $allowed, true))
            ->unique('key')->values()->all();
        if (str_contains($typeSlug, 'asansor')) {
            $system = $semantic['template']['fire_systems']['systems'][0]['system_name'] ?? ($type['name'] ?? null);
            $semantic['extracted_data']['findings'] = $this->elevatorFindings($pages, $system, $provider);
            $semantic['extracted_data']['report_information'] = $this->elevatorCustomer((array) ($semantic['extracted_data']['report_information'] ?? []), (string) (array_values($pages)[0] ?? ''), $provider);
        }

        return $semantic;
    }

    // Asansör uygunsuzluk listesi onlarca uzun madde; küçük model tek istekte birleştiriyor / atlıyor. Her sayfa ayrı,
    // yalnız madde no + metin + renk soran küçük istekle okunur.
    private function elevatorFindings(array $pages, ?string $systemName, ?string $provider): array
    {
        $item = ['type' => 'object', 'properties' => ['no' => ['type' => 'string'], 'text' => ['type' => 'string'], 'color' => ['type' => 'string', 'enum' => ['Kırmızı', 'Sarı', 'Mavi', 'yok']]], 'required' => ['no', 'text', 'color']];
        $schema = ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => $item]], 'required' => ['items']];
        $findings = [];
        $seen = [];
        foreach (array_values($pages) as $index => $page) {
            if (trim((string) $page) === '') {
                continue;
            }
            $items = (array) (PkAiProvider::client($provider)->extract(self::ELEVATOR_PAGE_PROMPT, '--- SAYFA ' . ($index + 1) . " ---\n" . trim((string) $page), 12000, schema: $schema)['items'] ?? []);
            foreach ($items as $found) {
                $text = trim(trim((string) ($found['no'] ?? '')) . ' ' . trim((string) ($found['text'] ?? '')));
                // Aynı madde (numarasıyla) bir kez: sayfa sınırında bölünen madde iki sayfada farklı metinle tekrar yazılıyordu
                // (NuExtract3 #63: 94 madde yerine 192).
                $no = trim((string) ($found['no'] ?? ''));
                if ($text !== '' && !collect($findings)->contains('description', $text) && ($no === '' || !isset($seen[$no]))) {
                    $seen[$no] = true;
                    $color = $found['color'] ?? null;
                    $findings[] = ['id' => 'F' . (count($findings) + 1), 'system_name' => $systemName, 'description' => $text, 'source_pages' => [$index + 1], 'severity' => in_array($color, ['Kırmızı', 'Sarı', 'Mavi'], true) ? $color : null, 'ambiguous' => false];
                }
            }
        }

        return $findings;
    }

    // Asansör sonuç sınıfı ayrı küçük istekle, yalnız 1. sayfadan (sınıf tablosu orada): genel okumada küçük model "GÜVENSİZ
    // 60 GÜN 10.12.2025" satırını kaçırıp "uygun" diyordu (NuExtract3 #63). Kural asansör talimatından aynen.
    private function elevatorResult(array $semantic, string $firstPage, ?string $provider): array
    {
        if (trim($firstPage) === '') {
            return $semantic;
        }
        $rules = (string) str(PkSingleEquipmentPrompt::ELEVATOR_RULES)->after('GENEL SONUÇ (extracted_data.overall_result)');
        $found = PkAiProvider::client($provider)->extract(
            "Sana bir asansör periyodik kontrol raporunun 1. sayfası verilecek. Yalnız periyodik kontrol sonucunun sınıfını oku.\n" . $rules,
            $firstPage,
            1000,
            schema: ['type' => 'object', 'properties' => [
                'text' => ['type' => ['string', 'null']],
                'status' => ['type' => ['string', 'null'], 'enum' => ['uygun', 'uygun_degil', 'uygulanamiyor', null]],
                'validity_date' => ['type' => ['string', 'null']],
            ], 'required' => ['text', 'status', 'validity_date']]
        );
        if (filled($found['status'] ?? null)) {
            $semantic['extracted_data']['overall_result'] = ['text' => $found['text'] ?? null, 'status' => $found['status']];
        }
        if (filled($found['validity_date'] ?? null)) {
            $info = collect((array) ($semantic['extracted_data']['report_information'] ?? []))->keyBy('key');
            $info['validity_date'] = ['key' => 'validity_date', 'value' => $found['validity_date']];
            $semantic['extracted_data']['report_information'] = $info->values()->all();
        }

        return $semantic;
    }

    // Asansör raporunda antette muayene firması ve belediye yazar; model müşteri yerine onları yazıyor. Müşteri (asansörün
    // bulunduğu bina / işletme) 1. sayfadan ayrı küçük istekle okunur; okunamazsa ana okumadaki değer kalır.
    private function elevatorCustomer(array $info, string $firstPage, ?string $provider): array
    {
        if (trim($firstPage) === '') {
            return $info;
        }
        $schema = ['type' => 'object', 'properties' => ['company_title' => ['type' => ['string', 'null']], 'address' => ['type' => ['string', 'null']]], 'required' => ['company_title', 'address']];
        $found = PkAiProvider::client($provider)->extract(<<<'PROMPT'
Sana bir asansör periyodik kontrol raporunun ilk sayfası verilecek. Asansörün bulunduğu binanın / işletmenin adını ve asansörün adresini bul.
- company_title: asansör bilgilerindeki "ADRES" satırının başında ya da bina sorumlusu / bina bilgileri bölümünde yazan yapı / firma adı (örn. "ADRES: ABC GIDA A.Ş. ÖRNEK MAH. ... NO: 5" → "ABC GIDA A.Ş."). Sayfanın en üstündeki muayene firması ve raporun adına düzenlendiği belediye / kurum company_title DEĞİLDİR. Yapı adı yazmıyorsa null.
- address: asansörün adresi (ADRES satırının yapı adından sonraki kısmı), raporda yazdığı gibi; yoksa null.
PROMPT, $firstPage, 1500, schema: $schema);

        foreach (['company_title', 'address'] as $key) {
            if (filled($found[$key] ?? null)) {
                $info = collect($info)->reject(fn ($row) => ($row['key'] ?? null) === $key)->push(['key' => $key, 'value' => trim((string) $found[$key])])->values()->all();
            }
        }

        return $info;
    }

    private const ELEVATOR_PAGE_PROMPT = <<<'PROMPT'
Sana bir asansör periyodik kontrol raporunun TEK bir sayfası verilecek. Sayfadaki uygunsuzluk / kusur maddelerini çıkar.
- Her numaralı madde (örn. "1.36.2 (K) Elektrik kuvvet panosu topraklama bağlantısı yapılmalıdır.") ayrı bir öğedir; hiçbirini atlama ya da birleştirme.
- no = maddenin numarası (örn. "1.36.2"). text = maddenin TAM metni, raporda yazdığı gibi; renk kodunu ("(K)") metne yazma. Madde birden çok satıra bölünmüşse satırları birleştir.
- color = maddenin renk / kusur kategorisi: (K) ya da Kırmızı → "Kırmızı", (S) ya da Sarı → "Sarı", (M) ya da Mavi → "Mavi". Renk bölüm başlığında veriliyorsa ("KIRMIZI KUSURLAR" gibi) o bölümdeki maddelere onu yaz; hiç belirtilmemişse "yok".
- Altında numaralı maddeler olan grup başlıkları (örn. "1.36. Topraklama**"), rapor ve asansör bilgileri, sonuç tablosu, imza, sayfa numarası ve e-imza notu öğe DEĞİLDİR.
- Sayfada uygunsuzluk maddesi yoksa items = [].
PROMPT;
}
