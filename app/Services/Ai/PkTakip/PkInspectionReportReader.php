<?php

namespace App\Services\Ai\PkTakip;

use App\Models\PeriodicEquipmentType;
use App\Models\PkEquipment;
use App\Services\PeriodicEquipmentSpecCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Ekipman profilinden yüklenen tek bir periyodik kontrol raporunu PkReportAnalyzer (kriterler hariç) ile okur
 * ve kontrol formu için öneri üretir: genel sonuç, kontrol tarihi, geçerlilik (gelecek kontrol) tarihi, bulgular,
 * raporun bu ekipmana ait olup olmadığı (match). Ham analiz "semantic" anahtarında döner, kayıt için saklanır.
 * Hiçbir şey kaydetmez; kullanıcı öneriyi görüp düzeltir, kayıt normal kontrol ekleme ile yapılır.
 * Test için fromFixture(): Gemini çağrılmaz, test sayfasında kaydedilmiş analiz aynı yoldan geçer.
 */
class PkInspectionReportReader
{
    private const MONTHS = [
        'ocak' => 1, 'şubat' => 2, 'subat' => 2, 'mart' => 3, 'nisan' => 4, 'mayıs' => 5, 'mayis' => 5, 'haziran' => 6,
        'temmuz' => 7, 'ağustos' => 8, 'agustos' => 8, 'eylül' => 9, 'eylul' => 9, 'ekim' => 10, 'kasım' => 11, 'kasim' => 11, 'aralık' => 12, 'aralik' => 12,
    ];

    public function __construct(
        private PkReportText $text,
        private PkReportAnalyzer $analyzer,
        private PkReportEquipmentMatcher $matcher,
        private PeriodicEquipmentSpecCatalog $catalog,
        private PkBulkReportReader $bulk
    ) {
    }

    public function read(UploadedFile $pdf, PkEquipment $equipment): array
    {
        $startedAt = microtime(true);
        // Tek ekipman raporu: görüntü PDF de okunur (kısa ise, PDF'in kendisi gönderilir).
        $document = $this->text->read($pdf, hint: ' ya da "Elle doldur" ile devam edin');
        $semantic = $this->analyze($document, [
            'type' => $equipment->type?->name,
            'slug' => $equipment->type?->slug,
            'label' => $equipment->type?->variant_label,
            'options' => $equipment->type?->variants ?? [],
            'specs' => $this->catalog->specsFor($equipment->type)->map(fn ($spec) => ['key' => $spec->key, 'name' => $spec->name, 'unit' => $spec->unit])->values()->all(),
        ]);

        return $this->suggest($semantic, $equipment) + ['duration_s' => round(microtime(true) - $startedAt, 1), 'input' => $document['input']];
    }

    // Test sayfasında kaydedilmiş fikstür: Gemini'ye gitmeden aynı öneri ve eşleştirme.
    public function fromFixture(array $fixture, PkEquipment $equipment): array
    {
        return $this->suggest((array) ($fixture['semantic'] ?? []), $equipment) + [
            'duration_s' => 0,
            'fixture' => ['id' => $fixture['fixture_id'] ?? null, 'original_file_name' => $fixture['original_file_name'] ?? null, 'model' => $fixture['model'] ?? null],
            'input' => $fixture['input'] ?? 'text',
        ];
    }

    // Kayıtlı analiz başka bir ekipmana bağlanırken (rapordan ekipman tanımlama): Gemini'ye gitmeden aynı öneri.
    public function fromSemantic(array $semantic, PkEquipment $equipment): array
    {
        return $this->suggest($semantic, $equipment) + ['duration_s' => 0];
    }

    // Rapordan ekipman tanımlama: tür bilinmiyor, Gemini'ye tüm katalog verilir ve türü seçer. Rapor toplu tüp kontrol
    // formuysa tüp satırları tablodan okunur (taranmışsa OCR ikizinden; görüntüden okunduysa tablo yok).
    public function readUnknown(UploadedFile $pdf): array
    {
        $startedAt = microtime(true);
        $document = $this->text->read($pdf);
        try {
            $semantic = $this->analyze($document, ['catalog' => $this->catalog->promptCatalog()], false);
            $tables = PkBulkReportReader::isBulk($semantic) && !$document['image']
                ? $this->bulk->tables($document['ocr_pdf'] ?? (string) $pdf->getRealPath(), $semantic)
                : null;
        } finally {
            $this->text->forget($document['ocr_pdf']);
        }

        return $this->forUnknown($semantic, $tables) + ['duration_s' => round(microtime(true) - $startedAt, 1), 'input' => $document['input']];
    }

    public function fromFixtureUnknown(array $fixture): array
    {
        $semantic = (array) ($fixture['semantic'] ?? []);
        // Toplu tüp formu: tablolar fikstürün PDF'inden yeniden okunur (yapay zeka çağrılmaz).
        $pdf = (string) ($fixture['ocr_pdf_path'] ?? $fixture['pdf_path'] ?? '');
        $tables = PkBulkReportReader::isBulk($semantic) && ($fixture['input'] ?? 'text') !== 'image' && $pdf !== '' && \Illuminate\Support\Facades\Storage::disk('local')->exists($pdf)
            ? $this->bulk->tables(\Illuminate\Support\Facades\Storage::disk('local')->path($pdf), $semantic)
            : null;

        return $this->forUnknown($semantic, $tables) + [
            'duration_s' => 0,
            'fixture' => ['id' => $fixture['fixture_id'] ?? null, 'original_file_name' => $fixture['original_file_name'] ?? null, 'model' => $fixture['model'] ?? null],
            'input' => $fixture['input'] ?? 'text',
        ];
    }

    // Gemini'nin seçtiği tür (katalogda, ekipman kategorisinde) ya da ekipman / bölüm adından tahmin; öneri o türe göre.
    // Toplu tüp kontrol formunda tür yangın söndürme cihazıdır; "bulk": bölümler ve tüp satırları (kayıt penceresi için).
    private function forUnknown(array $semantic, ?array $tables = null): array
    {
        $bulkType = PkBulkReportReader::isBulk($semantic) ? $this->bulk->type() : null;
        $slug = (string) ($semantic['extracted_data']['equipment_type']['slug'] ?? '');
        $type = $bulkType
            ?? ($slug !== '' ? PeriodicEquipmentType::query()->where('slug', $slug)->whereHas('category', fn ($q) => $q->where('kind', 'equipment'))->first() : null)
            ?? $this->catalog->guessType($semantic);
        $equipment = new PkEquipment(['equipment_type_id' => $type?->id]);
        $equipment->setRelation('type', $type);

        return $this->suggest($semantic, $equipment) + [
            'detected_type' => $type ? ['id' => $type->id, 'slug' => $type->slug, 'name' => $type->name] : null,
            'type_evidence' => $semantic['extracted_data']['equipment_type']['evidence'] ?? null,
        ] + ($bulkType ? [
            'bulk' => $this->bulk->summarize($semantic, $tables ?? ['equipment' => [], 'error' => 'Tüp tablosu okunamadı (rapor görüntüden okundu ya da PDF bulunamadı).'], $bulkType),
            'tables' => $tables,
        ] : []);
    }

    private function suggest(array $semantic, PkEquipment $equipment): array
    {
        $data = (array) ($semantic['extracted_data'] ?? []);

        $info = collect((array) ($data['report_information'] ?? []))
            ->filter(fn ($row) => is_array($row) && isset($row['key']))
            ->mapWithKeys(fn ($row) => [$row['key'] => $row['value'] ?? null]);

        $controlDate = $this->parseDate($info['control_date'] ?? null) ?? $this->parseDate($info['report_date'] ?? null);
        $validity = $this->parseDate($info['validity_date'] ?? null);
        // Raporda geçerlilik tarihi yoksa ekipman türünün varsayılan periyodu kullanılır.
        $period = $equipment->type?->default_period_months;
        $nextDate = $validity ?? ($controlDate && $period ? $controlDate->addMonths($period) : null);

        $overall = (array) ($data['overall_result'] ?? []);
        $status = in_array($overall['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $overall['status'] : null;

        // Rapor bulguyu derecelendiriyorsa (örn. asansörde Kırmızı / Sarı / Mavi) derece metnin sonunda kalır.
        $findings = collect((array) ($data['findings'] ?? []))
            ->map(fn ($finding) => trim(trim((string) ($finding['description'] ?? '')) . (filled($finding['severity'] ?? null) && filled($finding['description'] ?? null) ? ' (' . trim((string) $finding['severity']) . ')' : '')))
            ->filter()
            ->unique()
            ->values();

        return [
            'status' => $status,
            'control_date' => $controlDate?->format('Y-m-d'),
            'next_control_date' => $nextDate?->format('Y-m-d'),
            'next_control_source' => $validity ? 'report' : ($nextDate ? 'period' : null),
            'report_no' => $info['report_no'] ?? null,
            'report_date' => $info['report_date'] ?? null,
            'company_title' => $info['company_title'] ?? null,
            'overall_text' => $overall['text'] ?? null,
            // Periyodik kontrolü yapan kuruluş + kontrol eden / onaylayan uzmanlar (metinde yazanlar).
            'inspection_body' => $data['inspection_body'] ?? null,
            'findings' => $findings,
            // Tek örnekli ekipman bilgisi (seri no / kod gibi): kullanıcı doğru ekipmanın raporu mu diye bakabilsin.
            'equipment' => $this->singleEquipment($semantic),
            'match' => $this->matcher->match($equipment, $semantic),
            'semantic' => $semantic,
        ];
    }

    // Tek istek; otomatik tekrar deneme yok (her deneme istek sınırından yer). Tekrar denemeyi kullanıcı yapar.
    // Taranmış PDF'in OCR ikizi yalnızca bu okuma için; sonra silinir.
    // $forgetOcr false: OCR ikizi okumadan sonra da gerekiyor (toplu tüp formunun tabloları); çağıran siler.
    private function analyze(array $document, array $equipmentTag, bool $forgetOcr = true): array
    {
        try {
            return $this->analyzer->analyze($document['pages'], $equipmentTag, $document['image'], $document['input'] === 'ocr');
        } catch (RuntimeException $exception) {
            throw PkGeminiError::friendly($exception, ' ya da "Elle doldur" ile devam edin');
        } finally {
            if ($forgetOcr) {
                $this->text->forget($document['ocr_pdf']);
            }
        }
    }

    private function singleEquipment(array $semantic): array
    {
        $items = [];
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                if (($definition['instance_structure']['equipment_axis'] ?? $definition['equipment_axis'] ?? null) !== 'none') {
                    continue;
                }
                $attributes = collect((array) ($definition['attributes'] ?? []))
                    ->filter(fn ($attribute) => filled($attribute['value'] ?? null))
                    ->map(fn ($attribute) => ['field' => $attribute['field'] ?? null, 'value' => $attribute['value']])
                    ->values()
                    ->all();
                $items[] = [
                    'name' => $definition['equipment_name'] ?? null,
                    'identity_field' => $definition['instance_structure']['identity_field'] ?? null,
                    'identity' => $definition['instance_structure']['identity_value'] ?? $definition['identity_value'] ?? null,
                    'attributes' => $attributes,
                    'status' => $definition['verdict']['status'] ?? null,
                ];
            }
        }

        return $items;
    }

    // Rapor tarihleri: 10.04.2026, 10/04/2026, 10-04-2026, 2026-04-10 ya da "10 Nisan 2026".
    private function parseDate(mixed $value): ?CarbonImmutable
    {
        $text = mb_strtolower(trim((string) $value), 'UTF-8');
        if ($text === '') {
            return null;
        }
        try {
            if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $m)) {
                return CarbonImmutable::createStrict((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
            }
            if (preg_match('/(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})/', $text, $m)) {
                $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

                return CarbonImmutable::createStrict($year, (int) $m[2], (int) $m[1])->startOfDay();
            }
            if (preg_match('/(\d{1,2})\s+(\p{L}+)\s+(\d{4})/u', $text, $m) && isset(self::MONTHS[$m[2]])) {
                return CarbonImmutable::createStrict((int) $m[3], self::MONTHS[$m[2]], (int) $m[1])->startOfDay();
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
