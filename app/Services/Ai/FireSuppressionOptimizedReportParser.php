<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Thin orchestration layer around the existing report parser.
 *
 * It does not replace FireSuppressionReportParser. It only decides which
 * BÖLÜMLER (sections, not raw pages — bkz. ReportSectionSplitter) AI'a
 * gitmeli ve mevcut parser'ın normalizasyonu sahiplenmesine izin verir.
 */
class FireSuppressionOptimizedReportParser
{
    public function __construct(
        private FireSuppressionReportParser $baseParser,
        private ReportSectionSplitter $splitter,
        private FireSuppressionEquipmentListParser $equipmentListParser,
    ) {
    }

    public function parse(array $pages): array
    {
        $sections = $this->splitter->split($pages);

        $aiTexts = [];
        $deterministicTexts = [];
        $equipmentListRecords = [];
        $controlCriteriaSkipped = 0;
        $telemetry = [];

        foreach ($sections as $section) {
            $type = $section['type'];
            $text = $section['text'];

            $telemetry[] = [
                'topic' => $section['topic'],
                'type' => $type,
                'pages' => $section['pages'],
                'length' => mb_strlen($text),
            ];

            // Başlık hiç bulunamadan önceki kısa öneki (rapor şablonunun
            // sayfa üstü birim listesi gibi kalıntı metinler) AI'a göndermeye
            // değmez — gerçek içerik değil, ayrıştırma artığı.
            if ($type === PdfPageClassifier::UNKNOWN && mb_strlen($text) < 300) {
                continue;
            }

            if ($type === PdfPageClassifier::EQUIPMENT_LIST) {
                $records = $this->equipmentListParser->parse($text);

                // Only skip AI when the deterministic parser actually found
                // complete equipment records. Otherwise the old parser gets
                // the section as a safe fallback.
                if ($records !== []) {
                    $equipmentListRecords = [...$equipmentListRecords, ...$records];
                    continue;
                }

                $aiTexts[] = $text;
                continue;
            }

            if ($type === PdfPageClassifier::FINDINGS) {
                $deterministicTexts[] = $text;
                continue;
            }

            if ($type === PdfPageClassifier::CONTROL_CRITERIA) {
                // Bu bölüm ekipman-sütunlu bir U/UD/N matrisi (bkz.
                // FireSuppressionReportParser::parseControlItemMatrices —
                // "No/Kod" satırı) İÇERMİYORSA, bu raporun kontrol
                // kriterleri bölümü gibi TEK bir genel/bina seviyesi
                // checklist'tir (her madde belirli bir ekipmana değil,
                // tesisin kendisine bağlıdır). Mevcut taslak şeması
                // (equipment/findings/control_date/company_name/
                // overall_result) bu veriden HİÇBİR ALANI doldurmuyor — AI'a
                // gönderilse de boş bir tahmin üretir, o yüzden hiç
                // gönderilmez. Matrisli bir rapor şablonunda ("No/Kod" varsa)
                // eski davranış (AI/baseParser'a gönder, o kendi matris
                // regex'iyle zaten AI'ı atlar) korunur.
                if (! $this->looksLikeEquipmentColumnMatrix($text)) {
                    $controlCriteriaSkipped++;
                    continue;
                }
            }

            $aiTexts[] = $text;
        }

        $aiDraft = $aiTexts === []
            ? $this->emptyDraft()
            : $this->baseParser->parse($aiTexts);

        // Let the existing parser own deterministic finding parsing. The
        // finding section is never sent to NIM by the base parser, so this
        // call adds no AI request while keeping its established
        // normalization.
        $deterministicDraft = $deterministicTexts === []
            ? $this->emptyDraft()
            : $this->baseParser->parse($deterministicTexts);

        $draft = $this->mergeDrafts($aiDraft, $deterministicDraft);
        $draft['equipment'] = $this->mergeEquipment($draft['equipment'], $equipmentListRecords);
        $draft = $this->attachFindingDescriptions($draft);

        Log::info('FireSuppressionOptimizedReportParser: bölüm yönlendirme', [
            'total_pages' => count($pages),
            'total_sections' => count($sections),
            'ai_sections' => count($aiTexts),
            'deterministic_finding_sections' => count($deterministicTexts),
            'control_criteria_skipped' => $controlCriteriaSkipped,
            'equipment_list_records' => count($equipmentListRecords),
            'sections' => $telemetry,
        ]);

        return $draft;
    }

    // FireSuppressionReportParser::parseControlItemMatrices()'in aradığı AYNI
    // işaret ("No/Kod" başlık satırı, ekipman-sütunlu matrisin imzası) —
    // burada sadece bir bölümü AI'a göndermeye değip değmeyeceğine karar
    // vermek için kullanılıyor, matrisin kendisi hâlâ baseParser içinde
    // ayrıştırılıyor.
    private function looksLikeEquipmentColumnMatrix(string $text): bool
    {
        return (bool) preg_match('/no\s*\/?\s*kod\b/u', $this->lowerTr($text));
    }

    private function lowerTr(string $value): string
    {
        $value = str_replace(['İ', 'I'], ['i', 'ı'], $value);

        return mb_strtolower($value, 'UTF-8');
    }

    private function emptyDraft(): array
    {
        return [
            'control_date' => null,
            'next_control_date' => null,
            'overall_result' => null,
            'company_name' => null,
            'covered_categories' => [],
            'equipment' => [],
            'findings' => [],
        ];
    }

    private function mergeDrafts(array $primary, array $secondary): array
    {
        foreach (['control_date', 'next_control_date', 'overall_result', 'company_name'] as $key) {
            if (($primary[$key] ?? null) === null && ($secondary[$key] ?? null) !== null) {
                $primary[$key] = $secondary[$key];
            }
        }

        $primary['covered_categories'] = array_values(array_unique([
            ...($primary['covered_categories'] ?? []),
            ...($secondary['covered_categories'] ?? []),
        ]));

        $primary['equipment'] = $this->mergeEquipment(
            $primary['equipment'] ?? [],
            $secondary['equipment'] ?? []
        );

        $primary['findings'] = $this->mergeFindings(
            $primary['findings'] ?? [],
            $secondary['findings'] ?? []
        );

        return $primary;
    }

    private function mergeEquipment(array $primary, array $secondary): array
    {
        $indexByCode = [];

        foreach ($primary as $index => $item) {
            $code = $this->normalizeCode($item['code'] ?? null);
            if ($code !== null) {
                $indexByCode[$code] = $index;
            }
        }

        foreach ($secondary as $item) {
            $code = $this->normalizeCode($item['code'] ?? null);

            if ($code !== null && isset($indexByCode[$code])) {
                $index = $indexByCode[$code];
                $existing = $primary[$index];

                foreach (['category', 'location_note', 'brand', 'model', 'serial_no', 'result', 'note'] as $field) {
                    if (($existing[$field] ?? null) === null && ($item[$field] ?? null) !== null) {
                        $existing[$field] = $item[$field];
                    }
                }

                if (($item['control_items'] ?? []) !== []) {
                    $existing['control_items'] = $item['control_items'];
                }

                $existing['is_uncertain'] = ($existing['is_uncertain'] ?? false) && ($item['is_uncertain'] ?? false);
                $primary[$index] = $existing;
                continue;
            }

            $primary[] = $item;
            if ($code !== null) {
                $indexByCode[$code] = array_key_last($primary);
            }
        }

        return array_values($primary);
    }

    private function mergeFindings(array $primary, array $secondary): array
    {
        $result = $primary;

        foreach ($secondary as $finding) {
            $key = ($finding['control_item'] ?? '') . '|' . ($finding['description'] ?? '');
            $exists = false;

            foreach ($result as $existing) {
                $existingKey = ($existing['control_item'] ?? '') . '|' . ($existing['description'] ?? '');
                if ($existingKey === $key) {
                    $exists = true;
                    break;
                }
            }

            if (! $exists) {
                $result[] = $finding;
            }
        }

        return $result;
    }

    private function attachFindingDescriptions(array $draft): array
    {
        $descriptionByCode = [];

        foreach ($draft['findings'] ?? [] as $finding) {
            $code = $this->normalizeCode($finding['control_item'] ?? null);
            $description = trim((string) ($finding['description'] ?? ''));

            if ($code !== null && $description !== '') {
                $descriptionByCode[$code] = isset($descriptionByCode[$code])
                    ? $descriptionByCode[$code] . ' ' . $description
                    : $description;
            }
        }

        foreach ($draft['equipment'] ?? [] as $equipmentIndex => $equipment) {
            $udNotes = [];

            foreach ($equipment['control_items'] ?? [] as $itemIndex => $controlItem) {
                $code = $this->normalizeCode($controlItem['code'] ?? null);
                if ($code === null || ! isset($descriptionByCode[$code])) {
                    continue;
                }

                $draft['equipment'][$equipmentIndex]['control_items'][$itemIndex]['description'] = $descriptionByCode[$code];

                if (($controlItem['status'] ?? null) === 'uygun_degil') {
                    $udNotes[] = $descriptionByCode[$code];
                }
            }

            if ($udNotes !== []) {
                $draft['equipment'][$equipmentIndex]['note'] = implode(' ', array_values(array_unique($udNotes)));
            }
        }

        return $draft;
    }

    private function normalizeCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/(\d+\.\d+)/u', $value, $matches)) {
            return $matches[1];
        }

        return mb_strtolower($value, 'UTF-8');
    }
}
