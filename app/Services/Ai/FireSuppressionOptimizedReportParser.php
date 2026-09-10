<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Thin orchestration layer around the existing report parser.
 *
 * It does not replace FireSuppressionReportParser. It only decides which
 * pages need AI and lets the existing parser continue to own normalization.
 */
class FireSuppressionOptimizedReportParser
{
    public function __construct(
        private FireSuppressionReportParser $baseParser,
        private PdfPageClassifier $classifier,
        private FireSuppressionEquipmentListParser $equipmentListParser,
    ) {
    }

    public function parse(array $pages): array
    {
        $aiPages = [];
        $deterministicPages = [];
        $equipmentListPages = [];
        $telemetry = [];

        foreach (array_values($pages) as $index => $pageText) {
            $classification = $this->classifier->classify($pageText);
            $type = $classification['type'] ?? PdfPageClassifier::UNKNOWN;

            $telemetry[] = [
                'page' => $index + 1,
                'type' => $type,
                'confidence' => $classification['confidence'] ?? 0.0,
            ];

            if ($type === PdfPageClassifier::EQUIPMENT_LIST) {
                $records = $this->equipmentListParser->parse($pageText);

                // Only skip AI when the deterministic parser actually found
                // complete equipment records. Otherwise the old parser gets
                // the page as a safe fallback.
                if ($records !== []) {
                    $equipmentListPages = [...$equipmentListPages, ...$records];
                    continue;
                }
            }

            // Findings are already deterministic in the existing parser.
            // Keep control-criteria pages on the old path unless its own
            // matrix detector proves they are deterministic; this prevents
            // the new classifier from accidentally suppressing AI on an
            // unfamiliar checklist layout.
            if ($type === PdfPageClassifier::FINDINGS) {
                $deterministicPages[] = $pageText;
                continue;
            }

            $aiPages[] = $pageText;
        }

        $aiDraft = $aiPages === []
            ? $this->emptyDraft()
            : $this->baseParser->parse($aiPages);

        // Let the existing parser own deterministic finding parsing. The
        // finding page is never sent to NIM by the base parser, so this call
        // adds no AI request while keeping its established normalization.
        $deterministicDraft = $deterministicPages === []
            ? $this->emptyDraft()
            : $this->baseParser->parse($deterministicPages);

        $draft = $this->mergeDrafts($aiDraft, $deterministicDraft);
        $draft['equipment'] = $this->mergeEquipment($draft['equipment'], $equipmentListPages);
        $draft = $this->attachFindingDescriptions($draft);

        Log::info('FireSuppressionOptimizedReportParser: sayfa yönlendirme', [
            'total_pages' => count($pages),
            'ai_pages' => count($aiPages),
            'deterministic_pages' => count($deterministicPages),
            'equipment_list_records' => count($equipmentListPages),
            'classification' => $telemetry,
        ]);

        return $draft;
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
