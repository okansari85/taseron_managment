<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Models\PkBulkReport;
use App\Models\PkEquipment;
use App\Models\PkInspection;
use App\Models\User;
use App\Services\Ai\PkTakip\PkBulkReportReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Tüp kontrol formu (toplu rapor): "Rapordan Ekipman Tanımla" ya da "Tüp Kontrol Formu Yükle" ile okunur. Kayıtta tüpler
 * Ekipmanlar'daki kayıtlarla eşleşir ya da eklenir; her tüpe bu formla kendi sonucunda kontrol kaydı açılır. Form, genel
 * sonucu, bölümleri (raporda yazdığı gibi) ve genel bulgularıyla saklanır; silinince tüplerdeki kontrol kayıtları da silinir.
 * Tek tüpe rapor yükleme (Ekipmanlar) ayrıca açıktır.
 */
class PkBulkReportService
{
    public function __construct(
        private TenantContext $tenantContext,
        private PkEquipmentService $equipment,
        private PkEquipmentPropertyService $properties,
        private PkReportAnalysisHistory $history,
        private PkBulkReportReader $reader
    ) {
    }

    /**
     * Okuma sonucu kayda kadar saklanır (24 saat; kayıt analysis_id ile). Pencere için: tüp satırları, bölümler, lokasyondaki
     * mevcut (aktif) tüpler (eşleştirme için) ve formun daha önce yüklenip yüklenmediği.
     */
    public function rememberAnalysis(string $analysisId, array $result, ?int $locationId, ?string $fileHash): array
    {
        Cache::put($this->analysisKey($analysisId), [
            'tenant_id' => $this->tenantContext->id(),
            'location_id' => $locationId,
            'model' => $result['fixture']['model'] ?? \App\Services\Ai\PkTakip\PkAiProvider::model(),
            'analyzed_at' => now()->toIso8601String(),
            'fixture' => $result['fixture'] ?? null,
            'duration_s' => $result['duration_s'] ?? null,
            'input' => $result['input'] ?? null,
            'semantic' => $result['semantic'] ?? [],
            'tables' => $result['tables'] ?? null,
        ], now()->addDay());

        $typeId = $result['bulk']['type']['id'] ?? null;

        return [
            // Sonuç ve kanaat metni de (formda gösterilir, kayda yazılır).
            'bulk' => $result['bulk'] + ['conclusion' => $result['overall_text'] ?? null],
            'bulk_duplicate' => $this->duplicate($result['report_no'] ?? null, $fileHash),
            'location_equipment' => $locationId && $typeId
                ? $this->equipment->list(['location_id' => $locationId, 'type_id' => $typeId])
                    ->filter(fn (array $item) => $item['is_active'])
                    ->map(fn (array $item) => Arr::only($item, ['id', 'code', 'place', 'variant', 'serial_no', 'status']) + ['workplace' => $item['workplace']['company_name'] ?? null])
                    ->values()->all()
                : [],
        ];
    }

    /** Lokasyondaki formlar (en yeni önce). İşyeri context'inde o işyerininkiler + lokasyon geneli. */
    public function list(array $filters): Collection
    {
        return PkBulkReport::query()
            ->with(['creator:id,name', 'type:id,name'])
            ->withCount('inspections')
            ->where('location_id', $filters['location_id'])
            ->when($filters['workplace_id'] ?? null, fn ($query, $workplaceId) => $query->where(
                fn ($inner) => $inner->whereNull('location_business_entity_id')->orWhere('location_business_entity_id', $workplaceId)
            ))
            ->orderByDesc('control_date')->orderByDesc('id')
            ->get()
            ->map(fn (PkBulkReport $report) => $this->present($report))
            ->values();
    }

    // Form detayı: bölümler, bulgular ve tüpler (her tüpün bu formdaki sonucu).
    public function show(PkBulkReport $report): array
    {
        $report->load(['creator:id,name', 'type:id,name'])->loadCount('inspections');
        $inspections = $report->inspections()->with('equipment')->get();

        return $this->present($report) + [
            'conclusion' => $report->conclusion,
            'equipment' => $inspections->map(fn (PkInspection $inspection) => [
                'inspection_id' => $inspection->id,
                'status' => $inspection->status,
                'findings' => $inspection->findings ?? [],
                'equipment' => $inspection->equipment ? [
                    'id' => $inspection->equipment->id,
                    'code' => $inspection->equipment->code,
                    'place' => $inspection->equipment->place,
                    'variant' => $inspection->equipment->variant,
                    'is_active' => $inspection->equipment->is_active !== false,
                ] : null,
            ])->sortBy(fn (array $row) => [$row['equipment']['place'] ?? '', $row['equipment']['code'] ?? ''])->values(),
        ];
    }

    /**
     * Formu kaydeder: tüpler kullanıcının seçtiği mevcut tüp ya da yeni tüp (Ekipmanlar'a eklenir); her birine bu formla
     * kontrol kaydı (sonuç satırdan; yoksa belirtilmemiş), katalogla eşleşen özellikler (yeni tüpte geçerli, mevcutta boşsa).
     */
    public function save(array $data, UploadedFile $file, User $user): PkBulkReport
    {
        $type = $this->reader->type();
        $locationId = (int) $data['location_id'];
        // Tüpler lokasyonun tamamına kayıtlıdır (firma bazında değil): form ve yeni tüpler lokasyon geneli.
        $workplaceId = null;
        $this->assertPlacement($locationId, $workplaceId);

        $analysisKey = !empty($data['analysis_id']) ? $this->analysisKey($data['analysis_id']) : null;
        $analysis = $analysisKey ? Cache::get($analysisKey) : null;
        if ($analysisKey && (!is_array($analysis) || $analysis['tenant_id'] !== $this->tenantContext->id())) {
            throw ValidationException::withMessages(['analysis_id' => 'Yapay zeka analizinin süresi doldu; raporu yeniden okuyun.']);
        }

        $reportNo = mb_substr(trim((string) ($data['report_no'] ?? '')), 0, 100) ?: null;
        $hash = hash_file('sha256', $file->getRealPath());
        if ($duplicate = $this->duplicate($reportNo, $hash)) {
            throw ValidationException::withMessages(['report' => $duplicate['message']]);
        }
        $rows = $this->cleanRows((array) ($data['rows'] ?? []), $locationId, $type->id);
        if ($rows === []) {
            throw ValidationException::withMessages(['rows' => 'Kaydedilecek tüp yok: en az bir satırı mevcut ya da yeni tüp olarak işaretleyin.']);
        }

        $path = $file->store('pk-bulk-reports/' . $this->tenantContext->id(), 'local');
        $linked = [];
        try {
            $report = DB::transaction(function () use ($data, $type, $locationId, $workplaceId, $analysis, $reportNo, $hash, $rows, $file, $path, $user, &$linked) {
                $report = PkBulkReport::create([
                    'tenant_id' => $this->tenantContext->id(),
                    'location_id' => $locationId,
                    'location_business_entity_id' => $workplaceId,
                    'equipment_type_id' => $type->id,
                    'control_date' => $data['control_date'],
                    'next_control_date' => $data['next_control_date'] ?? null,
                    'status' => in_array($data['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $data['status'] : null,
                    'source' => $analysis ? 'ai' : 'manual',
                    'report_file' => $path,
                    'report_file_name' => $file->getClientOriginalName(),
                    'report_no' => $reportNo,
                    'report_hash' => $hash,
                    'inspection_body' => trim((string) ($data['inspection_body'] ?? '')) ?: null,
                    'conclusion' => trim((string) ($data['conclusion'] ?? '')) ?: null,
                    'systems' => $this->cleanSystems((array) ($data['systems'] ?? [])) ?: null,
                    'findings' => $this->cleanTexts((array) ($data['findings'] ?? [])) ?: null,
                    'analysis' => $analysis ? Arr::only($analysis, ['model', 'analyzed_at', 'fixture', 'duration_s', 'input', 'semantic', 'tables']) : null,
                    'created_by' => $user->id,
                ]);

                foreach ($rows as $row) {
                    $equipment = $row['equipment_id']
                        ? PkEquipment::query()->where('location_id', $locationId)->where('is_active', true)->find($row['equipment_id'])
                        : $this->equipment->create([
                            'location_id' => $locationId,
                            'location_business_entity_id' => $workplaceId,
                            'equipment_type_id' => $type->id,
                            'variant' => in_array($row['variant'], (array) ($type->variants ?? []), true) ? $row['variant'] : null,
                            'code' => $row['code'],
                            'place' => $row['place'],
                            'brand' => $row['brand'],
                            'model' => $row['model'],
                            'serial_no' => $row['serial_no'],
                        ], $user);
                    if (!$equipment) {
                        throw ValidationException::withMessages(['rows' => 'Seçilen tüplerden biri bu lokasyonda bulunamadı ya da pasif.']);
                    }
                    $linked[] = $reportFile = $this->equipment->linkReportFile($path, $equipment->id);
                    $inspection = PkInspection::create([
                        'tenant_id' => $this->tenantContext->id(),
                        'pk_equipment_id' => $equipment->id,
                        'control_date' => $report->control_date,
                        'next_control_date' => $report->next_control_date,
                        'status' => $row['status'],
                        'source' => $report->source,
                        'report_file' => $reportFile,
                        'report_file_name' => $report->report_file_name,
                        'report_no' => $report->report_no,
                        'report_hash' => $report->report_hash,
                        'inspection_body' => $report->inspection_body,
                        'findings' => $row['findings'] ?: null,
                        'created_by' => $user->id,
                    ]);
                    $report->inspections()->attach($inspection->id);
                    if ($row['properties']) {
                        $this->properties->recordTableRow($equipment, $inspection, $row['properties'], $report->report_no, !$row['equipment_id'], $user);
                    }
                }

                return $report;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete([$path, ...$linked]);
            throw $exception;
        }
        if ($analysisKey) {
            Cache::forget($analysisKey);
            // Analiz geçmişi: okuma bu forma dönüştü.
            rescue(fn () => $this->history->markSaved($data['analysis_id'], ['pk_bulk_report_id' => $report->id], $path));
        }

        return $report;
    }

    // Form silinir: dosyası ve tüplere açılan kontrol kayıtları da (Ekipmanlar'ın kendi silme işlemiyle); tüpler kalır.
    public function delete(PkBulkReport $report): void
    {
        foreach ($report->inspections()->with('equipment')->get() as $inspection) {
            if ($inspection->equipment) {
                $this->equipment->deleteInspection($inspection->equipment, $inspection);
            } else {
                $inspection->delete();
            }
        }
        $file = $report->report_file;
        $report->delete();
        Storage::disk('local')->delete($file);
    }

    // Rapor dosyası (private disk): [mutlak yol, indirme adı].
    public function reportFile(PkBulkReport $report): array
    {
        if (!$report->report_file || !Storage::disk('local')->exists($report->report_file)) {
            abort(404, 'Rapor dosyası bulunamadı.');
        }

        return [Storage::disk('local')->path($report->report_file), $report->report_file_name ?: basename($report->report_file)];
    }

    // Aynı form ikinci kez yüklenemez: rapor no ya da aynı dosya.
    public function duplicate(?string $reportNo, ?string $hash): ?array
    {
        $reportNo = trim((string) $reportNo);
        if ($reportNo === '' && blank($hash)) {
            return null;
        }
        $existing = PkBulkReport::query()
            ->where(fn ($query) => $query
                ->when($reportNo !== '', fn ($q) => $q->orWhere('report_no', $reportNo))
                ->when(filled($hash), fn ($q) => $q->orWhere('report_hash', $hash)))
            ->orderBy('id')
            ->first();
        if (!$existing) {
            return null;
        }
        $by = $reportNo !== '' && $existing->report_no === $reportNo ? "rapor no {$reportNo}" : 'aynı dosya';

        return [
            'report_id' => $existing->id,
            'message' => "Bu tüp kontrol formu zaten yüklenmiş ({$by}), kontrol tarihi " . ($existing->control_date?->format('d.m.Y') ?? '—') . '.',
        ];
    }

    /**
     * Formdaki tüp satırları: "Ekleme" seçilenler gelmez. Mevcut tüp aynı lokasyonda, aynı türde ve aktif olmalı; aynı tüp
     * iki satırda seçilemez. Sonuç uygun / uygun değil, yoksa belirtilmemiş.
     */
    private function cleanRows(array $rows, int $locationId, int $typeId): array
    {
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max) ?: null;
        $existing = PkEquipment::query()->where('location_id', $locationId)->where('equipment_type_id', $typeId)->where('is_active', true)->pluck('id')->all();
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $equipmentId = !empty($row['equipment_id']) ? (int) $row['equipment_id'] : null;
            if ($equipmentId && !in_array($equipmentId, $existing, true)) {
                throw ValidationException::withMessages(['rows' => 'Seçilen tüplerden biri bu lokasyonda bulunamadı ya da pasif.']);
            }
            if ($equipmentId && isset($seen[$equipmentId])) {
                throw ValidationException::withMessages(['rows' => 'Aynı tüp iki satırda seçilmiş.']);
            }
            $seen[$equipmentId ?? 0] = true;
            $out[] = [
                'equipment_id' => $equipmentId,
                'status' => in_array($row['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $row['status'] : 'belirtilmemis',
                'code' => $text($row['code'] ?? null, 100),
                'place' => $text($row['place'] ?? null, 255),
                'variant' => $text($row['variant'] ?? null, 50),
                'brand' => $text($row['brand'] ?? null, 100),
                'model' => $text($row['model'] ?? null, 100),
                'serial_no' => $text($row['serial_no'] ?? null, 100),
                'properties' => collect((array) ($row['properties'] ?? []))
                    ->filter(fn ($value, $label) => is_scalar($value) && trim((string) $value) !== '' && trim((string) $label) !== '')
                    ->mapWithKeys(fn ($value, $label) => [mb_substr(trim((string) $label), 0, 255) => mb_substr(trim((string) $value), 0, 1000)])
                    ->all(),
                'findings' => $this->cleanTexts((array) ($row['findings'] ?? [])),
            ];
        }

        return $out;
    }

    // Bölümler raporda yazdığı gibi: ad, sonuç, bulgular, tüp sayısı.
    private function cleanSystems(array $systems): array
    {
        return collect($systems)
            ->filter(fn ($system) => is_array($system) && trim((string) ($system['name'] ?? '')) !== '')
            ->map(fn (array $system) => [
                'name' => mb_substr(trim((string) $system['name']), 0, 255),
                'status' => in_array($system['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $system['status'] : null,
                'findings' => $this->cleanTexts((array) ($system['findings'] ?? [])),
                'equipment_count' => max(0, (int) ($system['equipment_count'] ?? 0)),
            ])
            ->values()->all();
    }

    private function cleanTexts(array $texts): array
    {
        return collect($texts)->map(fn ($text) => trim((string) $text))->filter()->unique()->values()->all();
    }

    private function present(PkBulkReport $report): array
    {
        $systems = (array) ($report->systems ?? []);

        return [
            'id' => $report->id,
            'type' => $report->type?->name,
            'control_date' => $report->control_date?->format('Y-m-d'),
            'next_control_date' => $report->next_control_date?->format('Y-m-d'),
            'status' => $report->status,
            'source' => $report->source,
            'report_no' => $report->report_no,
            'report_file_name' => $report->report_file_name,
            'has_report' => (bool) $report->report_file,
            'inspection_body' => $report->inspection_body,
            'systems' => $systems,
            'findings' => (array) ($report->findings ?? []),
            // Bölüm bulguları + genel bulgular.
            'findings_count' => count((array) ($report->findings ?? [])) + collect($systems)->sum(fn ($system) => count((array) ($system['findings'] ?? []))),
            'equipment_count' => $report->inspections_count ?? 0,
            'created_by' => $report->creator?->name,
            'created_at' => $report->created_at,
        ];
    }

    private function analysisKey(string $id): string
    {
        return 'pk-bulk-analysis:' . $id;
    }

    // Lokasyon tenant'a ait olmalı; işyeri seçildiyse o lokasyona ait olmalı.
    private function assertPlacement(int $locationId, ?int $workplaceId): void
    {
        if (!Location::query()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Lokasyon bulunamadı.']);
        }
        if ($workplaceId !== null && !LocationBusinessEntity::query()->whereKey($workplaceId)->where('location_id', $locationId)->exists()) {
            throw ValidationException::withMessages(['location_business_entity_id' => 'İşyeri bu lokasyona ait değil.']);
        }
    }
}
