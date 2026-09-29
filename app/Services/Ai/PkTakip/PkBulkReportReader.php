<?php

namespace App\Services\Ai\PkTakip;

use App\Models\PeriodicEquipmentType;

/**
 * Toplu tüp kontrol formu (yangın söndürme cihazları): tek raporda çok sayıda tüp. "Rapordan Ekipman Tanımla" ile okunur;
 * yapay zeka raporu toplu tüp raporu olarak sınıflandırınca (report_category "ysc") tüp satırlarını Camelot okur.
 * Bölümler (sistemler) raporda yazdığı gibi, sonuç ve bulgularıyla; tüp bazında uygunluk satırın kendi sonucundan.
 */
class PkBulkReportReader
{
    public const TYPE_SLUG = 'yangin-sondurme-cihazi';

    // Yapay zekanın rapor tipi sınıflandırması: taşınabilir yangın söndürme cihazı (tüp) raporu.
    public const CATEGORY = 'ysc';

    // Kriter işaretleri: bu değerleri taşıyan sütun tüpün özelliği değil, sorusudur.
    private const MARKS = ['✔', '✓', '√', '✘', '✗', 'x', '-', '–', '—'];

    public function __construct(private PkReportTableReader $tableReader)
    {
    }

    public static function isBulk(array $semantic): bool
    {
        return ($semantic['extracted_data']['report_category'] ?? null) === self::CATEGORY;
    }

    public function type(): ?PeriodicEquipmentType
    {
        return PeriodicEquipmentType::query()->where('slug', self::TYPE_SLUG)->first();
    }

    // Tablo adımı: kimlik yer sütunu (bkz. prepare); aynı yerde aynı no'lu farklı tüpler ayrı kalır.
    public function tables(string $pdfPath, array $semantic): array
    {
        return $this->tableReader->read($pdfPath, $this->prepare($semantic), false, true);
    }

    /**
     * Tüp no bazı satırlarda boş olabilir (numarasız tüp) ve Taşeron'un tablo okuyucusu kimlik hücresi boş satırı atlar:
     * satır tablosunda kimlik yer sütunu değilse yer sütunu kimlik olur, eski kimlik (no) özelliklere geçer.
     */
    public function prepare(array $semantic): array
    {
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $s => $system) {
            foreach ((array) ($system['equipment_definitions'] ?? []) as $d => $definition) {
                $structure = (array) ($definition['instance_structure'] ?? []);
                if (($structure['equipment_axis'] ?? null) !== 'rows' || $this->isPlace((string) ($structure['identity_field'] ?? ''))) {
                    continue;
                }
                $attributes = (array) ($definition['attributes'] ?? []);
                $header = collect([...(array) ($structure['header_patterns'] ?? []), ...array_map(fn ($attribute) => $attribute['source_pattern'] ?? $attribute['field'] ?? '', $attributes)])
                    ->first(fn ($title) => $this->isPlace((string) $title));
                if (!$header) {
                    continue;
                }
                $old = (string) $structure['identity_field'];
                $attributes = array_values(array_filter($attributes, fn ($attribute) => !$this->isPlace((string) ($attribute['source_pattern'] ?? $attribute['field'] ?? ''))));
                array_unshift($attributes, ['field' => $old, 'source_pattern' => $old, 'value' => null]);
                $semantic['template']['fire_systems']['systems'][$s]['equipment_definitions'][$d]['instance_structure']['identity_field'] = $header;
                $semantic['template']['fire_systems']['systems'][$s]['equipment_definitions'][$d]['attributes'] = $attributes;
            }
        }

        return $semantic;
    }

    /**
     * Kayıt penceresi için: bölümler (raporda yazdığı gibi; sonuç, bulgular, tüp sayısı), hiçbir bölüme bağlı olmayan
     * genel bulgular ve tüp satırları (yer, no, etiket, kapasite, dolum tarihi, katalog özellikleri, sonuç).
     */
    public function summarize(array $semantic, array $tables, PeriodicEquipmentType $type): array
    {
        $prepared = $this->prepare($semantic);
        $identityIsPlace = [];
        foreach ((array) ($prepared['template']['fire_systems']['systems'] ?? []) as $system) {
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                $identityIsPlace[$this->key((string) ($system['system_name'] ?? ''), (string) ($definition['equipment_name'] ?? ''))]
                    = $this->isPlace((string) ($definition['instance_structure']['identity_field'] ?? ''));
            }
        }

        $rows = [];
        foreach ((array) ($tables['equipment'] ?? []) as $index => $row) {
            $rows[] = $this->row($row, $index, $identityIsPlace[$this->key((string) $row['system_name'], (string) $row['equipment_name'])] ?? false, (array) ($type->variants ?? []));
        }

        $findings = collect((array) ($semantic['extracted_data']['findings'] ?? []))->filter('is_array');
        $text = fn (array $finding) => trim(trim((string) ($finding['description'] ?? '')) . (filled($finding['severity'] ?? null) && filled($finding['description'] ?? null) ? ' (' . trim((string) $finding['severity']) . ')' : ''));
        $systems = collect((array) ($semantic['template']['fire_systems']['systems'] ?? []))->map(fn ($system) => [
            'name' => (string) ($system['system_name'] ?? ''),
            'status' => in_array($system['verdict']['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $system['verdict']['status'] : null,
            'findings' => $findings->filter(fn ($finding) => ($finding['system_name'] ?? null) === ($system['system_name'] ?? null))->map($text)->filter()->values()->all(),
            'equipment_count' => collect($rows)->where('system_name', $system['system_name'] ?? null)->count(),
        ])->filter(fn ($system) => $system['name'] !== '')->values();
        $names = $systems->pluck('name')->all();

        return [
            'type' => ['id' => $type->id, 'slug' => $type->slug, 'name' => $type->name, 'variants' => $type->variants ?? [], 'variant_label' => $type->variant_label],
            'systems' => $systems->all(),
            'general_findings' => $findings->reject(fn ($finding) => in_array($finding['system_name'] ?? null, $names, true))->map($text)->filter()->unique()->values()->all(),
            'rows' => $rows,
            'table_error' => $tables['error'] ?? null,
        ];
    }

    private function row(array $row, int $index, bool $identityIsPlace, array $variants): array
    {
        $place = $identityIsPlace ? trim((string) ($row['code'] ?? '')) : trim((string) ($row['location'] ?? ''));
        $code = $identityIsPlace ? '' : trim((string) ($row['code'] ?? ''));
        $out = ['serial_no' => null, 'brand' => null, 'model' => null];
        $typeText = '';
        $capacityText = '';
        $fillDate = null;
        $specs = [];
        $extra = [];

        foreach ((array) ($row['properties'] ?? []) as $name => $value) {
            $value = trim((string) $value);
            $title = $this->title((string) $name);
            if ($value === '' || in_array(mb_strtolower($value, 'UTF-8'), self::MARKS, true)
                || str_contains($title, 'değerlendirme') || str_contains($title, 'sonuç') || str_contains($title, 'uygunluk')) {
                continue;
            }
            match (true) {
                $this->isPlace($title) => $place = $place !== '' ? $place : $value,
                str_contains($title, 'seri') => $out['serial_no'] = $value,
                $this->isNumber($title) => $code = $code !== '' ? $code : $value,
                str_contains($title, 'marka') => $out['brand'] = $value,
                str_contains($title, 'model') => $out['model'] = $value,
                // "Cihaz tipi ve kapasitesi" gibi birleşik sütun hem tip hem kapasite yazısıdır.
                str_contains($title, 'kapasite') || str_contains($title, 'ağırlık') => [$capacityText, $typeText] = [$value, str_contains($title, 'tip') ? $value : $typeText],
                str_contains($title, 'dolum') => $fillDate = $this->monthYear($value),
                str_contains($title, 'tip') || str_contains($title, 'cins') || str_contains($title, 'tür') => $typeText = $value,
                str_contains($title, 'üretim') => $specs['Üretim yılı'] = $value,
                str_contains($title, 'hidrostatik') => $specs['Hidrostatik test tarihi'] = $value,
                default => $extra[(string) $name] = $value,
            };
        }
        $capacity = $this->kilograms($capacityText !== '' ? $capacityText : $typeText);
        if ($capacity !== null) {
            $specs['Dolum ağırlığı'] = $capacity;
        }
        if ($fillDate !== null) {
            $specs['Son dolum tarihi'] = $fillDate;
        }

        return [
            'key' => 'b' . $index,
            'system_name' => (string) ($row['system_name'] ?? ''),
            'place' => $place !== '' ? $place : null,
            'code' => $code !== '' ? $code : null,
            // Rapordaki tip yazısı ("6KG KKT"): etiket ve kapasite bundan; ekranda da gösterilir.
            'type_text' => $typeText !== '' ? $typeText : null,
            'variant' => $this->variant($typeText . ' ' . $capacityText, $variants),
            'capacity' => $capacity,
            'fill_date' => $fillDate,
            'serial_no' => $out['serial_no'],
            'brand' => $out['brand'],
            'model' => $out['model'],
            // Katalogdaki adlarla (kayıtta teknik özelliklere yazılır) + katalogda karşılığı olabilecek diğer sütunlar.
            'properties' => $specs + $extra,
            'status' => in_array($row['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $row['status'] : null,
        ];
    }

    // Söndürücü etiketi: tip yazısında kelime olarak geçen katalog seçeneği ya da bilinen karşılığı (KURU KİMYEVİ TOZ → KKT).
    private function variant(string $text, array $variants): ?string
    {
        // "6KG KKT" → " 6kg kkt ": kelimeler boşlukla ayrılır, seçenek adı kelime olarak aranır ("su" "sulu"da bulunmaz).
        $text = ' ' . $this->title(str_replace(['-', '/', '(', ')', ',', '.', '%'], ' ', $text)) . ' ';
        $aliases = [
            // "ABC" yazılmaz: köpüklü ABC tipi de var.
            'KKT' => ['kuru kimyevi', 'kuru kimyasal', ' toz '],
            'CO2' => [' co 2 ', 'karbondioksit'],
            'Köpük' => ['köpük', ' afff '],
            'Su' => [' sulu '],
            'Islak kimyasal' => ['ıslak kimyasal', 'islak kimyasal', 'wet chemical'],
            'Temiz gazlı' => ['temiz gaz', ' fm200 ', ' fm 200 ', 'novec'],
        ];
        foreach ($variants as $variant) {
            foreach ([' ' . $this->title((string) $variant) . ' ', ...($aliases[$variant] ?? [])] as $needle) {
                if (trim($needle) !== '' && str_contains($text, $needle)) {
                    return $variant;
                }
            }
        }

        return null;
    }

    // "6KG KKT", "6 kg", "12,5 KG" → "6", "12.5" (sayının ardından kg gelmeli).
    private function kilograms(string $text): ?string
    {
        $text = $this->title($text);
        $position = mb_strpos($text, 'kg');
        if ($position === false) {
            return null;
        }
        $before = rtrim(mb_substr($text, 0, $position));
        $digits = '';
        for ($i = mb_strlen($before) - 1; $i >= 0; $i--) {
            $char = mb_substr($before, $i, 1);
            if (!ctype_digit($char) && $char !== ',' && $char !== '.') {
                break;
            }
            $digits = $char . $digits;
        }
        $digits = trim(str_replace(',', '.', $digits), '.');

        return $digits !== '' && is_numeric($digits) ? (string) (float) $digits : null;
    }

    // "Mayıs 24" → "Mayıs 2024"; başka biçimler olduğu gibi.
    private function monthYear(string $value): string
    {
        $parts = array_values(array_filter(explode(' ', trim($value)), fn ($part) => $part !== ''));
        if (count($parts) === 2 && strlen($parts[1]) === 2 && ctype_digit($parts[1])) {
            return $parts[0] . ' 20' . $parts[1];
        }

        return $value;
    }

    private function isPlace(string $title): bool
    {
        $title = $this->title($title);

        return in_array($title, ['yer', 'mahal', 'kat', 'bölüm'], true) || str_contains($title, 'bulunduğu yer') || str_contains($title, 'konum') || str_contains($title, 'yerleşim yeri');
    }

    // "Tüp No", "No", "Cihaz No", "Tüp Numarası" (seri no ve sıra no hariç).
    private function isNumber(string $title): bool
    {
        if (str_contains($title, 'seri') || str_contains($title, 'sıra')) {
            return false;
        }

        return $title === 'no' || str_ends_with($title, ' no') || str_ends_with($title, ' no.') || str_contains($title, 'numara');
    }

    // Küçük harf, boşluklar tekleştirilmiş, "İ"nin birleşik noktası atılmış.
    private function title(string $value): string
    {
        $words = array_filter(explode(' ', str_replace(["\t", "\r", "\n"], ' ', $value)), fn (string $word) => $word !== '');

        return str_replace("\u{0307}", '', mb_strtolower(implode(' ', $words), 'UTF-8'));
    }

    private function key(string $systemName, string $equipmentName): string
    {
        return mb_strtolower(trim($systemName) . '|' . trim($equipmentName), 'UTF-8');
    }
}
