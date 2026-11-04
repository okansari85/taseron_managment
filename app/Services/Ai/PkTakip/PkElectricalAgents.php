<?php

namespace App\Services\Ai\PkTakip;

use Illuminate\Support\Str;

/**
 * Tesisat okuması (yerel model): rapor bilgisi (PkHeaderPass), tesis kartı, tablo seçimi ve kusur ajanları. Satırları ve sütunları kod okur (Camelot, PkGridRows).
 * Ajan az tutulur: zincir uzadıkça hata birikiyordu. Tipe özel metinler türün talimatında ve PkTesisatTalimat'ta. Ekipman yolu burayı kullanmaz.
 */
class PkElectricalAgents
{
    public function __construct(private PkGeminiReportClient $contract, private PkTableDefinitions $definitions)
    {
    }

    /**
     * @param string $instruction türün talimatı
     * @param list<string> $systemNames katalog sistemleri
     */
    public function read(string $instruction, string $pdfPath, string $typeSlug, ?string $provider = null, array $systemNames = [], ?string $allText = null): array
    {
        $raw = (array) (app(PkCachedCamelot::class)->extract($pdfPath)['tables'] ?? []);
        $definitions = $this->measurementLists($raw, $pdfPath, $systemNames, self::sections($instruction, [2], false), $provider, $typeSlug === PkElectricalReportAnalyzer::GROUNDING);
        $rows = self::facilityRows($raw);
        $facility = $this->facility(self::sections($instruction, [6, 7]), $rows ? implode("\n", $rows) : $allText, $provider);
        // Değer Camelot satırında yazılı olmalı (model uyduramaz, komşu hücreye kayamaz).
        if ($rows) {
            $text = implode(' ', $rows);
            $labels = collect((array) ($facility['facility_or_project_information']['fields'] ?? []))->mapWithKeys(fn (array $field) => [$field['key'] => $field['label_patterns'][0] ?? '']);
            $facility['facility_information'] = array_values(array_filter((array) ($facility['facility_information'] ?? []), fn (array $field) => self::facilityPair($text, (string) ($labels[$field['key']] ?? ''), (string) ($field['value'] ?? '')) !== null));
        }

        return [
            'template' => [
                'template_type' => 'tesisat_raporu',
                'template_version' => '1.0',
                'report_information' => [],
                'facility_or_project_information' => $facility['facility_or_project_information'] ?? null,
                'fire_systems' => ['systems' => array_map(fn (string $name) => [
                    'system_name' => $name,
                    'section_heading_patterns' => [],
                    'verdict' => ['status' => null, 'raw' => null, 'basis' => 'not_stated', 'evidence' => null],
                    'section_detection' => ['start_heading_patterns' => [], 'continuation_patterns' => [], 'end_detection_patterns' => []],
                    'equipment_definitions' => [$definitions[$name]],
                ], array_keys($definitions))],
            ],
            'extracted_data' => [
                'report_category' => null,
                'extraction_mode' => 'structured',
                'equipment_type' => ['slug' => $typeSlug, 'evidence' => null],
                'equipment_tag' => ['label' => null, 'value' => null, 'evidence' => null],
                'equipment_specs' => [],
                'report_information' => [],
                'facility_information' => (array) ($facility['facility_information'] ?? []),
                'result_legend' => [],
                'findings' => [],
                'overall_result' => null,
                'inspection_body' => null,
            ],
        ];
    }

    /**
     * Bulgu ajanı: yalnız bulguları bulur, kataloğun sistemine bağlar, sisteme ait değilse genel bulgu olur (kullanıcı kararı).
     * Kusur açıklamaları / notlar ve sonuç metinleri kod tarafından olduğu gibi kopyalanır. Bulgusu olan sistem uygun değildir; rapor uygunsa sistemler de uygundur.
     *
     * @param list<string> $pages Docling sayfaları
     * @param list<string> $catalogNames katalog sistemleri
     */
    public const HOOD = 'davlumbaz';

    // Yangın tesisatı: raporun kendi notlar bölümü, yazdığı gibi. Kusur başlığı kullanılmaz: başlık yoksa model ilgisiz satır seçiyordu (SGS "6.9 BAR").
    public function fireNotes(array $pages, ?string $pdfPath = null, ?string $provider = null): string
    {
        $lines = array_map('trim', explode("\n", self::writtenText($pages)));

        return self::withoutFooters(self::electricalCut($lines, $this->electricalHeadings($lines, $provider, PkTesisatTalimat::BASLIK_YANGIN))['not'], $pages, [], self::pdfPages($pdfPath));
    }

    /**
     * Notlara karışan sayfa altı satırları: birden çok sayfada tekrar eden satır (rakamlar sayılmaz: "1/2 Bu rapor...", "2/2 Bu rapor..."). Docling bu satırların çoğunu tek sayfada verir, ham PDF sayfalarına da bakılır. Model satır satır gerçek notları da siliyordu (OKCO). Bulgu olan satır kalır.
     *
     * @param list<string> $pages Docling sayfaları
     * @param list<string> $findings bu rapordan okunan bulgular
     * @param list<string> $pdfPages ham PDF sayfaları
     */
    public static function withoutFooters(string $notes, array $pages, array $findings = [], array $pdfPages = []): string
    {
        $plain = fn (string $line) => mb_strtolower((string) preg_replace('/[^\p{L}]+/u', '', $line), 'UTF-8');
        $repeated = [];
        foreach ([$pages, $pdfPages] as $set) {
            $onPages = [];
            foreach ($set as $page) {
                foreach (array_unique(array_map($plain, explode("\n", self::writtenText([$page])))) as $line) {
                    $onPages[$line] = ($onPages[$line] ?? 0) + 1;
                }
            }
            $repeated += array_filter($onPages, fn (int $count) => $count >= 2);
        }
        $kept = array_filter(explode("\n", $notes), fn (string $line) => trim($line) !== ''
            && (!isset($repeated[$plain($line)]) || array_filter($findings, fn (string $finding) => str_contains($line, $finding))));

        return implode("\n", $kept);
    }

    // shortcut: PkReportText::pdfiumPages private (PkSingleEquipmentReader'daki gibi); ortak metot açılırsa doğrudan çağrılır.
    private static function pdfPages(?string $pdfPath): array
    {
        return $pdfPath ? (array) (new \ReflectionMethod(PkReportText::class, 'pdfiumPages'))->invoke(app(PkReportText::class), $pdfPath) : [];
    }

    // Topraklama: kusur bölümünde adı geçmese de ölçüm satırı uygun değilse ölçüm sistemi uygun değildir (SGS: 0,84 Ω, sınır 0,14 Ω).
    private function groundingRowFails(array $pages, ?string $provider): bool
    {
        $rows = collect($pages)->flatMap(fn ($page) => explode("\n", (string) $page))->filter(fn (string $line) => str_starts_with(trim($line), '| '))->implode("\n");
        if ($rows === '') {
            return false;
        }
        $found = (array) PkLocalRetry::client($provider, fn () => false)->extract(PkTesisatTalimat::OLCUM_SONUC_TOPRAKLAMA, $rows, 30, schema: ['type' => 'object', 'properties' => ['uygunsuz' => ['type' => 'string', 'enum' => ['var', 'yok']]], 'required' => ['uygunsuz']]);

        return ($found['uygunsuz'] ?? null) === 'var';
    }

    public function findings(array $semantic, array $pages, string $typeSlug, ?string $provider = null, array $catalogNames = [], ?string $pdfPath = null): array
    {
        // Ajan başlık satırlarını söyler, bölümü kod keser: bütün rapor verilince gerçek bulgu nota kayıyordu (Neta 350), Docling başlığı da her raporda çıkmıyor (Lider 6. bölüm).
        $written = self::writtenText($pages);
        // Yöntem ortak, talimatlar türün kendi metni (PkTesisatTalimat): elektrik, yangın algılama, akü.
        $isElectric = in_array($typeSlug, ['elektrik-ic-tesisati', PkElectricalReportAnalyzer::DETECTION, PkElectricalReportAnalyzer::BATTERY, PkElectricalReportAnalyzer::GROUNDING, self::HOOD], true);
        if ($typeSlug === PkElectricalReportAnalyzer::LIGHTNING) {
            // Paratonere özel: genel başlık ajanı her satırı "diğer" diyordu, sonuç kesilmiyordu (Neta 201). Kusur boşsa bulgu sonuçtan aranır.
            $lines = array_map('trim', explode("\n", $written));
            $cut = self::lightningCut($lines, $this->lightningHeadings($lines, $provider));
            $text = trim($cut['kusur'] ?: $cut['sonuc']) ?: ($cut['bulundu'] ? '' : $written);
            $semantic['extracted_data']['facility_information'] = [...(array) ($semantic['extracted_data']['facility_information'] ?? []), ...$this->lightningMeasurement($lines, $provider)];
        } elseif ($isElectric) {
            // Elektriğe özel: notlar bulgu ajanına gitmez ("Yönetim odası..." notu 9 Lider raporunda bulgu oluyordu); kusur yoksa bulgular sonuçta yazılıdır (Lider 8. bölüm).
            $lines = array_map('trim', explode("\n", $written));
            $cut = self::electricalCut($lines, $this->electricalHeadings($lines, $provider, match ($typeSlug) {
                PkElectricalReportAnalyzer::GROUNDING => PkTesisatTalimat::BASLIK_TOPRAKLAMA,
                self::HOOD => PkTesisatTalimat::BASLIK_DAVLUMBAZ,
                default => PkTesisatTalimat::BASLIK_ELEKTRIK,
            }));
            // Rapor uygun diyorsa sonuç bölümünden bulgu okunmaz: bilgi cümleleri bulgu oluyordu (Neta 262).
            $status = $semantic['extracted_data']['overall_result']['status'] ?? null;
            $text = trim($cut['kusur'] ?: ($status === 'uygun' ? '' : $cut['sonuc'])) ?: ($cut['bulundu'] ? '' : $written);
            // Topraklama: sonuç başlığı metinde yoksa model kusur başlığını seçiyor (Lider T20250367); okuyucunun sonucu kalır.
            if ($typeSlug === PkElectricalReportAnalyzer::GROUNDING && $cut['sonuc'] === $cut['kusur']) {
                $cut['sonuc'] = '';
            }
            // Sonuç karar satırında biter: sonrası sayfa altı (Neta algılama) ya da işaretsiz seçenek (Neta akü).
            if (in_array($typeSlug, [PkElectricalReportAnalyzer::DETECTION, PkElectricalReportAnalyzer::BATTERY, PkElectricalReportAnalyzer::GROUNDING, self::HOOD], true) && $status) {
                $cut['sonuc'] = $this->untilDecision($cut['sonuc'], $status, $provider);
            }
        } else {
            $cut = self::cutSections(explode("\n", $written), $this->sectionHeadings($written, $provider));
            // Kusur bölümü varsa sonuç bölümünde bulgu aranmaz, olduğu gibi sonuç ve kanaat olur (kullanıcı kuralı). Yoksa kusurlar sonuçta yazılıdır.
            $text = trim($cut['kusur'] . "\n" . $cut['not'] . ($cut['kusur'] === '' ? "\n" . $cut['sonuc'] : '')) ?: $written;
        }
        $listed = collect((array) ($semantic['template']['fire_systems']['systems'] ?? []))->pluck('system_name')->all();
        $systems = array_values(array_unique([...$listed, ...$catalogNames]));
        // Topraklama: RCD selektivite ölçüm sistemi yalnız raporda tablosu varsa seçilir; yoksa artık akım bulguları ona gidiyordu (Lider).
        if ($typeSlug === PkElectricalReportAnalyzer::GROUNDING && !in_array('RCD Selektivite Kontrolü', $listed, true)) {
            $systems = array_values(array_diff($systems, ['RCD Selektivite Kontrolü']));
        }
        $properties = ['items' => ['type' => 'array', 'maxItems' => 60, 'items' => ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string'],
            'system' => ['type' => ['string', 'null'], 'enum' => [...$systems, null]],
        ], 'required' => ['text', 'system']]]];
        if ($isElectric) {
            $properties['items']['items']['properties']['tur'] = ['type' => 'string', 'enum' => ['bulgu', 'karar', 'bilgi']];
            $properties['items']['items']['required'][] = 'tur';
        }
        $answer = $text === '' ? [] : (array) PkLocalRetry::client($provider, fn () => false)->extract(
            PkTesisatTalimat::kusur($typeSlug) . "\nSİSTEMLER: " . ($systems ? implode(' | ', $systems) : '(yok)'),
            $text,
            3000,
            schema: ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties)]
        );
        $plain = fn (string $value) => mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value), 'UTF-8');
        $written_in = fn (string $value) => $plain($value) !== '' && str_contains($plain($text), $plain($value));
        $items = collect((array) ($answer['items'] ?? []))->filter(fn ($item) => is_array($item) && filled($item['text'] ?? null) && $written_in((string) $item['text']) && (!$isElectric || ($item['tur'] ?? null) === 'bulgu' || ($typeSlug === PkElectricalReportAnalyzer::DETECTION && $cut['kusur'] !== '')))->unique('text')->values();
        if ($isElectric) {
            // Aynı satırdaki ikinci cümle düşüyordu ("...yapılmamıştır. Pano kapağı bulunmamaktadır.", Lider 7900).
            $items = $items->concat(self::electricalLeftovers($text, (array) ($answer['items'] ?? [])))->values();
            // Topraklama: cümlenin önündeki "Not 1 :" etiketi kalan metin sayılıp bulgu oluyordu (Neta 350).
            if ($typeSlug === PkElectricalReportAnalyzer::GROUNDING) {
                $items = $items->reject(fn (array $item) => mb_strlen(trim((string) $item['text'])) <= 10)->values();
            }
        }
        $notes = trim($cut['kusur'] . "\n" . $cut['not']);
        if ($typeSlug === PkElectricalReportAnalyzer::GROUNDING) {
            $notes = self::withoutFooters($notes, $pages, array_map(fn (array $item) => trim((string) $item['text']), $items->all()), self::pdfPages($pdfPath));
        }
        if ($cut['sonuc'] !== '') {
            $semantic['extracted_data']['overall_result']['text'] = $cut['sonuc'];
        }
        if ($notes !== '') {
            $semantic['extracted_data']['report_information'][] = ['key' => 'notlar', 'value' => $notes];
        }
        $semantic['extracted_data']['findings'] = $items->map(fn (array $item, int $i) => [
            'id' => 'F' . ($i + 1), 'system_name' => $item['system'] ?? null, 'description' => $typeSlug === self::HOOD ? ltrim(trim((string) $item['text']), "-* \t") : trim((string) $item['text']), 'source_pages' => [], 'severity' => null, 'ambiguous' => false,
        ])->all();
        // Ölçüm tablosu olmayan sistem de bulgusuyla listeye girer: okuyucu listede olmayan sisteme bağlı bulguyu genele atar.
        foreach ($items->pluck('system')->filter()->unique()->diff($listed) as $name) {
            $semantic['template']['fire_systems']['systems'][] = ['system_name' => $name, 'section_heading_patterns' => [], 'verdict' => [], 'section_detection' => ['start_heading_patterns' => [], 'continuation_patterns' => [], 'end_detection_patterns' => []], 'equipment_definitions' => []];
        }
        $overall = $semantic['extracted_data']['overall_result']['status'] ?? null;
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $i => $system) {
            $status = $items->contains('system', $system['system_name']) ? 'uygun_degil' : ($overall === 'uygun' ? 'uygun' : null);
            if ($typeSlug === PkElectricalReportAnalyzer::GROUNDING && $status === null && $overall === 'uygun_degil' && !empty($system['equipment_definitions']) && $this->groundingRowFails($pages, $provider)) {
                $status = 'uygun_degil';
            }
            // Davlumbazda sistemin kararı kontrol listesinden gelir, bulgusu yoksa korunur.
            if ($typeSlug === self::HOOD && $status === null) {
                continue;
            }
            $semantic['template']['fire_systems']['systems'][$i]['verdict'] = ['status' => $status, 'raw' => null, 'basis' => $status ? 'derived' : 'not_stated', 'evidence' => null];
        }

        return $semantic;
    }

    /**
     * Başlık ajanı: yazı metnindeki ana bölüm başlıklarının satır numarası ve türü. Metne dokunmaz.
     *
     * @return list<array{line: int, kind: string}>
     */
    private function sectionHeadings(string $written, ?string $provider): array
    {
        $numbered = collect(explode("\n", $written))->map(fn (string $line, int $i) => ($i + 1) . ': ' . mb_substr($line, 0, 90))->implode("\n");

        return (array) (PkLocalRetry::client($provider, fn () => false)->extract(PkTesisatTalimat::BASLIK, $numbered, 1000, schema: ['type' => 'object', 'properties' => ['headings' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'object', 'properties' => [
            'line' => ['type' => 'integer'],
            'kind' => ['type' => 'string', 'enum' => ['kusur', 'not', 'sonuc', 'diger']],
        ], 'required' => ['line', 'kind']]]], 'required' => ['headings']])['headings'] ?? []);
    }

    // Elektrik başlık ajanı: seçenekler Docling'in başlık satırları ve numaralı satırlar ("7. NOTLAR" bazı raporda başlık işaretsiz gelir, 11930).
    private function electricalHeadings(array $lines, ?string $provider, string $prompt): array
    {
        $headings = array_values(array_unique(array_filter($lines, [self::class, 'isElectricalHeading'])));
        if ($headings === []) {
            return [];
        }
        $keys = ['kusur', 'not', 'sonuc', 'karar'];

        return (array) PkLocalRetry::client($provider, fn () => false)->extract($prompt, implode("\n", $headings), 300, schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => ['string', 'null'], 'enum' => [...$headings, null]]), 'required' => $keys]);
    }

    /**
     * Bulgu içeren satırda ajanın yazmadığı kalan metin aynı sistemin bulgusudur; ajanın karar ya da bilgi dediği parça kalanda sayılmaz.
     *
     * @return list<array{text: string, system: ?string, tur: string}>
     */
    public static function electricalLeftovers(string $text, array $items): array
    {
        $items = array_values(array_filter($items, fn ($item) => is_array($item) && filled($item['text'] ?? null)));
        $rest = [];
        foreach (explode("\n", $text) as $line) {
            $inLine = array_values(array_filter($items, fn (array $item) => str_contains($line, trim((string) $item['text']))));
            $bulgu = collect($inLine)->firstWhere('tur', 'bulgu');
            $left = trim(str_replace(array_map(fn (array $item) => trim((string) $item['text']), $inLine), '', $line));
            if ($bulgu && mb_strlen($left) > 3) {
                $rest[] = ['text' => $left, 'system' => $bulgu['system'] ?? null, 'tur' => 'bulgu'];
            }
        }

        return $rest;
    }

    // shortcut: karar ikinci seçenekse ilk seçenek satırı metinde kalır, "uygun değil" işaretli akü raporu gelince düzeltilir.
    private function untilDecision(string $conclusion, string $status, ?string $provider): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $conclusion)), fn (string $line) => $line !== ''));
        if (count($lines) < 2) {
            return $conclusion;
        }
        $answer = (array) PkLocalRetry::client($provider, fn () => false)->extract(PkTesisatTalimat::KARAR_SATIRI, 'KARAR: ' . ($status === 'uygun' ? 'uygun' : 'uygun değil') . "\nSATIRLAR:\n" . implode("\n", $lines), 300, schema: ['type' => 'object', 'properties' => ['satir' => ['type' => 'string', 'enum' => array_values(array_unique($lines))]], 'required' => ['satir']]);

        return self::cutAfter($lines, $answer['satir'] ?? null) ?? $conclusion;
    }

    public static function cutAfter(array $lines, mixed $line): ?string
    {
        $at = is_string($line) ? array_search($line, $lines, true) : false;

        return $at === false ? null : implode("\n", array_slice($lines, 0, $at + 1));
    }

    public static function isElectricalHeading(string $line): bool
    {
        // Başlık işaretlidir, ya da büyük harfle numaralı / ":" ile biter ("7. NOTLAR", "NOTLAR:"). "1. kat tali panoda..." başlık değildir (Lider 590050); öteki büyük harf satırlar modeli şaşırtıyordu (Neta 262).
        if (str_starts_with($line, '## ')) {
            return true;
        }

        return mb_strlen($line) <= 60 && mb_strtoupper($line, 'UTF-8') === $line && preg_match('/\p{L}{3}/u', $line) && (ctype_digit(mb_substr($line, 0, 1)) || str_ends_with($line, ':'));
    }

    /**
     * Elektrik bölümleri: her bölüm sıradaki başlığa kadar sürer; sonuçta karar satırı başlık gibi görünürse (Neta) metne dahildir.
     *
     * @param list<string> $lines
     * @return array{kusur: string, not: string, sonuc: string, bulundu: bool}
     */
    public static function electricalCut(array $lines, array $found): array
    {
        $at = fn (mixed $heading) => is_string($heading) ? array_search($heading, $lines, true) : false;
        // Bölüm, seçilen başlıklardan birine ya da işaretli başlığa kadar sürer: büyük harfle yazılmış bulgu satırı bölümü kesmez.
        $selected = array_filter([$found['kusur'] ?? null, $found['not'] ?? null, $found['sonuc'] ?? null]);
        $next = function (int $from) use ($lines, $selected) {
            foreach (array_slice($lines, $from + 1, null, true) as $i => $line) {
                if (str_starts_with($line, '## ') || in_array($line, $selected, true)) {
                    return $i;
                }
            }

            return count($lines);
        };
        $block = fn (int|false $from, ?int $to = null) => $from === false ? '' : trim(implode("\n", array_map(fn (string $line) => ltrim($line, '# '), array_slice($lines, $from + 1, max(0, ($to ?? $next($from)) - $from - 1)))));
        [$kusur, $not, $sonuc, $karar] = [$at($found['kusur'] ?? null), $at($found['not'] ?? null), $at($found['sonuc'] ?? null), $at($found['karar'] ?? null)];
        // Model "KUSUR AÇIKLAMALARI / NOTLAR" başlığını seçmeyebiliyor (Neta): sonuçtan hemen önceki başlık kusur bölümüdür, yoksa dolu kusur kaybolur.
        if ($kusur === false && $not === false && $sonuc !== false) {
            foreach (array_reverse(array_slice($lines, 0, $sonuc, true), true) as $i => $line) {
                if (self::isElectricalHeading($line)) {
                    $kusur = $i;
                    break;
                }
            }
        }

        return [
            'kusur' => $block($kusur),
            'not' => $not === $kusur ? '' : $block($not),
            'sonuc' => $block($sonuc, $sonuc !== false && $karar !== false && $karar > $sonuc ? $karar + 1 : null),
            'bulundu' => $kusur !== false || $sonuc !== false,
        ];
    }

    // Paratoner başlık ajanı: seçenekler Docling'in başlık satırları. Satır numarası sorulunca 2-3 satır kayıyordu, bütün satırlardan seçince şaşırıyordu (Neta 201).
    private function lightningHeadings(array $lines, ?string $provider): array
    {
        $headings = array_values(array_unique(array_filter($lines, fn (string $line) => str_starts_with($line, '## '))));
        if ($headings === []) {
            return [];
        }
        $keys = ['kusur', 'sonuc', 'karar'];

        return (array) PkLocalRetry::client($provider, fn () => false)->extract(PkTesisatTalimat::BASLIK_PARATONER, implode("\n", $headings), 300, schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => ['string', 'null'], 'enum' => [...$headings, null]]), 'required' => $keys]);
    }

    /**
     * Paratoner bölümleri: kusur = kusur başlığı → sonuç başlığı; sonuç = sonuç başlığının altı, karar satırı dahil (karar başlık değilse sonraki başlığa kadar).
     *
     * @param list<string> $lines
     * @return array{kusur: string, not: string, sonuc: string, bulundu: bool}
     */
    public static function lightningCut(array $lines, array $found): array
    {
        $at = fn (mixed $heading) => is_string($heading) ? array_search($heading, $lines, true) : false;
        [$kusur, $sonuc, $karar] = [$at($found['kusur'] ?? null), $at($found['sonuc'] ?? null), $at($found['karar'] ?? null)];
        $next = function (int $from) use ($lines) {
            foreach (array_slice($lines, $from + 1, null, true) as $i => $line) {
                if (str_starts_with($line, '## ')) {
                    return $i;
                }
            }

            return count($lines);
        };
        $block = fn (int $from, int $to) => trim(implode("\n", array_map(fn (string $line) => ltrim($line, '# '), array_slice($lines, $from + 1, max(0, $to - $from - 1)))));

        return [
            'kusur' => $kusur === false ? '' : $block($kusur, $sonuc !== false && $sonuc > $kusur ? $sonuc : $next($kusur)),
            'not' => '',
            'sonuc' => $sonuc === false ? '' : $block($sonuc, $karar !== false && $karar > $sonuc ? $karar + 1 : $next($sonuc)),
            'bulundu' => $kusur !== false || $sonuc !== false,
        ];
    }

    // Paratoner ölçüm ve sınır değeri ayrı satırlara dağılmış düz yazıdır, tablo okuyucu görmez; tesis kartına girer.
    private function lightningMeasurement(array $lines, ?string $provider): array
    {
        $options = ['type' => ['string', 'null'], 'enum' => [...array_values(array_unique(array_filter($lines, fn (string $line) => $line !== ''))), null]];
        $found = (array) PkLocalRetry::client($provider, fn () => false)->extract(PkTesisatTalimat::OLCUM_PARATONER, implode("\n", $lines), 300, schema: ['type' => 'object', 'properties' => ['olcum' => $options, 'sinir' => $options], 'required' => ['olcum', 'sinir']]);

        return self::lightningFacts($lines, $found);
    }

    // Metinde yazmayan ya da sayı içermeyen seçim karta girmez.
    public static function lightningFacts(array $lines, array $found): array
    {
        $rows = [];
        foreach (['olcum' => 'Topraklama Ölçüm Değeri (Ω)', 'sinir' => 'Topraklama Sınır Değeri (Ω)'] as $key => $label) {
            $value = is_string($found[$key] ?? null) ? trim($found[$key]) : '';
            if (preg_match('/\d/', $value) && in_array($value, $lines, true)) {
                $rows[] = ['key' => $label, 'value' => $value];
            }
        }

        return $rows;
    }

    /**
     * Başlık satırlarından bölüm metinleri: bölüm, sıradaki başlığa kadar sürer; başlık satırı metne girmez.
     *
     * @param list<string> $lines
     * @param list<array{line: int, kind: string}> $headings satır numarası 1'den başlar
     * @return array{kusur: string, not: string, sonuc: string}
     */
    public static function cutSections(array $lines, array $headings): array
    {
        $out = ['kusur' => [], 'not' => [], 'sonuc' => []];
        $starts = collect($headings)->filter(fn ($h) => is_array($h) && is_int($h['line'] ?? null) && $h['line'] >= 1 && $h['line'] <= count($lines))->sortBy('line')->values();
        foreach ($starts as $i => $heading) {
            if (isset($out[$heading['kind'] ?? ''])) {
                $end = $starts[$i + 1]['line'] ?? count($lines) + 1;
                $out[$heading['kind']] = [...$out[$heading['kind']], ...array_slice($lines, $heading['line'], $end - $heading['line'] - 1)];
            }
        }

        return array_map(fn (array $part) => trim(implode("\n", $part)), $out);
    }

    // Kusur ajanının metni: başlık, düz yazı ve tek hücreli tablo satırları. Sütunlu tablolar girmez: ölçüm ve kriter satırları bulgu oluyordu (96320-topraklama).
    public static function writtenText(array $pages): string
    {
        $out = [];
        foreach ($pages as $page) {
            foreach (explode("\n", (string) $page) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '### Tablo ')) {
                    continue;
                }
                if (str_starts_with($line, '| ')) {
                    $line = trim(substr($line, 2, -2));
                    if ($line === '0' || str_contains($line, ' | ')) {
                        continue;
                    }
                }
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Tesis / proje bilgisi ajanı (elektrik ve yangın tesisatı ortak): kural metni türün talimatından. Kart yalnız ilk etiketi
     * kullanır (PkElectricalReportReader::facilityValues); alan × etiket döngüsü sınırlanır (NuExtract3 #128 / #129: "Bina
     * Yüksekliği" tekrarı cevap sınırını aştı).
     *
     * @return array{facility_or_project_information?: array, facility_information?: array, result_legend?: array}
     */
    public function facility(string $rules, ?string $text, ?string $provider = null): array
    {
        if (blank($text)) {
            return [];
        }
        $schema = $this->contract->responseSchema()['properties'];
        // Etiket ve değer aynı kayıtta: ayrı listelerde (template alanları + extracted_data değerleri) küçük model etiketleri
        // yazıp bütün değerleri null bırakıyordu (NuExtract3 #141: "Kullanım Amacı | ASPİRASYON / İKLİMLENDİRME" boş).
        $found = PkLocalRetry::client($provider, fn () => false)->extract(
            $rules . "\n\nBU ADIMDA: yalnız tesis / proje bilgileri ve sonuç lejantı (result_legend). section_heading_patterns = tesis bilgileri bölümünün başlıkları. fields: raporda GERÇEKTEN bulunan her alan için key, label (PDF'deki etiket AYNEN) ve value (o etiketin yanında / altında yazan değer AYNEN; boşsa null). kind: alanın türü, anlamına göre: tesis = tesise / projeye ait bilgi; kriter = kontrol listesi / test maddesi ya da grup başlığı (soru biçiminde olabilir, sonucu U, U.D, uygun, uygun değil gibi işarettir); rapor = rapor no, rapor / muayene tarihi, müşteri, adres, muayene kuruluşu ve yöntemi.",
            $text,
            4000,
            schema: ['type' => 'object', 'properties' => [
                'section_heading_patterns' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string']],
                // 50: yangın proje kartında Proje / Uygulama çiftleri 30'u aşıyor (#18); döngüde tekrar edenleri aşağıda unique atar.
                'fields' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'object', 'properties' => [
                    'key' => ['type' => 'string'],
                    'label' => ['type' => 'string'],
                    'value' => ['type' => ['string', 'null']],
                    'kind' => ['type' => 'string', 'enum' => ['tesis', 'kriter', 'rapor']],
                ], 'required' => ['key', 'label', 'value', 'kind']]],
                'result_legend' => $schema['extracted_data']['properties']['result_legend'],
            ], 'required' => ['section_heading_patterns', 'fields', 'result_legend']]
        );
        $fields = collect((array) ($found['fields'] ?? []))->filter(fn ($field) => is_array($field) && filled($field['key'] ?? null) && ($field['kind'] ?? null) === 'tesis')->unique('key')->values();

        return [
            'facility_or_project_information' => [
                'section_heading_patterns' => (array) ($found['section_heading_patterns'] ?? []),
                'fields' => $fields->map(fn (array $field) => ['key' => $field['key'], 'label_patterns' => array_filter([(string) ($field['label'] ?? '')])])->all(),
            ],
            'facility_information' => $fields->map(fn (array $field) => ['key' => $field['key'], 'value' => $field['value'] ?? null])->all(),
            'result_legend' => (array) ($found['result_legend'] ?? []),
        ];
    }

    /**
     * Tablo türü, ajan kararı: "### Tablo N" ile numaralı tablolar.
     *
     * @param list<int> $nos
     * @return array<string, string> tN => tür
     */
    private function tableKinds(string $text, array $nos, ?string $provider): array
    {
        $keys = array_map(fn (int $no) => 't' . $no, $nos);

        return (array) PkLocalRetry::client($provider, fn () => false)->extract(<<<'PROMPT'
Sana bir periyodik kontrol raporunun tabloları verilecek ("### Tablo N" ve ilk satırları). HER tablonun türünü yaz (anahtar: tN).
- olcum_listesi: her satırı ayrı bir ölçüm noktası ya da ekipman (pano, röle, priz gibi) olan, aynı sütunlarda sayısal değerler taşıyan liste. Sıra no ile devam eden, başlığı önceki sayfada kalmış satırlar da budur. Tablonun üstünde başka satırlar olsa da içinde böyle bir liste başlıyorsa budur.
- kontrol_listesi: kontrol maddeleri ve sonuçları (UYGUN, UYGUN DEĞİL, U, U.D, Uygulama Yok gibi).
- bilgi: rapor no, tarih, firma, tesis, ölçüm aleti, yetkili kişi bilgisi (etiket ve değer; tek kayıtlık tablo).
- yazi: not, açıklama, sonuç cümlesi.
PROMPT, $text, max(500, count($nos) * 15), schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => 'string', 'enum' => ['olcum_listesi', 'kontrol_listesi', 'bilgi', 'yazi']]), 'required' => $keys]);
    }

    /**
     * Topraklama: her tablo ayrı sorulur, tablonun tamamıyla. Ölçüm listesi form satırlarının altında başlayabiliyor (SGS: 26 satırın
     * 13.sinden sonra); ortak tür sorusu ilk 12 satırı görüp "bilgi" diyordu, toplu soruda rapor bilgisi tablosuna da "var" diyordu.
     *
     * @return array<string, string> tN => olcum_listesi ya da yok
     */
    private function groundingListTables(array $tables, ?string $provider): array
    {
        $kinds = [];
        foreach ($tables as $table) {
            // En az 4 hücreli satır yoksa liste başlığı da yoktur (listHeader): not ve imza tablosu sorulmaz, "sınır değer" cümlesi sahte sistem açıyordu (SGS).
            if (!array_filter($table['rows'], fn (string $row) => substr_count($row, ' | ') >= 3)) {
                $kinds['t' . $table['no']] = 'yok';
                continue;
            }
            $found = (array) PkLocalRetry::client($provider, fn () => false)->extract(PkTesisatTalimat::TABLO_TOPRAKLAMA, implode("\n", array_slice($table['rows'], 0, 40)), 50, schema: ['type' => 'object', 'properties' => ['liste' => ['type' => 'string', 'enum' => ['var', 'yok']]], 'required' => ['liste']]);
            $kinds['t' . $table['no']] = ($found['liste'] ?? null) === 'var' ? 'olcum_listesi' : 'yok';
        }

        return $kinds;
    }

    /**
     * Ölçüm listeleri: tablo başına tür kararı, liste başına sistem kararı (ajana yalnız tablonun başı gider). Sütunları ve satırları kod okur. Katalogda olmayan gerçek liste
     * rapordaki adıyla kalır: okunur, görünür, kaydedilmez.
     *
     * @return array<string, array> sistem adı => tablo tarifi
     */
    private function measurementLists(array $raw, string $pdfPath, array $names, string $rules, ?string $provider, bool $grounding = false): array
    {
        $tables = self::latticeTables($raw);
        if (!$tables) {
            return [];
        }
        $text = collect($tables)->map(fn (array $table) => '### Tablo ' . $table['no'] . "\n" . implode("\n", array_slice($table['rows'], 0, 12)))->implode("\n\n");
        $kinds = $this->tableKinds($text, array_column($tables, 'no'), $provider);
        // Topraklama: ortak soru hiç liste bulamadıysa tablo tablo sorulur. Her raporda sorulunca devam sayfaları listeden kopuyordu (Lider: 68 satır 4'e düştü).
        if ($grounding && !in_array('olcum_listesi', $kinds, true)) {
            $kinds = $this->groundingListTables($tables, $provider);
            $rules .= "\n" . PkTesisatTalimat::LISTE_TOPRAKLAMA;
        }
        $listKeys = array_keys(array_filter($kinds, fn ($kind) => $kind === 'olcum_listesi'));
        $runs = self::listRuns($tables, $listKeys);
        if (!$runs) {
            return [];
        }
        $keys = array_map(fn (int $i) => 'l' . ($i + 1), array_keys($runs));
        $text = collect($runs)->map(fn (array $run, int $i) => '### Liste ' . ($i + 1) . "\n" . implode("\n", array_slice(array_merge(...array_column($run, 'rows')), 0, 20)))->implode("\n\n");
        $found = PkLocalRetry::client($provider, fn () => false)->extract(<<<PROMPT
Sana bir periyodik kontrol raporunun ölçüm / ekipman listeleri verilecek ("### Liste N" ve ilk satırları; listenin üstünde başlığın durduğu tablo da görünür). HER listenin sistemini yaz (anahtar: lN).
- system: listenin ait olduğu sistem, listeden AYNEN, sütunlarına bakarak. Listede karşılığı yoksa "katalogda_yok".
- name: yalnız "katalogda_yok" iken listenin rapordaki adı; diğerlerinde null.

SİSTEM KURALI:
{$rules}
PROMPT, $text, max(1000, count($runs) * 40), schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => 'object', 'properties' => [
            'system' => ['type' => 'string', 'enum' => [...$names, 'katalogda_yok']],
            'name' => ['type' => ['string', 'null']],
        ], 'required' => ['system', 'name']]), 'required' => $keys]);

        $out = [];
        foreach ($runs as $i => $run) {
            $choice = (array) ($found['l' . ($i + 1)] ?? []);
            $outside = ($choice['system'] ?? null) === 'katalogda_yok';
            $system = $outside ? trim((string) ($choice['name'] ?? '')) : (string) ($choice['system'] ?? '');
            $header = self::listHeader($run, $listKeys);
            // Katalogda olmayan liste en az 5 satır ister: 3 satırlık cihaz tablosu sahte sistem oluyordu (Neta elektrik).
            $own = array_sum(array_map(fn (array $table) => in_array('t' . $table['no'], $listKeys, true) ? count($table['rows']) : 0, $run));
            if ($system === '' || isset($out[$system]) || !$header || ($outside && $own < 5)) {
                continue; // shortcut: sistem başına tek liste; ayrı başlıklı ikinci liste olan rapor çıkarsa liste başına tarif kurulur
            }
            $definition = $this->definitions->fromHeader($pdfPath, $header[1], $header[0]);
            if ($definition) {
                $out[$system] = $grounding ? $this->groundingRowResult($definition, $run, $provider) : $definition;
            }
        }

        return $out;
    }

    // Elektrik: bilgi ajanı kuruluş adını bulamadıysa kuruluş kuralıyla bir kez daha okunur, YALNIZ ad alınır (kural ilk okumaya
    // konunca müşteri adını kesiyordu, Lider 648460; ayrı kısa ajan 10 Lider raporunun 3'ünde adı bulamadı ya da kişi adı yazdı).
    // Ad metinde aynen yazılı olmalı ve müşteri olmamalı: ad yazmayan SGS raporunda müşteri kuruluş yazılıyordu.
    public function electricalInspectionBody(array $semantic, string $text, ?string $provider = null): array
    {
        if (filled($semantic['extracted_data']['inspection_body']['name'] ?? null)) {
            return $semantic;
        }
        $found = app(PkHeaderPass::class)->apply($semantic, $text, $provider, PkTesisatTalimat::KURULUS_ELEKTRIK);
        $name = trim((string) ($found['extracted_data']['inspection_body']['name'] ?? ''));
        $plain = fn (string $value) => mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value), 'UTF-8');
        $customer = $plain((string) collect((array) ($semantic['extracted_data']['report_information'] ?? []))->firstWhere('key', 'company_title')['value'] ?? '');
        if ($plain($name) === '' || !str_contains($plain($text), $plain($name)) || ($customer !== '' && (str_contains($customer, $plain($name)) || str_contains($plain($name), $customer)))) {
            return $semantic;
        }
        $semantic['extracted_data']['inspection_body'] = [...(array) ($semantic['extracted_data']['inspection_body'] ?? []), 'name' => $name];

        return $semantic;
    }

    // Topraklama: satırın sonucunu yazan sütun özellik değildir, satırın durumudur (eski talimatın kuralı; SGS'te SONUÇ özellik olunca 4 satırın durumu boş kalıyordu).
    // Ajan liste başına bir kez sütunu seçer; değerleri tablo okuyucu çevirir (PkElectricalReportReader::gridRows, eski okuyucuda kriter sütunu).
    private function groundingRowResult(array $definition, array $run, ?string $provider): array
    {
        $labels = array_values(array_filter(array_map(fn (array $attribute) => (string) ($attribute['field'] ?? ''), (array) ($definition['attributes'] ?? []))));
        if (!$labels) {
            return $definition;
        }
        $rows = implode("\n", array_slice(array_merge([], ...array_column($run, 'rows')), 0, 20));
        $found = (array) PkLocalRetry::client($provider, fn () => false)->extract(PkTesisatTalimat::SONUC_SUTUNU_TOPRAKLAMA, "SÜTUNLAR: " . implode(' | ', $labels) . "\n\n" . $rows, 60, schema: ['type' => 'object', 'properties' => ['sutun' => ['type' => 'string', 'enum' => [...$labels, 'yok']]], 'required' => ['sutun']]);
        $label = $found['sutun'] ?? 'yok';
        if (!in_array($label, $labels, true)) {
            return $definition;
        }
        $definition['attributes'] = array_values(array_filter($definition['attributes'], fn (array $attribute) => ($attribute['field'] ?? null) !== $label));
        $definition['instance_structure']['header_patterns'] = array_values(array_diff((array) $definition['instance_structure']['header_patterns'], [$label]));
        $definition['row_result'] = $label;

        return $definition;
    }

    /**
     * Listenin başlık satırı, yapıdan: en az 4 hücreli ve hücrelerinin çoğunda rakam olmayan ilk satır (veri satırının çoğu rakamdır). Önce listenin kendi tabloları, sonra
     * önündeki tablo (alttan yukarı). Dönen: hücreler; ilki kimlik sütunu.
     *
     * @return list<string>|null
     */
    public static function listHeader(array $run, array $listKeys): ?array
    {
        $own = array_filter($run, fn (array $table) => in_array('t' . $table['no'], $listKeys, true));
        $before = array_filter($run, fn (array $table) => !in_array('t' . $table['no'], $listKeys, true));
        $rows = [...array_merge([], ...array_column($own, 'rows')), ...array_reverse(array_merge([], ...array_column($before, 'rows')))];
        foreach ($rows as $row) {
            $cells = explode(' | ', $row);
            if (count($cells) >= 4 && count(array_filter($cells, fn (string $cell) => preg_match('/\d/', $cell) === 1)) * 2 < count($cells)) {
                return $cells;
            }
        }

        return null;
    }

    /**
     * Ölçüm listeleri: art arda gelen aynı sütun sayılı tablolar tek liste (başlıksız devam sayfaları); listenin önündeki tablo da eklenir,
     * başlık orada olabilir (topraklama: "Sıra | Ölçüm Noktası" kriter satırlarının altında, satırlar sonraki sayfalarda).
     *
     * @param list<string> $listKeys ölçüm listesi denen tablolar ("t3")
     * @return list<list<array>>
     */
    public static function listRuns(array $tables, array $listKeys): array
    {
        $runs = [];
        $columns = null;
        foreach ($tables as $i => $table) {
            if (!in_array('t' . $table['no'], $listKeys, true)) {
                $columns = null;
                continue;
            }
            if ($columns === $table['columns']) {
                $runs[count($runs) - 1][] = $table;
                continue;
            }
            $runs[] = isset($tables[$i - 1]) && !in_array('t' . $tables[$i - 1]['no'], $listKeys, true) ? [$tables[$i - 1], $table] : [$table];
            $columns = $table['columns'];
        }

        // 3 satırdan azı liste değildir: tek kayıtlık bilgi tablosu liste sanılıp sahte sistem açıyordu (#126: "Rapor No" tablosu; gerçek listeler 15+ satır).
        return array_values(array_filter($runs, fn (array $run) => count(array_merge(...array_map(fn (array $table) => in_array('t' . $table['no'], $listKeys, true) ? $table['rows'] : [], $run))) >= 3));
    }

    /**
     * Çizgili tablolar: numara, sayfa, Camelot kutusu (sol-üst → sağ-alt), dolu satırlar ve sütun başlığı adayları (ölçüm tablosunun
     * başlığı kriter satırlarının altında olabilir, sayfa başında değil).
     *
     * @return list<array{no: int, page: int, area: string, rows: list<string>, labels: list<string>}>
     */
    public static function latticeTables(array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            if (!is_array($table) || ($table['flavor'] ?? null) !== 'lattice' || count((array) ($table['bbox'] ?? [])) !== 4) {
                continue;
            }
            [$x1, $y1, $x2, $y2] = array_map('floatval', $table['bbox']);
            $rows = array_values(array_filter(array_map(fn ($row) => array_values(array_filter(array_map(fn ($cell) => Str::squish((string) $cell), (array) $row), fn (string $cell) => $cell !== '')), (array) ($table['data'] ?? []))));
            $out[] = [
                'no' => count($out) + 1,
                'page' => (int) ($table['page'] ?? 0),
                'area' => implode(',', [min($x1, $x2), max($y1, $y2), max($x1, $x2), min($y1, $y2)]),
                'columns' => count((array) (((array) ($table['data'] ?? []))[0] ?? [])),
                'rows' => array_map(fn (array $cells) => implode(' | ', $cells), $rows),
                'labels' => array_values(array_unique(array_filter(array_merge(...array_map(fn (array $cells) => array_filter($cells, fn (string $cell) => mb_strlen($cell) <= 60), $rows ?: [[]])), fn (string $cell) => preg_match('/\p{L}/u', $cell) === 1))),
            ];
        }

        return $out;
    }

    /**
     * Kartın aranacağı satırlar: çizgili tabloların ilk sayfalardaki dolu satırları, hücreler " | " ile.
     * shortcut: yalnız ilk 3 sayfa (tesis bilgileri raporun başında), kapak ve içindekiler daha uzun bir raporda çıkarsa artırılır.
     *
     * @return list<string>
     */
    public static function facilityRows(array $tables, int $pages = 3): array
    {
        return array_column(self::facilityCells($tables, $pages), 'row');
    }

    /** @return list<array{row: string, table: int}> */
    public static function facilityCells(array $tables, int $pages = 3): array
    {
        $rows = [];
        foreach ($tables as $index => $table) {
            // Tek sütunlu tablo yazıdır (not, sonuç), etiket-değer taşımaz: kusur cümleleri karta giriyordu (Lider elektrik).
            if (!is_array($table) || ($table['flavor'] ?? null) !== 'lattice' || (int) ($table['page'] ?? 0) > $pages || count((array) (((array) ($table['data'] ?? []))[0] ?? [])) < 2) {
                continue;
            }
            // shortcut: tablo başına ilk 25 satır; tesis bilgisi uzun tablonun sonunda olan rapor çıkarsa artırılır.
            foreach (array_slice((array) ($table['data'] ?? []), 0, 25) as $row) {
                $cells = array_values(array_filter(array_map(fn ($cell) => Str::squish((string) $cell), (array) $row), fn (string $cell) => $cell !== ''));
                // Her sayfada tekrar eden form satırı bir kez (Neta akü: aynı üst bilgi 3 sayfada).
                if ($cells && !in_array(implode(' | ', $cells), array_column($rows, 'row'), true)) {
                    $rows[] = ['row' => implode(' | ', $cells), 'table' => $index];
                }
            }
        }

        return $rows;
    }

    // Etiket ve değer satırda gerçekten yazılı olmalı (model uyduramaz); değeri boş alan karta girmez.
    public static function facilityPair(string $row, string $label, string $value): ?array
    {
        // Yalnız harf ve rakam karşılaştırılır: PDF'te bozuk harf birleşimi metinde boşluk ya da garip işaret olarak kalır ("tesisatı" → "tesisa  ", #126).
        $plain = fn (string $text) => mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text), 'UTF-8');
        [$label, $value] = [Str::squish($label), Str::squish(str_replace(['☒', '☐'], '', $value))];
        if ($label === '' || $value === '' || !str_contains($plain($row), $plain($label)) || !str_contains($plain($row), $plain($value))) {
            return null;
        }

        return ['label' => $label, 'value' => $value];
    }

    // Talimatın istenen numaralı bölümleri (başlık satırı "N. "; alt bölümler "4a." gibi bir öncekinin içinde kalır);
    // $common: ilk bölümden önceki ortak kısım (sabit kararlar) da eklenir.
    public static function sections(string $instruction, array $numbers, bool $common = true): string
    {
        $out = [];
        $keep = $common;
        foreach (explode("\n", $instruction) as $line) {
            if (preg_match('/^(\d{1,2})\. /', $line, $match)) {
                $keep = in_array((int) $match[1], $numbers, true);
            }
            if ($keep) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }
}
