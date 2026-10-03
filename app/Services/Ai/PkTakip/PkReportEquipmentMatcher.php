<?php

namespace App\Services\Ai\PkTakip;

use App\Models\PeriodicEquipmentType;
use App\Models\PkEquipment;

/**
 * Yapay zeka ile okunan raporun, yüklendiği ekipmana ait olup olmadığını kontrol eder:
 * rapordaki tek örnekli ekipman bilgileri (seri no, marka, model, ekipman no) ve ekipman türü
 * kayıtlı ekipmanla karşılaştırılır. Karar vermez; sonuç kullanıcıya uyarı olarak gösterilir.
 *
 * status: match (tutuyor) | close (küçük fark, yazım hatası olabilir) | mismatch (tutmuyor) | unknown (karşılaştıracak bilgi yok).
 * Seri no iki tarafta da varsa karar yalnızca onunla verilir. Yoksa yedek kimlik: ekipman no; yangın tüpünde no tekrar
 * ettiği için bulunduğu yer + ekipman no + söndürücü tipi birlikte. Marka, model, tür, etiket ya da kimlik alanlarından
 * biri farklıysa mismatch; kimlik karşılaştırılamıyorsa "eşleşti" denmez (unknown).
 */
class PkReportEquipmentMatcher
{
    // Rapor alan adı (normalize) → ekipman alanı. Sıra önemli: ilk eşleşen desen kazanır.
    private const FIELD_PATTERNS = [
        'serial_no' => '/seri|serial|imalat no|fabrika no/u',
        'brand' => '/marka|imalatci|uretici|brand/u',
        'model' => '/model|tipi/u',
        'code' => '/ekipman no|ekipman kodu|makine no|envanter|sicil no|demirbas|^no$|^kod/u',
        'place' => '/bulundugu yer|calisma alani|konumu?$/u',
    ];

    private const LABELS = ['serial_no' => 'Seri no', 'brand' => 'Marka', 'model' => 'Model', 'code' => 'Ekipman no / kod', 'place' => 'Bulunduğu yer', 'type' => 'Ekipman türü', 'variant' => 'Etiket'];

    // Tür adındaki ayırt edici olmayan kelimeler (karşılaştırmada kullanılmaz).
    private const GENERIC_WORDS = ['elektrikli', 'manuel', 'mobil', 'sabit', 'servis', 'kontrol', 'sistemi', 'sistemleri', 'tesisati', 'ekipmani'];

    // Seri no yokken kimliği belirleyen alanlar: varsayılan ekipman no; yangın tüpünde no tekrar ettiği için
    // bulunduğu yer + ekipman no + söndürücü tipi (etiket) birlikte; yangın dolabında dolap no + konum; rafta raf sıra no
    // + konum (rafın seri no'su yok; rapor no her yıl değişir, kimlik değildir).
    private const IDENTITY_FIELDS = [
        'yangin-sondurme-cihazi' => ['place', 'code', 'variant'],
        'yangin-dolabi' => ['place', 'code'],
        'depolama-rafi' => ['place', 'code'],
    ];

    /** @return array<int, string> seri no yokken bu türde kimliği belirleyen alanlar */
    public static function identityFields(?string $typeSlug): array
    {
        return self::IDENTITY_FIELDS[$typeSlug] ?? ['code'];
    }

    public function match(PkEquipment $equipment, array $semantic): array
    {
        $reportValues = $this->reportValues($semantic);
        $checks = [];

        foreach (['serial_no', 'brand', 'model'] as $field) {
            $ours = trim((string) $equipment->{$field});
            $theirs = $reportValues[$field] ?? null;
            if ($ours === '' || $theirs === null) {
                continue;
            }
            $checks[] = [
                'field' => $field,
                'label' => self::LABELS[$field],
                'equipment' => $ours,
                'report' => $theirs,
                'result' => $this->compare($field, $ours, $theirs),
            ];
        }

        if ($type = $this->typeCheck($equipment, $semantic)) {
            $checks[] = $type;
        }

        // Etiket (örn. transpalet Elektrikli / Manuel): rapordaki tahrik türünden anlaşılır.
        $reportVariant = $this->reportVariant($equipment, $semantic);
        if ($reportVariant && filled($equipment->variant)) {
            $checks[] = [
                'field' => 'variant',
                'label' => $equipment->type?->variant_label ?: self::LABELS['variant'],
                'equipment' => $equipment->variant,
                'report' => $reportVariant,
                'result' => $this->token($reportVariant) === $this->token($equipment->variant) ? 'ok' : 'diff',
            ];
        }

        $serialDecides = in_array(array_column($checks, 'result', 'field')['serial_no'] ?? null, ['ok', 'close', 'diff'], true);

        // Seri no yoksa (örn. manuel transpalet) yedek kimlik: ekipman no; yangın tüpünde yer + no + tip birlikte.
        $identity = self::identityFields($equipment->type?->slug);
        if (!$serialDecides) {
            foreach (array_diff($identity, ['variant']) as $field) {
                $ours = trim((string) $equipment->{$field});
                $theirs = $reportValues[$field] ?? null;
                if ($ours !== '' && $theirs !== null) {
                    $checks[] = ['field' => $field, 'label' => self::LABELS[$field], 'equipment' => $ours, 'report' => $theirs, 'result' => $this->compare($field, $ours, $theirs)];
                }
            }
        }
        $byField = array_column($checks, 'result', 'field');
        $identityDecides = !$serialDecides && collect($identity)->every(fn (string $field) => isset($byField[$field]));

        return [
            'status' => match (true) {
                $serialDecides => $this->serialStatus($byField['serial_no']),
                in_array('diff', $byField, true) => 'mismatch',
                $identityDecides => 'match',
                default => 'unknown',
            },
            // serial_no: seri no ile; code: seri no yok, ekipman no ile; place_code_variant: yangın tüpü, yer + no + tip ile;
            // fields: kimlik karşılaştırılamadı (eşleşti denmez).
            'basis' => $serialDecides ? 'serial_no' : ($identityDecides ? implode('_', $identity) : 'fields'),
            'checks' => $checks,
            // Rapordaki kimlik değerleri (seri no, marka, model, ekipman no); ekipmanda boşsa kullanıcı onayıyla yazılabilir.
            'fields' => $reportValues,
            // Rapora göre etiket; ekipmanda etiket yoksa kullanıcı onayıyla eklenir.
            'variant' => $reportVariant,
            'variant_evidence' => $reportVariant ? ($semantic['extracted_data']['equipment_tag']['evidence'] ?? null) : null,
        ];
    }

    // Seri no iki tarafta da varsa kimliği o belirler: aynıysa aynı ekipman (tür, model gibi farklar kayıt / yazım farkıdır),
    // tek karakter farkı yazım hatası olabilir (close), daha fazlası başka ekipman.
    private function serialStatus(string $result): string
    {
        return match ($result) {
            'ok' => 'match',
            'close' => 'close',
            default => 'mismatch',
        };
    }

    // Türün etiket seçeneklerinden hangisi: önce Gemini'nin seçtiği (equipment_tag, yalnızca listedeyse),
    // sonra rapordaki "Tahrik Türü" gibi alanlarda birebir geçen seçenek. Listede olmayan değer: boş.
    private function reportVariant(PkEquipment $equipment, array $semantic): ?string
    {
        $variants = (array) ($equipment->type?->variants ?? []);
        if (!$variants) {
            return null;
        }
        $chosen = $this->token((string) ($semantic['extracted_data']['equipment_tag']['value'] ?? ''));
        foreach ($chosen !== '' ? $variants : [] as $variant) {
            if ($this->token($variant) === $chosen) {
                return $variant;
            }
        }
        $attributes = [];
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                if ((($definition['instance_structure'] ?? [])['equipment_axis'] ?? $definition['equipment_axis'] ?? null) !== 'none') {
                    continue;
                }
                foreach ((array) ($definition['attributes'] ?? []) as $attribute) {
                    if (filled($attribute['value'] ?? null)) {
                        $attributes[] = [$this->ascii((string) ($attribute['field'] ?? '')), $this->token((string) $attribute['value'])];
                    }
                }
            }
        }
        $ownLabel = $this->ascii((string) $equipment->type?->variant_label);
        foreach ([true, false] as $driveFieldsOnly) {
            foreach ($attributes as [$label, $value]) {
                if ($driveFieldsOnly && preg_match('/tahrik|surus|calisma sekli|yakit|motor tipi|enerji/u', $label) !== 1 && ($ownLabel === '' || !str_contains($label, $ownLabel))) {
                    continue;
                }
                foreach ($variants as $variant) {
                    $token = $this->token($variant);
                    if ($value === $token || ($driveFieldsOnly && str_contains($value, $token))) {
                        return $variant;
                    }
                }
            }
        }

        return null;
    }

    // Katalog anahtarı (equipment_specs) → ekipman alanı.
    private const SPEC_FIELDS = ['seri_no' => 'serial_no', 'marka' => 'brand', 'model' => 'model', 'ekipman_no' => 'code', 'bulundugu_yer' => 'place'];

    // Rapordaki kimlik değerleri, ekipman alanlarına eşlenmiş olarak: önce yapay zekanın katalog anahtarıyla verdiği
    // teknik özellikler (equipment_specs), eksik kalanlar tek örnekli ekipman tanımının özelliklerinden.
    // Tanımın kimlik alanı seri no / marka / model / yer değilse ekipman no'dur: adı firmadan firmaya değişir ("Raf Sıra
    // Numarası", "Raf No"...); raporda ayrıca ekipman no yazmıyorsa o kullanılır.
    private function reportValues(array $semantic): array
    {
        $values = [];
        $identityCode = null;
        foreach ((array) ($semantic['extracted_data']['equipment_specs'] ?? []) as $spec) {
            $key = self::SPEC_FIELDS[(string) ($spec['key'] ?? '')] ?? null;
            $value = trim((string) (($spec['value'] ?? null) ?: ($spec['raw_value'] ?? '')));
            if ($key && !isset($values[$key]) && !$this->isEmptyValue($value)) {
                $values[$key] = $value;
            }
        }
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                $structure = (array) ($definition['instance_structure'] ?? []);
                if (($structure['equipment_axis'] ?? $definition['equipment_axis'] ?? null) !== 'none') {
                    continue;
                }
                $pairs = [];
                $identity = $structure['identity_value'] ?? $definition['identity_value'] ?? null;
                if (filled($identity)) {
                    $identityLabel = (string) ($structure['identity_field'] ?? 'no');
                    $pairs[] = [$identityLabel, (string) $identity];
                    if ($this->fieldFor($identityLabel) === null) {
                        $identityCode ??= trim((string) $identity);
                    }
                }
                foreach ((array) ($definition['attributes'] ?? []) as $attribute) {
                    if (filled($attribute['value'] ?? null)) {
                        $pairs[] = [(string) ($attribute['field'] ?? $attribute['source_pattern'] ?? ''), (string) $attribute['value']];
                    }
                }
                foreach ($pairs as [$label, $value]) {
                    $key = $this->fieldFor($label);
                    if ($key && !isset($values[$key]) && !$this->isEmptyValue($value)) {
                        $values[$key] = trim($value);
                    }
                }
            }
        }
        if (!isset($values['code']) && $identityCode !== null && !$this->isEmptyValue($identityCode)) {
            $values['code'] = $identityCode;
        }

        return $values;
    }

    private function fieldFor(string $label): ?string
    {
        $label = $this->ascii($label);
        foreach (self::FIELD_PATTERNS as $field => $pattern) {
            if (preg_match($pattern, $label) === 1) {
                return $field;
            }
        }

        return null;
    }

    private function compare(string $field, string $ours, string $theirs): string
    {
        $a = $this->token($ours);
        $b = $this->token($theirs);
        if ($a === '' || $b === '') {
            return 'unknown';
        }
        if ($a === $b) {
            return 'ok';
        }
        // Ekipman no kısa olabilir ("2"), yer serbest metin: yalnızca birebir eşitlik sayılır.
        if (in_array($field, ['code', 'place'], true)) {
            return 'diff';
        }
        // "EXU20" ↔ "EXU20 / ELEKTRİKLİ", "W40155H05442" ↔ "SN: W40155H05442".
        if (min(strlen($a), strlen($b)) >= 3 && (str_contains($a, $b) || str_contains($b, $a))) {
            return 'ok';
        }
        // Seri no'da tek karakter fark: yazım hatası olabilir. Daha fazlası ayrı ekipman (filodaki seri numaraları
        // birkaç haneyle ayrılır, örn. …G00448 / …G00908).
        if ($field === 'serial_no' && min(strlen($a), strlen($b)) >= 6 && levenshtein($a, $b) <= 1) {
            return 'close';
        }

        return 'diff';
    }

    /**
     * Ekipman türü: yapay zeka katalogdan tür seçtiyse (equipment_type.slug) doğrudan karşılaştırılır; seçmediyse tür
     * adının ayırt edici kelimesi (örn. "transpalet") rapordaki sistem / ekipman adlarında geçiyor mu. Rapor kategorisi
     * (tekli_ekipman, ysc...) tür adı değildir, kullanılmaz.
     */
    private function typeCheck(PkEquipment $equipment, array $semantic): ?array
    {
        $typeName = (string) $equipment->type?->name;
        $slug = trim((string) ($semantic['extracted_data']['equipment_type']['slug'] ?? ''));
        if ($slug !== '' && $equipment->type) {
            $reportType = $slug === $equipment->type->slug ? $equipment->type : PeriodicEquipmentType::query()->where('slug', $slug)->first();

            return [
                'field' => 'type',
                'label' => self::LABELS['type'],
                'equipment' => $typeName,
                'report' => $reportType?->name ?? $slug,
                'result' => $slug === $equipment->type->slug ? 'ok' : 'diff',
            ];
        }

        $words = collect(preg_split('/[^a-z0-9]+/', $this->ascii($typeName)))
            ->filter(fn ($word) => strlen($word) >= 5 && !in_array($word, self::GENERIC_WORDS, true))
            ->values();
        if ($words->isEmpty()) {
            return null;
        }

        $names = [];
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            $names[] = (string) ($system['system_name'] ?? '');
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                $names[] = (string) ($definition['equipment_name'] ?? '');
            }
        }
        $reportText = $this->ascii(implode(' ', array_filter($names)));
        if (trim($reportText) === '') {
            return null;
        }

        return [
            'field' => 'type',
            'label' => self::LABELS['type'],
            'equipment' => $typeName,
            'report' => implode(', ', array_values(array_unique(array_filter($names)))),
            'result' => $words->contains(fn ($word) => str_contains($reportText, $word)) ? 'ok' : 'diff',
        ];
    }

    private function isEmptyValue(string $value): bool
    {
        return in_array($this->token($value), ['', 'NU', 'YOK', 'BELIRTILMEMIS', 'OKUNAMADI'], true);
    }

    // Karşılaştırma için: büyük harf, Türkçe karakterler sadeleşmiş, yalnızca harf ve rakam.
    private function token(string $value): string
    {
        return strtoupper(preg_replace('/[^a-z0-9]/', '', $this->ascii($value)));
    }

    private function ascii(string $value): string
    {
        $value = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $value), 'UTF-8');

        return strtr($value, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
    }
}
