<?php

namespace App\Services\Ai\PkTakip;

use App\Models\PeriodicEquipmentType;
use App\Services\Ai\CamelotPdfTableExtractor;
use App\Services\PkInstallationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Elektrik ailesi tesisat raporunu (elektrik iç tesisatı, topraklama) okur ve kontrol formu için öneri üretir: rapor
 * bilgileri, genel sonuç, sistemler (sonuç, bulgular) ve rapordaki ekipmanlar (panolar, röleler, ölçüm noktaları;
 * Ekipmanlar'a kayıt açılmaz, sistemin içinde görünür). Okuma hattı yangınla ortak: metin (gerekirse OCR ikizi) → yapay
 * zeka (tesisat türünün kendi talimatı) → Camelot tabloları. Öneri yangın tesisatı okumasındaki kodla hazırlanır
 * (PkInstallationReportReader; değiştirilmeden çağrılır). Rapor başka bir tesisata aitse hangisi olduğu da döner (yanlış
 * yerden yükleme uyarısı). Hiçbir şey kaydetmez.
 */
class PkElectricalReportReader
{
    public function __construct(
        private PkReportText $text,
        private PkElectricalReportAnalyzer $analyzer,
        private PkReportTableReader $tableReader,
        private PkInstallationReportReader $installationReader,
        private CamelotPdfTableExtractor $camelot
    ) {
    }

    /**
     * Yapay zeka ile okuma: tek istek, tekrar deneme yok. $types: tesisatın türü ("systems" ilişkisi yüklü); $allTypes: bütün
     * tesisat türleri (raporun hangi tesisata ait olduğu bunlardan seçilir).
     */
    public function read(UploadedFile $pdf, Collection $types, Collection $allTypes): array
    {
        $startedAt = microtime(true);
        $type = $types->first();
        $document = $this->text->read($pdf, false, ' ya da "Elle doldur" ile devam edin');
        try {
            try {
                $semantic = $this->analyzer->analyze($document['pages'], self::promptCatalog($types), $document['input'] === 'ocr', (string) $type?->slug, self::otherTypes($allTypes, $type));
            } catch (RuntimeException $exception) {
                throw PkGeminiError::friendly($exception, ' ya da "Elle doldur" ile devam edin');
            }
            // Tablolar taranmış raporda OCR ikizinden okunur; kimlik no + konum (tesisat okumasıyla aynı).
            $tables = $this->readTables($document['ocr_pdf'] ?? (string) $pdf->getRealPath(), $semantic);
        } finally {
            $this->text->forget($document['ocr_pdf']);
        }

        return ['duration_s' => round(microtime(true) - $startedAt, 1)] + $this->summarize($semantic, $tables, $types, $allTypes, $document['input']);
    }

    // Test sayfasında tesisat türünün talimatıyla kaydedilmiş analiz: yapay zekaya gitmeden aynı öneri.
    public function fromFixture(array $fixture, Collection $types, Collection $allTypes): array
    {
        return $this->summarize((array) ($fixture['semantic'] ?? []), (array) ($fixture['tables'] ?? []), $types, $allTypes, $fixture['input'] ?? 'text') + [
            'fixture' => ['id' => $fixture['fixture_id'] ?? null, 'original_file_name' => $fixture['original_file_name'] ?? null, 'model' => $fixture['model'] ?? null],
        ];
    }

    // Talimattaki diğer tesisat türleri (tesisatın kendi türü hariç): [['slug', 'name'], ...].
    public static function otherTypes(Collection $allTypes, ?PeriodicEquipmentType $type): array
    {
        return $allTypes->reject(fn (PeriodicEquipmentType $other) => $other->id === $type?->id)
            ->map(fn (PeriodicEquipmentType $other) => ['slug' => $other->slug, 'name' => $other->name])->values()->all();
    }

    // Yapay zekaya verilen katalog: Tesis Özellikleri kartı hariç (kartı okuma kodu tesis bilgilerinden oluşturur; yapay zeka
    // onu sistem diye yazmaz).
    public static function promptCatalog(Collection $types): array
    {
        return PkInstallationReportReader::promptCatalog($types->map(function (PeriodicEquipmentType $type) {
            $copy = clone $type;

            return $copy->setRelation('systems', $type->systems->reject(fn ($system) => in_array($system->slug, PkInstallationService::FACILITY_SYSTEM_SLUGS, true))->values());
        }));
    }

    /**
     * Tablo adımı (tesisat okumasındaki gibi: kimlik no + konum), ardından birleşik başlık tamamlaması (mergedColumns). Test
     * sayfasının elektrik okuması da bunu kullanır.
     */
    public function readTables(string $pdfPath, array $semantic): array
    {
        $tables = $this->tableReader->read($pdfPath, $semantic, true);
        try {
            return $this->mergedColumns($pdfPath, $semantic, $tables);
        } catch (Throwable) {
            // Tamamlama yapılamazsa tablo adımının sonucu olduğu gibi kalır.
            return $tables;
        }
    }

    /**
     * Birleşik başlık: PDF'te iki sütunun başlığı tek hücre çizilmişse (ör. "NOKTA ADI MAX. ÖLÇÜM"; değerler altında iki ayrı
     * sütunda) tablo adımı başlığı birebir eşleştiremez ve bu sütunlar bütün satırlarda boş kalır. Yalnızca o durumda
     * Camelot'un ham tablosuna bakılır: başlık hücresi, içindeki sütun adlarının (tarifteki başlıkların) sırayla
     * birleşimiyse ve hücrenin altındaki dolu değer sütunu sayısı ad sayısıyla birebir tutuyorsa değerler sırayla
     * yerleştirilir. Satırlar kimlik (sıra no) ile eşleşir; aynı no birden fazla satırdaysa o no doldurulmaz. Okunmuş değer
     * değiştirilmez, yalnızca boş olan doldurulur; tutmayan durumda hiçbir şey yapılmaz (tahmin yok).
     */
    private function mergedColumns(string $pdfPath, array $semantic, array $tables): array
    {
        $rawTables = null;
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                if (($definition['instance_structure']['equipment_axis'] ?? null) !== 'rows') {
                    continue;
                }
                $systemName = trim((string) ($system['system_name'] ?? ''));
                $equipmentName = trim((string) ($definition['equipment_name'] ?? ''));
                $indexes = array_keys(array_filter((array) ($tables['equipment'] ?? []), fn ($row) => ($row['system_name'] ?? null) === $systemName && ($row['equipment_name'] ?? null) === $equipmentName));
                $attributes = collect((array) ($definition['attributes'] ?? []))
                    ->map(fn ($attribute) => ['field' => trim((string) ($attribute['field'] ?? '')), 'pattern' => $this->plain((string) ($attribute['source_pattern'] ?? ''))])
                    ->filter(fn (array $attribute) => $attribute['field'] !== '' && $attribute['pattern'] !== '')
                    ->values();
                $missing = $attributes->filter(fn (array $attribute) => collect($indexes)->every(fn (int $index) => trim((string) ($tables['equipment'][$index]['properties'][$attribute['field']] ?? '')) === ''));
                if (!$indexes || $missing->isEmpty()) {
                    continue;
                }
                $rawTables ??= $this->rawTables($pdfPath);
                $identity = $this->plain((string) ($definition['instance_structure']['identity_field'] ?? ''));
                $tables = $this->fillFromRaw($tables, $indexes, $attributes->all(), $identity, $rawTables);
            }
        }

        return $tables;
    }

    // Camelot'un ham tabloları: önce çizgili (lattice), sonra çizgisiz (stream) okuma.
    private function rawTables(string $pdfPath): array
    {
        $raw = $this->camelot->extract($pdfPath);

        return collect((array) ($raw['tables'] ?? []))
            ->filter(fn ($table) => is_array($table) && is_array($table['data'] ?? null))
            ->sortBy(fn (array $table) => ($table['flavor'] ?? '') === 'lattice' ? 0 : 1)
            ->values()
            ->all();
    }

    private function fillFromRaw(array $tables, array $indexes, array $attributes, string $identity, array $rawTables): array
    {
        // Kimlik no → satır (aynı no birden fazla satırdaysa o no atlanır).
        $byCode = collect($indexes)->groupBy(fn (int $index) => $this->plain((string) ($tables['equipment'][$index]['code'] ?? '')))
            ->filter(fn (Collection $group, string $code) => $code !== '' && $group->count() === 1)
            ->map(fn (Collection $group) => $group->first());

        foreach ($rawTables as $table) {
            $rows = array_values(array_map(fn ($row) => array_map(fn ($cell) => trim((string) $cell), (array) $row), $table['data']));
            foreach ($rows as $h => $header) {
                $identityColumn = array_search($identity, array_map(fn (string $cell) => $this->plain($cell), $header), true);
                if ($identityColumn === false) {
                    continue;
                }
                $filled = array_keys(array_filter($header, fn (string $cell) => $cell !== ''));
                foreach ($filled as $position => $column) {
                    $cell = $this->plain($header[$column]);
                    // Hücrede geçen sütun adları, hücredeki sırasıyla; hücre bunların birleşimi değilse birleşik başlık değildir.
                    $named = collect($attributes)->filter(fn (array $attribute) => str_contains($cell, $attribute['pattern']))
                        ->sortBy(fn (array $attribute) => mb_strpos($cell, $attribute['pattern']))->values();
                    if ($named->count() < 2 || $named->pluck('pattern')->implode(' ') !== $cell) {
                        continue;
                    }
                    // Hücrenin altı: bir sonraki başlık hücresine kadarki sütunlar; değer satırlarında dolu olanlar.
                    $end = ($filled[$position + 1] ?? count($header)) - 1;
                    $dataRows = array_filter(array_slice($rows, $h + 1), fn (array $row) => $byCode->has($this->plain($row[$identityColumn] ?? '')));
                    $columns = array_values(array_filter(range($column, $end), fn (int $index) => collect($dataRows)->contains(fn (array $row) => ($row[$index] ?? '') !== '')));
                    if (count($columns) !== $named->count()) {
                        continue;
                    }
                    foreach ($dataRows as $row) {
                        $index = $byCode->get($this->plain($row[$identityColumn] ?? ''));
                        foreach ($named as $k => $attribute) {
                            $value = $row[$columns[$k]] ?? '';
                            if ($value !== '' && trim((string) ($tables['equipment'][$index]['properties'][$attribute['field']] ?? '')) === '') {
                                $tables['equipment'][$index]['properties'][$attribute['field']] = $value;
                            }
                        }
                    }
                }
            }
        }

        return $tables;
    }

    // Karşılaştırma için sade metin: küçük harf (Türkçe), satır sonları ve fazla boşluklar tek boşluk.
    private function plain(string $text): string
    {
        $text = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $text), 'UTF-8');

        return implode(' ', array_filter(explode(' ', str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text)), fn (string $part) => $part !== ''));
    }

    private function summarize(array $semantic, array $tables, Collection $types, Collection $allTypes, string $input): array
    {
        $summary = $this->installationReader->fromFixture(['semantic' => $semantic, 'tables' => $tables, 'input' => $input], $types);
        unset($summary['fixture'], $summary['duration_s']);
        $detected = $allTypes->firstWhere('slug', $semantic['extracted_data']['equipment_type']['slug'] ?? null);
        // Yapay zekaya göre raporun kapsamı: tek bir bölümün raporuysa sistem raporu (Kontrol Ekle'den yüklense de tesisatın
        // genel kartını değiştirmez); değilse tesisat raporu. Bu bilgiyi taşımayan eski okumalar tesisat raporu sayılır.
        $scope = ($semantic['template']['template_type'] ?? null) === 'sistem_raporu' ? 'system' : null;
        $info = collect((array) ($semantic['extracted_data']['report_information'] ?? []))
            ->filter(fn ($row) => is_array($row) && isset($row['key']))
            ->mapWithKeys(fn ($row) => [$row['key'] => $row['value'] ?? null]);
        $summary['equipment_tables'] = $this->equipmentTables($semantic);
        $summary = $this->withoutGeneralRecord($summary);
        $summary = $this->withFacility($summary, $semantic, $types->first(), $scope);

        return $summary + [
            'report_scope' => $scope,
            // Raporun ait olduğu tesisat türü (bütün tesisat türleri arasından): tesisatınkinden farklıysa rapor ilgili sekmeden
            // yüklenmelidir.
            'detected_type' => $detected ? ['id' => $detected->id, 'slug' => $detected->slug, 'name' => $detected->name] : null,
            // Raporun NOTLAR bölümü (yoksa ya da "-" ise boş).
            'notes' => $this->notes($info['notlar'] ?? null),
            // Rapor belirli bir bina / bölüm içinse adı (tesis geneli ise boş): form, tesisat kaydının adıyla karşılaştırır.
            'building' => $this->notes($info['bina'] ?? null),
            'duration_s' => 0,
        ];
    }

    // Talimattaki "Tesisat Geneli" kaydı (tesisat raporunun genel kontrol listesi; şema en az bir kayıt istediği için) sistem
    // değildir: listeden çıkarılır, bulguları raporun genel bulgusu olur.
    private const GENERAL_RECORD = 'tesisat geneli';

    private function withoutGeneralRecord(array $summary): array
    {
        $names = [];
        $summary['systems'] = collect($summary['systems'] ?? [])->reject(function (array $system) use (&$names, &$summary) {
            if (($system['system_id'] ?? null) !== null || $this->plain((string) ($system['name'] ?? '')) !== self::GENERAL_RECORD) {
                return false;
            }
            $names = [...$names, ...$system['source_names']];
            $summary['general_findings'] = array_values(array_unique([...($summary['general_findings'] ?? []), ...$system['findings']]));

            return true;
        })->values()->all();
        $summary['equipment_rows'] = collect($summary['equipment_rows'] ?? [])->reject(fn (array $row) => in_array($row['system_name'], $names, true))->values()->all();

        return $summary;
    }

    /**
     * Rapordaki tablolar (ölçüm sonuçları) raporda nasılsa öyle gösterilir: her tablo için kimlik sütununun başlığı ve değer
     * sütunları PDF'deki sırayla (yapay zekanın tarifinden; değerleri Camelot okur).
     */
    private function equipmentTables(array $semantic): array
    {
        $result = [];
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                $result[] = [
                    'system_name' => trim((string) ($system['system_name'] ?? '')),
                    'equipment_name' => trim((string) ($definition['equipment_name'] ?? '')),
                    'code_label' => trim((string) ($definition['instance_structure']['identity_field'] ?? '')) ?: null,
                    'columns' => collect((array) ($definition['attributes'] ?? []))
                        ->map(fn ($attribute) => trim((string) (is_array($attribute) ? ($attribute['field'] ?? '') : '')))
                        ->filter()->unique()->values()->all(),
                ];
            }
        }

        return $result;
    }

    /**
     * Tesis Özellikleri (bilgi kartı): tesisat raporundaki tesis bilgileri, raporun kendi etiketleriyle, kartın sistemine tek
     * satır olarak eklenir. Sistem raporunda eklenmez (kart, genel durum gibi tesisat raporundan gelir). Yapay zeka tesis
     * bilgilerini yine de bir sistem olarak yazdıysa o kayıt atılır, bulguları genel bulgu olur.
     */
    private function withFacility(array $summary, array $semantic, ?PeriodicEquipmentType $type, ?string $scope): array
    {
        $card = $type?->systems->first(fn ($system) => in_array($system->slug, PkInstallationService::FACILITY_SYSTEM_SLUGS, true));
        if (!$card) {
            return $summary;
        }
        $names = [];
        $summary['systems'] = collect($summary['systems'] ?? [])->reject(function (array $system) use ($card, &$names, &$summary) {
            if (($system['system_id'] ?? null) !== $card->id) {
                return false;
            }
            $names = [...$names, ...$system['source_names']];
            $summary['general_findings'] = array_values(array_unique([...($summary['general_findings'] ?? []), ...$system['findings']]));

            return true;
        })->values()->all();
        $summary['equipment_rows'] = collect($summary['equipment_rows'] ?? [])->reject(fn (array $row) => in_array($row['system_name'], $names, true))->values()->all();

        $values = $scope ? [] : $this->facilityValues($semantic);
        if (!$values) {
            return $summary;
        }
        $summary['systems'][] = [
            'key' => 'c' . $card->id,
            'system_id' => $card->id,
            'name' => $card->name,
            'sort_order' => $card->sort_order,
            'source_names' => [$card->name],
            'equipment_summary' => [],
            'equipment_count' => 0,
            'findings' => [],
            'status' => null,
        ];
        $summary['equipment_rows'][] = [
            'key' => 'facility',
            'system_name' => $card->name,
            'equipment_name' => $card->name,
            'code' => null,
            'place' => null,
            'brand' => null,
            'model' => null,
            'serial_no' => null,
            'properties' => $values,
            'status' => null,
            'findings' => [],
        ];
        $summary['equipment_tables'][] = ['system_name' => $card->name, 'equipment_name' => $card->name, 'code_label' => null, 'columns' => array_keys($values)];

        return $summary;
    }

    // Tesis bilgileri: etiket → değer (raporun etiketi; yoksa alanın Türkçe adı), raporun sırasıyla; boş ve "-" atlanır.
    private function facilityValues(array $semantic): array
    {
        $labels = collect((array) ($semantic['template']['facility_or_project_information']['fields'] ?? []))
            ->filter(fn ($field) => is_array($field) && isset($field['key']))
            ->mapWithKeys(fn (array $field) => [$field['key'] => rtrim(trim((string) (((array) ($field['label_patterns'] ?? []))[0] ?? '')), ': ')]);
        $values = [];
        foreach ((array) ($semantic['extracted_data']['facility_information'] ?? []) as $row) {
            $key = is_array($row) ? trim((string) ($row['key'] ?? '')) : '';
            $value = is_array($row) && is_scalar($row['value'] ?? null) ? trim((string) $row['value']) : '';
            if ($key === '' || $value === '' || $value === '-') {
                continue;
            }
            $label = ($labels[$key] ?? '') ?: (self::FACILITY_LABELS[$key] ?? Str::ucfirst(str_replace('_', ' ', $key)));
            $values[$label] = $value;
        }

        return $values;
    }

    // Raporda etiketi yazmayan tesis bilgilerinin adı (talimattaki key'ler).
    private const FACILITY_LABELS = [
        'sebeke_tipi' => 'Şebeke tipi',
        'sebeke_gerilimi' => 'Şebeke gerilimi',
        'enerji_saglayan_kurulus' => 'Enerji sağlayan kuruluş',
        'proje_var_mi' => 'Proje var mı',
        'tek_hat_semasi_var_mi' => 'Tek hat şeması var mı',
        'kontrol_nedeni' => 'Kontrol nedeni',
        'topraklayici_tipi' => 'Topraklayıcı tipi',
        'yapi_cinsi' => 'Yapı cinsi',
        'kullanim_amaci' => 'Kullanım amacı',
        'olcum_metodu' => 'Ölçüm metodu',
        'hava_durumu' => 'Hava durumu',
        'zemin_nem_durumu' => 'Toprak durumu',
        'pano_ekipman_tanimlamasi' => 'Pano / ekipman tanımlaması',
        'son_kontrol_tarihi' => 'Son kontrol tarihi',
        // Yangın algılama
        'yapi_turu' => 'Yapı türü',
        'tehlike_sinifi' => 'Tehlike sınıfı',
        'kullanim_sinifi' => 'Kullanım sınıfı',
        'toplam_kullanim_alani' => 'Toplam kullanım alanı',
        'bina_yuksekligi' => 'Bina yüksekliği',
        'yapi_yuksekligi' => 'Yapı yüksekliği',
        'kat_sayisi' => 'Kat sayısı',
        'bolum_sayisi' => 'Bölüm sayısı',
        'algilama_sistemi' => 'Algılama sistemi',
        'uyari_sistemi' => 'Uyarı sistemi',
        'adresli_mi' => 'Adresli mi',
        'kontrol_paneli' => 'Kontrol paneli',
        'zon_loop_sayisi' => 'Zon / loop sayısı',
        // Havalandırma ve klima
        'havalandirma_tipi' => 'Havalandırma tipi',
        'mahal_bolum' => 'Mahal / bölüm',
        'toplam_alan' => 'Toplam alan',
        'hacim' => 'Hacim',
        'kisi_sayisi' => 'Kişi sayısı',
        'ortam_sicakligi' => 'Ortam sıcaklığı',
        'ortam_nemi' => 'Ortam nemi',
        'dis_hava_kosullari' => 'Dış hava koşulları',
        // Paratoner
        'yildirimdan_korunma_tipi' => 'Yıldırımdan korunma tesisat tipi',
        'test_rogari_var_mi' => 'Test rogarı var mı',
        'es_potansiyel_bara_var_mi' => 'Eş potansiyel bara var mı',
        'paratoner_adresi' => 'Paratoner adresi',
        'koruma_duzeyi' => 'Koruma düzeyi',
        // Akümülatör
        'aku_tipi' => 'Akü tipi',
        'aku_odasi_var_mi' => 'Akü odası var mı',
        // Trafo merkezi
        'tm_no' => 'TM no',
        'abone_no' => 'Abone no',
        'trafo_merkezi_tipi' => 'Trafo merkezi tipi',
        'trafo_gucu' => 'Trafo gücü',
        'trafo_tipi' => 'Trafo tipi',
        'trafo_sayisi' => 'Trafo sayısı',
    ];

    // Notlar: metin (liste geldiyse satır satır); "-" ya da boşsa null.
    private function notes($value): ?string
    {
        $text = trim(is_array($value) ? implode("\n", array_filter($value, 'is_scalar')) : (is_scalar($value) ? (string) $value : ''));

        return $text === '' || $text === '-' ? null : $text;
    }
}
