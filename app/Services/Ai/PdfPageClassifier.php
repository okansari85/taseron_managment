<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Classifies extracted PDF pages using deterministic signals only.
 *
 * This is intentionally conservative: an unknown/ambiguous page is returned
 * as UNKNOWN so the existing AI fallback can handle it safely.
 */
class PdfPageClassifier
{
    public const GENERAL_INFO = 'general_info';
    public const CONTROL_CRITERIA = 'control_criteria';
    public const EQUIPMENT_LIST = 'equipment_list';
    public const FINDINGS = 'findings';
    public const RESULT = 'result';
    public const UNKNOWN = 'unknown';

    public function classify(string $pageText): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $pageText) ?? '');
        $lower = $this->lowerTr($text);

        if ($text === '') {
            return $this->result(self::UNKNOWN, 0.0, []);
        }

        // Strong section headings are safer than generic keyword matching.
        if ($this->containsAny($lower, [
            'kusur açıklamaları / notlar',
            'tespit ve bulgular',
            'bulgular / notlar',
        ])) {
            return $this->result(self::FINDINGS, 0.99, ['findings_heading']);
        }

        if ($this->containsAny($lower, [
            'sonuç ve kanaat',
            'onay',
        ]) && $this->containsAny($lower, [
            'periyodik kontrol tarihi',
            'kullanımı uygun değildir',
            'kullanımı uygundur',
            'kullanılması uygun değildir',
            'kullanılması uygundur',
        ])) {
            return $this->result(self::RESULT, 0.98, ['result_heading']);
        }

        if ($this->containsAny($lower, [
            'muayene kriterleri ve testler',
            'kontrol kriterleri ve testler',
        ])) {
            return $this->result(self::CONTROL_CRITERIA, 0.99, ['control_criteria_heading']);
        }

        // Equipment/inventory list signals. We require a repeating equipment
        // label plus at least one measurement/location field, avoiding false
        // positives from ordinary narrative pages.
        $equipmentSignals = 0;
        $signals = [];

        foreach ([
            'yangın dolabı listesi' => 'fire_cabinet_heading',
            'hidrant listesi' => 'hydrant_heading',
            'sprinkler listesi' => 'sprinkler_heading',
            'su alma/verme ağızları' => 'water_connection_heading',
            'dolap no' => 'cabinet_number',
            'hidrant no' => 'hydrant_number',
            'soru / kriter' => 'criteria_table',
            'bulunduğu yer' => 'location_column',
            'ölçülen basınç' => 'pressure_column',
        ] as $needle => $signal) {
            if (str_contains($lower, $needle)) {
                $equipmentSignals++;
                $signals[] = $signal;
            }
        }

        if ($equipmentSignals >= 3 && $this->hasRepeatingListShape($lower)) {
            $confidence = $equipmentSignals >= 5 ? 0.98 : 0.94;
            return $this->result(self::EQUIPMENT_LIST, $confidence, array_values(array_unique($signals)));
        }

        // Generic report metadata. Kept deliberately lower confidence so an
        // unfamiliar page can safely fall through to the existing AI path.
        $generalSignals = 0;
        $general = [];
        foreach ([
            'genel bilgiler' => 'general_heading',
            'rapor no' => 'report_number',
            'muayene tarihi' => 'inspection_date',
            'gelecek muayene tarihi' => 'next_inspection_date',
            'kontrol talep eden kuruluş' => 'requesting_organization',
        ] as $needle => $signal) {
            if (str_contains($lower, $needle)) {
                $generalSignals++;
                $general[] = $signal;
            }
        }

        if ($generalSignals >= 2) {
            return $this->result(self::GENERAL_INFO, 0.93, $general);
        }

        // GEÇİCİ TEŞHİS LOGU — mantığı değiştirmiyor, sadece bu raporun
        // gerçek kelime kalıplarının neden hiçbir anahtar kelimeyle
        // eşleşmediğini görmek için. Sonuç netleşince kaldırılacak.
        Log::info('PdfPageClassifier: UNKNOWN sayfa', [
            'text_length' => mb_strlen($lower),
            'text_preview' => mb_substr($lower, 0, 400),
        ]);

        return $this->result(self::UNKNOWN, 0.0, []);
    }

    // $text: ÖNCEDEN lowerTr() ile küçültülmüş olmalı — PCRE'nin /i bayrağı
    // Türkçe noktalı "İ"yi "i" ile güvenilir bir şekilde eşleştirmiyor
    // (motor/Unicode tablo sürümüne göre değişebiliyor), bu yüzden burada
    // /i'ye güvenmek yerine zaten küçültülmüş metin + küçük harfli desenler
    // kullanılıyor.
    private function hasRepeatingListShape(string $text): bool
    {
        $patterns = [
            '/(?:soru\s*\/\s*kriter)\s+\d+(?:\s+\d+){1,}/u',
            '/(?:dolap|hidrant)\s+no\b/u',
            '/(?:ölçülen\s+basınç|bulunduğu\s+yer).*(?:\d|[a-zçğıöşü])/u',
        ];

        $matches = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    // mb_strtolower('UTF-8') Türkçe İ/I'yı doğru küçültmez: "İ" → "i" +
    // birleşen nokta işareti (2 kod noktası, düz "i" DEĞİL), "I" (noktasız
    // büyük) → "i" (olması gereken "ı" değil). Gerçek raporların büyük
    // harfli başlıkları ("TESPİT VE BULGULAR", "HİDRANT NO", "BASINÇ")
    // bu yüzden düz mb_strtolower ile anahtar kelimelerle EŞLEŞMİYORDU —
    // sınıflandırma sessizce UNKNOWN'a düşüp sayfayı gereksiz yere AI'a
    // gönderiyordu. Önce elle İ/I değiştirilip sonra küçültülüyor (bkz.
    // FireSuppressionReportParser::toLowerTr() — aynı hata orada bulunmuştu).
    private function lowerTr(string $value): string
    {
        $value = str_replace(['İ', 'I'], ['i', 'ı'], $value);

        return mb_strtolower($value, 'UTF-8');
    }

    private function result(string $type, float $confidence, array $signals): array
    {
        return [
            'type' => $type,
            'confidence' => $confidence,
            'signals' => $signals,
        ];
    }
}
