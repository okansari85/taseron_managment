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
        private FireSuppressionPumpListParser $pumpListParser,
        private FireSuppressionGeneralInfoParser $generalInfoParser,
    ) {
    }

    public function parse(array $pages): array
    {
        $sections = $this->splitter->split($pages);

        // AI'a giden metin İKİ ayrı gruba bölünür — "equipment" alanına
        // GÜVENİLEN grup (gerçekten bir ekipman tablosu olduğu bilinen
        // bölümler: deterministik ayrıştırması başarısız olan equipment_list
        // bölümleri — pompa/sprinkler gibi — ve matrisli control_criteria)
        // ile GÜVENİLMEYEN grup (genel bilgiler, sonuç, tanınmayan artık —
        // buralarda GERÇEK bir ekipman tablosu YOKTUR). Bunları AYNI çağrıda
        // birleştirip AI'ın "equipment" çıktısına toptan güvenmek gerçek
        // veride bir hataya yol açtı: "1. GENEL BİLGİLER" bölümündeki
        // "Ekipman Seri No / Kod: YT-01" tek bir meta alanını AI hayali bir
        // dolap kaydına çevirdi. Artık meta grubun AI çıktısındaki equipment
        // alanı TAMAMEN YOK SAYILIYOR (bkz. aşağıda emptyDraft ile sıfırlama).
        $aiEquipmentTexts = [];
        $aiMetaTexts = [];
        $deterministicTexts = [];
        $equipmentListRecords = [];
        $controlCriteriaSkipped = 0;
        $telemetry = [];
        $deterministicMetaDraft = $this->emptyDraft();
        $generalInfoSkipped = false;
        $resultSkipped = false;

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

            if ($section['topic'] === 'equipment_list:pompa') {
                $records = $this->pumpListParser->parse($text);

                if ($records !== []) {
                    $equipmentListRecords = [...$equipmentListRecords, ...$records];
                    continue;
                }

                $aiEquipmentTexts[] = $text;
                continue;
            }

            if ($type === PdfPageClassifier::EQUIPMENT_LIST) {
                $records = $this->equipmentListParser->parse($text);

                // GEÇİCİ TEŞHİS LOGU — dolap/hidrant sayısının gerçek
                // rapordan mı az geldiğini yoksa parser'ın bir kısmı
                // mükerrer/erken kesiyor mu diye görmek için (kaldırılacak).
                if ($records !== []) {
                    $codes = array_map(fn (array $r) => (int) preg_replace('/\D/', '', (string) $r['code']), $records);
                    Log::info('FireSuppressionOptimizedReportParser: ekipman bölümü ayrıştırıldı', [
                        'topic' => $section['topic'],
                        'raw_record_count' => count($records),
                        'unique_codes' => count(array_unique(array_column($records, 'code'))),
                        'min_code' => $codes === [] ? null : min($codes),
                        'max_code' => $codes === [] ? null : max($codes),
                    ]);
                }

                // Only skip AI when the deterministic parser actually found
                // complete equipment records. Otherwise the old parser gets
                // the section as a safe fallback.
                if ($records !== []) {
                    $equipmentListRecords = [...$equipmentListRecords, ...$records];
                    continue;
                }

                $aiEquipmentTexts[] = $text;
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

                // Matrisli bir control_criteria bölümü GERÇEKTEN ekipman
                // sütunları taşıyor (bkz. looksLikeEquipmentColumnMatrix) —
                // bu yüzden equipment-güvenilen gruba gider, meta gruba değil.
                $aiEquipmentTexts[] = $text;
                continue;
            }

            // Genel Bilgiler ve Sonuç ve Kanaat, TÜRKAK akreditasyonunun
            // zorunlu kıldığı yapısal bölümlerdir — etiket kelimeleri firmaya
            // göre değişse de (bkz. FireSuppressionGeneralInfoParser'daki
            // gerekçe) şekli hep aynı. Deterministik olarak ÇÖZÜLEBİLDİĞİ
            // kadarıyla bu metin AI'a hiç gönderilmez; eksik/emin olunamayan
            // kısım normal şekilde AI'a (aiMetaTexts) düşmeye devam eder.
            if ($type === PdfPageClassifier::GENERAL_INFO) {
                $resolved = $this->generalInfoParser->parseGeneralInfo($text);

                if ($deterministicMetaDraft['control_date'] === null) {
                    $deterministicMetaDraft['control_date'] = $resolved['control_date'];
                }
                if ($deterministicMetaDraft['next_control_date'] === null) {
                    $deterministicMetaDraft['next_control_date'] = $resolved['next_control_date'];
                }
                if ($deterministicMetaDraft['company_name'] === null) {
                    $deterministicMetaDraft['company_name'] = $resolved['company_name'];
                }

                if ($this->generalInfoParser->isGeneralInfoComplete($resolved)) {
                    $generalInfoSkipped = true;
                    continue;
                }

                $aiMetaTexts[] = $text;
                continue;
            }

            if ($type === PdfPageClassifier::RESULT) {
                $resolvedResult = $this->generalInfoParser->parseOverallResult($text);

                if ($resolvedResult !== null) {
                    if ($deterministicMetaDraft['overall_result'] === null) {
                        $deterministicMetaDraft['overall_result'] = $resolvedResult;
                    }

                    $resultSkipped = true;
                    continue;
                }

                $aiMetaTexts[] = $text;
                continue;
            }

            $aiMetaTexts[] = $text;
        }

        $equipmentAiDraft = $aiEquipmentTexts === []
            ? $this->emptyDraft()
            : $this->baseParser->parse($aiEquipmentTexts);

        $metaAiDraft = $aiMetaTexts === []
            ? $this->emptyDraft()
            : $this->baseParser->parse($aiMetaTexts);
        // Genel bilgiler/sonuç/tanınmayan bölümlerde GERÇEK bir ekipman
        // tablosu yoktur — buradan gelen equipment'e HİÇ güvenilmez (yukarıda
        // açıklanan YT-01 hatası). control_date/company_name/overall_result
        // gibi diğer alanlar normal şekilde kullanılmaya devam eder.
        $metaAiDraft['equipment'] = [];

        // Let the existing parser own deterministic finding parsing. The
        // finding section is never sent to NIM by the base parser, so this
        // call adds no AI request while keeping its established
        // normalization. Bulgu metninden AI'a düşülürse bile equipment
        // GÜVENİLMEZ — "Finding → Equipment matching" mimari kuralı henüz
        // geçerli (bkz. proje notları), bir bulgu cümlesinden yeni bir
        // ekipman kaydı asla türetilmemeli.
        $deterministicDraft = $deterministicTexts === []
            ? $this->emptyDraft()
            : $this->baseParser->parse($deterministicTexts);
        $deterministicDraft['equipment'] = [];

        // Deterministik meta alanları (tarih/firma/sonuç) EN YÜKSEK öncelik
        // — AI'dan geldiyse onun üzerine yazılmaz (mergeDrafts zaten "primary
        // alanı doluysa dokunma" kuralıyla çalışıyor).
        $draft = $this->mergeDrafts($deterministicMetaDraft, $metaAiDraft);
        $draft = $this->mergeDrafts($draft, $deterministicDraft);
        $draft['equipment'] = $this->mergeEquipment($draft['equipment'], $equipmentAiDraft['equipment']);
        $draft['equipment'] = $this->mergeEquipment($draft['equipment'], $equipmentListRecords);
        $draft = $this->attachFindingDescriptions($draft);

        Log::info('FireSuppressionOptimizedReportParser: bölüm yönlendirme', [
            'total_pages' => count($pages),
            'general_info_deterministic' => $generalInfoSkipped,
            'result_deterministic' => $resultSkipped,
            'total_sections' => count($sections),
            'ai_equipment_sections' => count($aiEquipmentTexts),
            'ai_meta_sections' => count($aiMetaTexts),
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
            $code = $this->equipmentMergeKey($item);
            if ($code !== null) {
                $indexByCode[$code] = $index;
            }
        }

        foreach ($secondary as $item) {
            $code = $this->equipmentMergeKey($item);

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

    // Ekipman kodları KATEGORİ İÇİNDE benzersizdir, TÜM rapor genelinde
    // değil — dolap listesi "1,2,3..." ile başlar, hidrant listesi de AYNI
    // "1,2,3..." ile başlar (bkz. gerçek rapor: A Dolap No 1 2 3 4 5 / A
    // Hidrant No 1 2 3 4 5). Eşleştirmeyi SADECE koda göre yapmak (kategori
    // yoksayılarak) hidrant kaydı "1"i dolap kaydı "1"in ÜZERİNE düşürüyordu
    // — 37 hidrant kaydının TAMAMI bu yüzden sessizce kayboluyordu (gerçek
    // testte kategori dağılımında "hidrant" hiç görünmedi). Anahtara
    // kategoriyi de katmak bu çakışmayı ortadan kaldırır.
    private function equipmentMergeKey(array $item): ?string
    {
        $code = $this->normalizeCode($item['code'] ?? null);

        if ($code === null) {
            return null;
        }

        $category = trim((string) ($item['category'] ?? ''));

        return ($category !== '' ? mb_strtolower($category, 'UTF-8') : '') . '|' . $code;
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
