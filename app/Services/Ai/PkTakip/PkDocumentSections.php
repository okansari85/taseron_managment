<?php

namespace App\Services\Ai\PkTakip;

use Illuminate\Support\Str;

/**
 * Yapısı korunmuş (Docling) sayfaları bölümlere ayırır ve yapay zekaya etiketletir (alt ajanların girdisi). Bölüm sınırı,
 * scripts/pk_docling.py'nin başlık öğelerine yazdığı "## " satırıdır (kendi çıktı biçimimiz; rapor metni ayrıştırılmaz).
 * Etiketler: rapor bilgisi, tesis bilgisi, sistem (hangisi), kusur, sonuç, kuruluş, diğer.
 */
class PkDocumentSections
{
    public const LABELS = ['rapor_bilgisi', 'tesis_bilgisi', 'sistem', 'kusur', 'sonuc', 'kurulus', 'diger'];

    /** @return list<array{heading: string, text: string, page: int}> */
    public static function split(array $pages): array
    {
        $sections = [['heading' => '', 'text' => '', 'page' => 1]];
        foreach (array_values($pages) as $index => $page) {
            $lines = explode("\n", (string) $page);
            for ($k = 0; $k < count($lines); $k++) {
                $line = $lines[$k];
                // Tek hücreli tablo bir başlık kutusudur: Docling "5.2. ÖLÇÜM VERİLERİ" başlığını böyle verdi, ölçüm tablosu önceki
                // bölüme karışıyordu (#141). Satır "| a | b |" biçiminde; başlıksız tablonun ilk satırı sütun numarası ("| 0 |").
                if (str_starts_with($line, '### Tablo ')) {
                    $rows = [];
                    for ($j = $k + 1; str_starts_with($lines[$j] ?? '', '| '); $j++) {
                        $rows[] = trim(substr($lines[$j], 2, -2));
                    }
                    $cells = array_values(array_diff($rows, ['0']));
                    if (count($cells) === 1 && !array_filter($rows, fn ($row) => str_contains($row, ' | '))) {
                        $line = '## ' . $cells[0];
                        $k = $j - 1;
                    }
                }
                if (str_starts_with($line, '## ')) {
                    $sections[] = ['heading' => substr($line, 3), 'text' => '', 'page' => $index + 1];
                } else {
                    $sections[count($sections) - 1]['text'] .= $line . "\n";
                }
            }
        }

        return array_values(array_filter($sections, fn ($section) => trim($section['heading'] . $section['text']) !== ''));
    }

    /**
     * Bölümleri etiketler: [bölüm sırası => ['label' => ..., 'system' => sistem adı | null]].
     *
     * @param list<string> $systems katalogdaki sistem adları
     */
    // $rules: türün sistem kuralı (örn. elektrik: sistem ancak kendi formu / tablosu varsa), etiketlemede kullanılır.
    public function label(array $sections, array $systems, ?string $provider = null, string $rules = ''): array
    {
        // Model sıra numarası yerine başlığın kendisini seçer (şemada seçenek listesi): uzun listede numara kaydırmıyor.
        // Aynı başlık birden çok bölümde geçiyorsa (sayfa üst bilgisi gibi) hepsi aynı etiketi alır.
        $headings = collect($sections)->pluck('heading')->filter()->unique()->values()->all();
        if (!$headings) {
            return [];
        }
        $list = collect($sections)->filter(fn ($section) => $section['heading'] !== '')->unique('heading')
            ->map(fn ($section) => '- "' . $section['heading'] . '" [s.' . $section['page'] . ']: ' . mb_substr(Str::squish($section['text']), 0, 160))->implode("\n");
        $names = $systems ? implode(' | ', $systems) : '(yok)';
        // maxItems: küçük model başlık listesini döngüye sokup tekrar ediyordu (NuExtract3, #126: cevap sınırı aşıldı).
        $schema = ['type' => 'object', 'properties' => ['sections' => ['type' => 'array', 'maxItems' => count($headings), 'items' => ['type' => 'object', 'properties' => [
            'heading' => ['type' => 'string', 'enum' => $headings],
            'label' => ['type' => 'string', 'enum' => self::LABELS],
            'system' => ['type' => ['string', 'null'], 'enum' => [...$systems, null]],
        ], 'required' => ['heading', 'label', 'system']]]], 'required' => ['sections']];

        $found = PkLocalRetry::client($provider, fn (array $r) => empty($r['sections']))->extract(<<<PROMPT
Sana bir periyodik kontrol raporunun bölüm başlıkları ve her bölümün ilk satırları verilecek. HER başlık için bölümün ne olduğunu etiketle (heading = başlık, listede yazdığı gibi).
- rapor_bilgisi: rapor no, tarihler, kontrol talep eden firma / müşteri bilgileri.
- tesis_bilgisi: tesisin / projenin özellikleri (kullanım amacı, şebeke tipi, proje bilgisi gibi).
- sistem: bir tesisat sisteminin kontrol listesi, ölçümleri ya da ekipman listesi. system = aşağıdaki listeden o sistemin adı.
- kusur: kusur / uygunsuzluk / eksiklik açıklamaları, notlardaki tespitler.
- sonuc: sonuç ve kanaat.
- kurulus: raporu düzenleyen muayene kuruluşu, onay, imza, personel.
- diger: sayfa üst / alt bilgisi, yöntem / kapsam açıklaması, lejant ve diğerleri.
- system yalnız label "sistem" iken dolu; diğerlerinde null.
SİSTEMLER: {$names}
PROMPT . ($rules !== '' ? "\n\nSİSTEM KURALI:\n" . $rules : ''), "BÖLÜMLER:\n" . $list, 4000, schema: $schema);

        $byHeading = [];
        foreach ((array) ($found['sections'] ?? []) as $row) {
            if (in_array($row['heading'] ?? null, $headings, true) && in_array($row['label'] ?? null, self::LABELS, true)) {
                $byHeading[$row['heading']] = ['label' => $row['label'], 'system' => in_array($row['system'] ?? null, $systems, true) ? $row['system'] : null];
            }
        }
        $labels = [];
        foreach ($sections as $i => $section) {
            if (isset($byHeading[$section['heading']])) {
                $labels[$i] = $byHeading[$section['heading']];
            }
        }

        return $labels;
    }

    /**
     * Alt ajanların girdileri: ['systems' => sistem bölümleri, 'findings' => kusur bölümleri, 'header' => bilgi / sonuç /
     * kuruluş bölümleri]; etiket bulunamazsa null (çağıran
     * bütün yapılı metni kullanır).
     */
    public function texts(array $pages, array $systems, ?string $provider = null, string $rules = ''): array
    {
        $sections = self::split($pages);
        $labels = count($sections) > 1 ? $this->label($sections, $systems, $provider, $rules) : [];
        // Türün sistem kuralı varsa (elektrik) her sistem bölümünün sistemi ayrı küçük ajanla, bölümün kendi içeriğinden
        // seçilir: bütün başlıkları tek istekte eşlerken küçük model karıştırıyordu (#141: Havalandırma Üniteleri → Klima
        // Santralleri).
        if ($rules !== '') {
            foreach ($labels as $i => $label) {
                if ($label['label'] === 'sistem') {
                    $labels[$i]['system'] = $this->system($sections[$i], $systems, $rules, $provider) ?? $label['system'];
                }
            }
        }
        $of = fn (array $wanted) => array_keys(array_filter($labels, fn ($label) => in_array($label['label'], $wanted, true)));

        // Sistem başına bölümler (her sistem ajanı yalnız kendi bölümünü okur). Sayfa üst bilgisi gibi "diger" bölümdeki tablo
        // önceki sistemin devamıdır (liste sonraki sayfada sürer).
        // Sayfa üst bilgisi (birden çok sayfada aynen tekrar eden başlık: kuruluş adı, tesisat adı) sistem değilse zinciri koparmaz:
        // altındaki liste önceki sayfadaki sistemin devamıdır (#141: debi ölçümlerinin 2. sayfası kuruluş başlığından sonra kalıyordu).
        $pagesOf = collect($sections)->groupBy('heading')->map(fn ($group) => $group->pluck('page')->unique()->count());
        $bySystem = [];
        $current = null;
        foreach ($sections as $i => $section) {
            $label = $labels[$i] ?? null;
            if (($label['label'] ?? null) !== 'sistem' && $section['heading'] !== '' && $pagesOf[$section['heading']] > 1) {
                if ($current !== null && str_contains($section['text'], '### Tablo')) {
                    $bySystem[$current][] = $i;
                }
                continue;
            }
            if (($label['label'] ?? null) === 'sistem' && $label['system'] !== null) {
                $current = $label['system'];
            } elseif (!(($label['label'] ?? 'diger') === 'diger' && $current !== null && str_contains($section['text'], '### Tablo'))) {
                $current = null;
            }
            if ($current !== null) {
                $bySystem[$current][] = $i;
            }
        }

        return [
            'by_system' => array_map(fn (array $indexes) => self::text($sections, $indexes), $bySystem),
            'facility' => self::text($sections, $of(['tesis_bilgisi'])),
            'systems' => self::text($sections, $of(['sistem', 'tesis_bilgisi'])),
            'findings' => self::text($sections, $of(['kusur'])),
            'header' => self::text($sections, $of(['rapor_bilgisi', 'sonuc', 'kurulus'])),
        ];
    }

    // Tek bölümün sistemi (bölümün başı yeter: başlık, tablo başlıkları, ilk satırlar).
    private function system(array $section, array $systems, string $rules, ?string $provider): ?string
    {
        $found = PkLocalRetry::client($provider, fn () => false)->extract(
            "Sana bir periyodik kontrol raporunun TEK bir bölümü verilecek. Bu bölüm aşağıdaki sistemlerden hangisine ait? system = listeden biri, AYNEN.\nSİSTEMLER: " . implode(' | ', $systems) . "\n\nSİSTEM KURALI:\n" . $rules,
            '## ' . $section['heading'] . "\n" . mb_substr(trim($section['text']), 0, 2500),
            500,
            schema: ['type' => 'object', 'properties' => ['system' => ['type' => 'string', 'enum' => $systems]], 'required' => ['system']]
        );

        return in_array($found['system'] ?? null, $systems, true) ? $found['system'] : null;
    }

    // Seçilen bölümlerin metni (başlıklarıyla); hiç bölüm yoksa null.
    public static function text(array $sections, array $indexes): ?string
    {
        $parts = collect($indexes)->map(fn ($i) => isset($sections[$i]) ? '## ' . $sections[$i]['heading'] . "\n" . trim($sections[$i]['text']) : null)->filter();

        return $parts->isEmpty() ? null : $parts->implode("\n\n");
    }
}
