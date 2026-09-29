<?php

namespace App\Services\Ai\PkTakip;

use App\Models\PeriodicEquipmentType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tesisat raporunu okur (Yangın Tesisatı, Yangın Algılama ve Uyarı Sistemleri) ve kayıt formu için öneri üretir:
 * tesisat türü, rapor bilgileri, genel sonuç, kapsanan sistemler (sonuç, ekipman özeti, bulgular).
 * Okuma hattı ekipmanlarla ortak: metin (gerekirse OCR ikizi) → yapay zeka (kriterler hariç) → Camelot tabloları.
 * Türü ve sistem adlarını yapay zeka tesisat kataloğundan seçer (tür equipment_type alanına yazılır, şema aynı);
 * burada yalnızca yapay zekanın yazdığı sistem adı katalogdaki adla birebir karşılaştırılır.
 * Hiçbir şey kaydetmez; kullanıcı öneriyi görüp düzeltir.
 */
class PkInstallationReportReader
{
    private const MONTHS = [
        'ocak' => 1, 'şubat' => 2, 'subat' => 2, 'mart' => 3, 'nisan' => 4, 'mayıs' => 5, 'mayis' => 5, 'haziran' => 6,
        'temmuz' => 7, 'ağustos' => 8, 'agustos' => 8, 'eylül' => 9, 'eylul' => 9, 'ekim' => 10, 'kasım' => 11, 'kasim' => 11, 'aralık' => 12, 'aralik' => 12,
    ];

    private array $generalFindings = [];

    public function __construct(
        private PkReportText $text,
        private PkReportAnalyzer $analyzer,
        private PkReportTableReader $tableReader
    ) {
    }

    /**
     * Yapay zeka ile okuma: tek istek, tekrar deneme yok.
     * $types: desteklenen tesisat türleri (PeriodicEquipmentType, "systems" ilişkisi yüklü: katalog sistemleri).
     */
    public function read(UploadedFile $pdf, Collection $types): array
    {
        $startedAt = microtime(true);
        $document = $this->text->read($pdf, false, ' ya da "Elle doldur" ile devam edin');
        try {
            try {
                $semantic = $this->analyzer->analyze($document['pages'], ['installation_catalog' => self::promptCatalog($types)], null, $document['input'] === 'ocr');
            } catch (RuntimeException $exception) {
                throw PkGeminiError::friendly($exception, ' ya da "Elle doldur" ile devam edin');
            }
            // Tablolar taranmış raporda OCR ikizinden okunur.
            // Aynı no farklı konumda ayrı ekipman (dolap no binada tekrar eder): no + konum.
            $tables = $this->tableReader->read($document['ocr_pdf'] ?? (string) $pdf->getRealPath(), $semantic, true);
        } finally {
            $this->text->forget($document['ocr_pdf']);
        }

        return $this->summarize($semantic, $tables, $types) + [
            'duration_s' => round(microtime(true) - $startedAt, 1),
            'input' => $document['input'],
        ];
    }

    // Test sayfasında kaydedilmiş tesisat analizi: yapay zekaya gitmeden aynı öneri.
    public function fromFixture(array $fixture, Collection $types): array
    {
        return $this->summarize((array) ($fixture['semantic'] ?? []), (array) ($fixture['tables'] ?? []), $types) + [
            'duration_s' => 0,
            'input' => $fixture['input'] ?? 'text',
            'fixture' => ['id' => $fixture['fixture_id'] ?? null, 'original_file_name' => $fixture['original_file_name'] ?? null, 'model' => $fixture['model'] ?? null],
        ];
    }

    // Yapay zekaya verilen tesisat kataloğu: [['slug', 'name', 'systems' => [['name', 'equipment' => [['name', 'variants']]]]], ...].
    // Sistemin ekipman türleri ve etiketleri de verilir: ekipman adı ve etiketi katalogdakiyle aynı yazılır.
    public static function promptCatalog(Collection $types): array
    {
        return $types->map(fn (PeriodicEquipmentType $type) => [
            'slug' => $type->slug,
            'name' => $type->name,
            'systems' => $type->systems->map(fn ($system) => [
                'name' => $system->name,
                'equipment' => $system->equipmentTypes->map(fn (PeriodicEquipmentType $equipment) => [
                    'name' => $equipment->name,
                    'variants' => $equipment->variants ?? [],
                ])->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }

    private function summarize(array $semantic, array $tables, Collection $types): array
    {
        $data = (array) ($semantic['extracted_data'] ?? []);
        $info = collect((array) ($data['report_information'] ?? []))
            ->filter(fn ($row) => is_array($row) && isset($row['key']))
            ->mapWithKeys(fn ($row) => [$row['key'] => $row['value'] ?? null]);
        $type = $types->firstWhere('slug', $data['equipment_type']['slug'] ?? null);

        $controlDate = $this->parseDate($info['control_date'] ?? null) ?? $this->parseDate($info['report_date'] ?? null);
        $validity = $this->parseDate($info['validity_date'] ?? null);
        $period = $type?->default_period_months ?? 12;
        $nextDate = $validity ?? ($controlDate ? $controlDate->addMonths($period) : null);
        $overall = (array) ($data['overall_result'] ?? []);

        return [
            // Yapay zekanın seçtiği tesisat türü; seçemediyse boş, kullanıcı seçer.
            'installation_type' => $type ? ['id' => $type->id, 'slug' => $type->slug, 'name' => $type->name] : null,
            'type_evidence' => $data['equipment_type']['evidence'] ?? null,
            'status' => $this->status($overall['status'] ?? null),
            'overall_text' => $overall['text'] ?? null,
            'control_date' => $controlDate?->format('Y-m-d'),
            'next_control_date' => $nextDate?->format('Y-m-d'),
            'next_control_source' => $validity ? 'report' : ($nextDate ? 'period' : null),
            'report_no' => $info['report_no'] ?? null,
            'company_title' => $info['company_title'] ?? null,
            'inspection_body' => $data['inspection_body'] ?? null,
            'systems' => $this->systems($semantic, $tables, $type),
            // Hiçbir sisteme bağlanamayan bulgular.
            'general_findings' => $this->generalFindings,
            'equipment_rows' => $this->equipmentRows($tables),
            'semantic' => $semantic,
            'tables' => $tables,
        ];
    }

    /**
     * Rapordaki sistemler (yapay zekanın yazdığı adlarla). Ad, seçilen türün katalogundaki bir sistemin adıyla aynıysa
     * o sisteme bağlanır; aynı sisteme düşenler birleşir (en kötü sonuç). Ekipman özeti ve bulgular sistem adına göre.
     */
    private function systems(array $semantic, array $tables, ?PeriodicEquipmentType $type): array
    {
        $catalog = $type ? $type->systems->keyBy(fn ($system) => $this->normalize($system->name)) : collect();
        $groups = [];
        $keyOf = [];
        $add = function (string $name) use (&$groups, &$keyOf, $catalog) {
            $match = $catalog->get($this->normalize($name));
            $key = $match ? 'c' . $match->id : 'n' . $this->normalize($name);
            $keyOf[$name] = $key;
            $groups[$key] ??= [
                'key' => $key,
                'system_id' => $match?->id,
                'name' => $match?->name ?? $name,
                'sort_order' => $match?->sort_order ?? 999,
                'source_names' => [],
                'statuses' => [],
                'equipment_summary' => [],
                'equipment_count' => 0,
                'findings' => [],
            ];
            if (!in_array($name, $groups[$key]['source_names'], true)) {
                $groups[$key]['source_names'][] = $name;
            }

            return $key;
        };

        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            $name = trim((string) ($system['system_name'] ?? ''));
            if ($name !== '') {
                $groups[$add($name)]['statuses'][] = $this->status($system['verdict']['status'] ?? null);
            }
        }
        foreach ((array) ($tables['equipment_summary'] ?? []) as $row) {
            if ($key = $keyOf[trim((string) ($row['system_name'] ?? ''))] ?? null) {
                $groups[$key]['equipment_summary'][] = array_intersect_key((array) $row, array_flip(['equipment_name', 'total', 'uygun', 'uygun_degil', 'unknown']));
            }
        }
        foreach ((array) ($tables['equipment'] ?? []) as $row) {
            if ($key = $keyOf[trim((string) ($row['system_name'] ?? ''))] ?? null) {
                $groups[$key]['equipment_count']++;
            }
        }
        // Bulgular: tablo adımınınki (ekipmana bağlı) yoksa yapay zekanınki. Sistemi belirtilmemiş ya da raporun sistem
        // listesinde olmayan bir ada bağlı bulgu raporun genel bulgusu olur (bulgudan yeni sistem açılmaz).
        $findings = (array) (($tables['findings'] ?? []) ?: ($semantic['extracted_data']['findings'] ?? []));
        $general = [];
        foreach ($findings as $finding) {
            $text = $this->findingText((array) $finding);
            $key = $keyOf[trim((string) ($finding['system_name'] ?? ''))] ?? null;
            if ($text === '') {
                continue;
            }
            if (!$key) {
                $general[] = $text;
            } elseif (!in_array($text, $groups[$key]['findings'], true)) {
                $groups[$key]['findings'][] = $text;
            }
        }
        $this->generalFindings = array_values(array_unique($general));

        return collect($groups)
            ->map(function (array $group) {
                $statuses = array_filter($group['statuses']);
                $group['status'] = in_array('uygun_degil', $statuses, true) ? 'uygun_degil' : (in_array('uygun', $statuses, true) ? 'uygun' : null);
                unset($group['statuses']);

                return $group;
            })
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Rapordaki ekipman satırları (tablo adımı): rapordaki sistem adı, no, konum, kimlik alanları, durum ve bulgular.
     * Durum: bulguda geçen ekipman uygun değil; bulgusu yoksa Camelot'un kriterlerinden (bir kriter uygunsuzsa uygun değil);
     * kriteri de yoksa boş (belirtilmemiş), kullanıcı seçer.
     * Bulgular: tablo adımının ekipman koduyla bağladığı bulgular (affected_equipment). Konum / marka / seri no satırda
     * yoksa rapordaki aynı adlı sütundan (kayıtta kullanıcı düzeltebilir).
     */
    private function equipmentRows(array $tables): array
    {
        $rows = collect((array) ($tables['equipment'] ?? []))->values()->map(function ($row, int $index) {
            $row = (array) $row;
            $properties = collect((array) ($row['properties'] ?? []))
                ->mapWithKeys(fn ($value, $key) => [$this->normalize((string) $key) => trim((string) $value)]);
            $property = fn (array $keys) => collect($keys)->map(fn (string $key) => $properties->get($key))->first(fn ($value) => filled($value));
            $code = trim((string) ($row['code'] ?? ''));

            return [
                'key' => 'r' . $index,
                'system_name' => trim((string) ($row['system_name'] ?? '')),
                'equipment_name' => trim((string) ($row['equipment_name'] ?? '')),
                'code' => $code !== '' ? $code : null,
                'place' => trim((string) ($row['location'] ?? '')) ?: $property(['konum', 'bulundugu yer', 'yer', 'mahal', 'kat']),
                'brand' => trim((string) ($row['brand'] ?? '')) ?: $property(['marka']),
                'model' => trim((string) ($row['model'] ?? '')) ?: $property(['model']),
                'serial_no' => trim((string) ($row['serial_no'] ?? '')) ?: $property(['seri no', 'seri numarasi']),
                // Rapordaki teknik özellikler (başlık → değer): kayıtta katalogdaki özelliklerle eşleşenler ekipmana yazılır.
                'properties' => collect((array) ($row['properties'] ?? []))->map(fn ($value) => trim((string) $value))->filter(fn (string $value) => $value !== '')->all(),
                'status' => $this->status($row['status'] ?? null),
                'findings' => [],
            ];
        })->all();

        // Bulgular ekipmana no + konumla bağlanır: tablo adımı bulgudaki noları verir; aynı no birden fazla ekipmanda
        // varsa (ör. dolap no her binada baştan başlar) bulgunun metninde konumu geçen ekipmana bağlanır, hiçbirinin
        // konumu geçmiyorsa bağlanmaz (yanlış ekipmana bulgu yazılmaz).
        foreach ((array) ($tables['findings'] ?? []) as $finding) {
            $text = $this->findingText((array) $finding);
            if ($text === '') {
                continue;
            }
            $where = $this->normalize((string) ($finding['description'] ?? ''));
            foreach ((array) ($finding['affected_equipment'] ?? []) as $code) {
                $code = mb_strtolower(trim((string) $code));
                $candidates = array_keys(array_filter($rows, fn (array $row) => $code !== '' && mb_strtolower((string) $row['code']) === $code));
                if (count($candidates) > 1) {
                    $scores = array_map(fn (int $index) => $this->placeInText((string) $rows[$index]['place'], $where), $candidates);
                    $best = max($scores);
                    $candidates = $best > 0 ? array_values(array_filter($candidates, fn (int $index, int $position) => $scores[$position] === $best, ARRAY_FILTER_USE_BOTH)) : [];
                }
                foreach ($candidates as $index) {
                    if (!in_array($text, $rows[$index]['findings'], true)) {
                        $rows[$index]['findings'][] = $text;
                    }
                }
            }
        }

        // Durum: bulguda geçen ekipman doğrudan uygun değil; bulgusu yoksa kriterlerinden (varsa), yoksa belirtilmemiş.
        return array_map(fn (array $row) => ['status' => $row['findings'] ? 'uygun_degil' : $row['status']] + $row, $rows);
    }

    // Konumun baştan kaç kelimesi bulgu metninde geçiyor (ör. "İDARİ BİNA BODRUM" → "İdari Bina YD-3" bulgusunda 2).
    private function placeInText(string $place, string $text): int
    {
        $words = array_values(array_filter(explode(' ', $this->normalize($place)), fn (string $word) => $word !== ''));
        $count = 0;
        foreach ($words as $index => $word) {
            $phrase = implode(' ', array_slice($words, 0, $index + 1));
            if (mb_strlen($phrase) < 3 || !str_contains($text, $phrase)) {
                break;
            }
            $count = $index + 1;
        }

        return $count;
    }

    // Ad karşılaştırması için: küçük harf, Türkçe karakterler sadeleştirilmiş, boşluklar tekleştirilmiş.
    public function normalize(string $value): string
    {
        $value = str_replace(['İ', 'I'], ['i', 'ı'], $value);

        return trim((string) preg_replace('/\s+/', ' ', Str::ascii(mb_strtolower($value, 'UTF-8'))));
    }

    // Rapor bulguyu derecelendiriyorsa derece metnin sonunda kalır (ekipman tarafıyla aynı).
    private function findingText(array $finding): string
    {
        $description = trim((string) ($finding['description'] ?? ''));
        $severity = trim((string) ($finding['severity'] ?? ''));

        return $description === '' ? '' : $description . ($severity !== '' ? " ({$severity})" : '');
    }

    private function status(mixed $value): ?string
    {
        return in_array($value, ['uygun', 'uygun_degil'], true) ? $value : null;
    }

    // Rapor tarihleri: 10.04.2026, 10/04/2026, 10-04-2026, 2026-04-10 ya da "10 Nisan 2026" (ekipman tarafıyla aynı).
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
