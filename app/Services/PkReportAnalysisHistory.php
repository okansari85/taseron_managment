<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\PkInspection;
use App\Models\PkInstallationReport;
use App\Models\PkReportAnalysis;
use App\Models\User;
use App\Services\Ai\PkTakip\PkAiProvider;
use App\Services\Ai\PkTakip\PkAiUsage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * pktakip analiz geçmişi (Ayarlar → Analiz geçmişi): yapay zeka ile yapılan her rapor okuması bir satır. Okuma anında
 * yazılır (kaydedilmese de kalır), kayıtta kaydedildiği ekipman / tesisat bağlanır, okunamazsa hatasıyla yazılır.
 * Hesaptaki (tenant) tüm uzmanların okumaları görünür; ileride kontür bu satırlardan düşülür. Satır silinmez.
 * Test verisiyle (fikstür) yapılan okumalar ve elle giriş yazılmaz: yapay zeka çağrılmaz.
 */
class PkReportAnalysisHistory
{
    public const DIR = 'pk-report-analyses';

    // Listede gelen sütunlar (analiz ve tablolar yalnızca detayda).
    private const LIST_COLUMNS = [
        'id', 'uuid', 'tenant_id', 'user_id', 'location_id', 'kind', 'status', 'pk_equipment_id', 'pk_inspection_id', 'pk_installation_id',
        'pk_installation_report_id', 'pk_bulk_report_id', 'file_name', 'file_path', 'input', 'provider', 'model', 'duration_s', 'input_tokens', 'output_tokens',
        'detected_type', 'report_no', 'control_date', 'overall_status', 'summary', 'error', 'saved_at', 'created_at',
    ];

    public function __construct(private TenantContext $tenantContext)
    {
    }

    /**
     * Başarılı okuma: okunan PDF saklanır (kaydedilmese de satırdan açılır). $result okuyucunun çıktısı (semantic,
     * varsa tables, report_no, control_date, duration_s, input).
     */
    public function recordRead(string $uuid, string $kind, array $result, ?UploadedFile $file, ?string $fileHash, User $user, ?int $locationId, ?string $detectedType, ?int $equipmentId = null): void
    {
        $semantic = (array) ($result['semantic'] ?? []);
        $tables = isset($result['tables']) && is_array($result['tables']) ? $result['tables'] : null;
        $path = $file ? $file->storeAs(self::DIR . '/' . $this->tenantContext->id(), $uuid . '.' . (strtolower($file->getClientOriginalExtension()) ?: 'pdf'), 'local') : null;

        PkReportAnalysis::create($this->base($kind, $user, $locationId, $file, $equipmentId) + PkAiUsage::take() + [
            'uuid' => $uuid,
            'status' => 'read',
            'file_path' => $path ?: null,
            'file_hash' => $fileHash,
            'input' => $result['input'] ?? null,
            'duration_s' => $result['duration_s'] ?? null,
            'detected_type' => $detectedType,
            'report_no' => mb_substr(trim((string) ($result['report_no'] ?? '')), 0, 100) ?: null,
            'control_date' => $result['control_date'] ?? null,
            'overall_status' => $semantic['extracted_data']['overall_result']['status'] ?? null,
            'summary' => $this->summaryOf($semantic, $tables),
            'semantic' => $semantic,
            'tables' => $tables,
        ]);
    }

    // Okunamadı (yapay zeka hatası, okunamayan PDF): kontür düşmez; kullanıcı neden okunamadığını görür.
    public function recordFailure(string $kind, ?UploadedFile $file, string $message, User $user, ?int $locationId, ?string $detectedType = null, ?int $equipmentId = null): void
    {
        PkReportAnalysis::create($this->base($kind, $user, $locationId, $file, $equipmentId) + PkAiUsage::take() + [
            'uuid' => (string) Str::uuid(),
            'status' => 'failed',
            'detected_type' => $detectedType,
            'error' => mb_substr($message, 0, 2000),
        ]);
    }

    /**
     * Okuma kayda dönüştü: kaydedildiği ekipman / tesisat bağlanır. Saklanan PDF kopyası kayıttaki dosyanın
     * bağlantısıyla değişir (diskte ikinci kez yer kaplamaz; kayıt silinse de geçmişteki PDF açılır).
     * Test verisiyle yapılan okumanın satırı yoktur: bir şey yapılmaz.
     */
    public function markSaved(?string $uuid, array $links, ?string $savedFile): void
    {
        $analysis = $uuid ? PkReportAnalysis::query()->where('uuid', $uuid)->first() : null;
        if (!$analysis) {
            return;
        }
        $analysis->update($links + ['status' => 'saved', 'saved_at' => now()]);
        if ($savedFile) {
            $this->shareFile($analysis, $savedFile);
        }
    }

    /** Liste (en yeni önce, sayfalı) + filtre seçenekleri (geçmişte geçen lokasyonlar ve uzmanlar). */
    public function list(array $filters): array
    {
        $page = PkReportAnalysis::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user:id,name', 'location:id,name', 'equipment:id,equipment_type_id,code,name', 'equipment.type:id,name', 'installation:id,installation_type_id,name', 'installation.type:id,name', 'bulkReport:id,report_no,control_date'])
            ->when($filters['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($filters['user_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->where('created_at', '>=', $date . ' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->where('created_at', '<=', $date . ' 23:59:59'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(50);

        return [
            'data' => collect($page->items())->map(fn (PkReportAnalysis $analysis) => $this->present($analysis))->values(),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'locations' => Location::query()->whereIn('id', PkReportAnalysis::query()->whereNotNull('location_id')->distinct()->pluck('location_id'))->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->whereIn('id', PkReportAnalysis::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function show(string $uuid): array
    {
        $analysis = $this->find($uuid)->load(['user:id,name', 'location:id,name', 'equipment.type:id,name', 'installation.type:id,name', 'bulkReport:id,report_no,control_date']);

        return $this->present($analysis) + [
            'semantic' => $analysis->semantic ?? [],
            'tables' => $analysis->tables,
        ];
    }

    // Okunan PDF: [mutlak yol, dosya adı].
    public function file(string $uuid): array
    {
        $analysis = $this->find($uuid);
        if (!$analysis->file_path || !Storage::disk('local')->exists($analysis->file_path)) {
            abort(404, 'Bu okumanın PDF\'i yok.');
        }

        return [Storage::disk('local')->path($analysis->file_path), $analysis->file_name ?: basename($analysis->file_path)];
    }

    /**
     * Bu özellikten önceki okumalar: yalnızca kaydedilmiş olanlar (analizi ekipman kontrol kaydında ya da tesisat raporunda
     * saklananlar). Test verisiyle yapılanlar atlanır; token sayısı bilinmez. Tekrar çalıştırılırsa aynı kayıt eklenmez.
     * Tüm kiracılar için (komut satırından; tenant context yok).
     */
    public function backfill(): array
    {
        $added = ['equipment' => 0, 'installation' => 0];
        $installationInspections = DB::table('pk_installation_report_inspections')->pluck('pk_inspection_id')->all();

        PkInspection::query()->withoutGlobalScopes()->whereNotNull('analysis')->whereNotIn('id', $installationInspections)
            ->with(['equipment' => fn ($query) => $query->withoutGlobalScopes()->with('type:id,name')])
            ->orderBy('id')
            ->each(function (PkInspection $inspection) use (&$added) {
                $analysis = (array) $inspection->analysis;
                if (!empty($analysis['fixture']) || PkReportAnalysis::query()->withoutGlobalScopes()->where('pk_inspection_id', $inspection->id)->exists()) {
                    return;
                }
                $equipment = $inspection->equipment;
                $semantic = (array) ($analysis['semantic'] ?? []);
                $this->createBackfilled($inspection->tenant_id, $inspection->report_file, $inspection->report_file_name, $analysis, $inspection->created_at, [
                    'user_id' => $inspection->created_by,
                    'location_id' => $equipment?->location_id,
                    'kind' => 'equipment',
                    'pk_equipment_id' => $equipment?->id,
                    'pk_inspection_id' => $inspection->id,
                    'file_hash' => $inspection->report_hash,
                    'detected_type' => $equipment?->type?->name,
                    'report_no' => $inspection->report_no,
                    'control_date' => $inspection->control_date?->format('Y-m-d'),
                    'overall_status' => $semantic['extracted_data']['overall_result']['status'] ?? null,
                    'summary' => $this->summaryOf($semantic, null),
                    'semantic' => $semantic,
                    'tables' => null,
                ]);
                $added['equipment']++;
            });

        PkInstallationReport::query()->withoutGlobalScopes()->whereNotNull('analysis')
            ->with(['installation' => fn ($query) => $query->withoutGlobalScopes()->with('type:id,name')])
            ->orderBy('id')
            ->each(function (PkInstallationReport $report) use (&$added) {
                $analysis = (array) $report->analysis;
                if (!empty($analysis['fixture']) || PkReportAnalysis::query()->withoutGlobalScopes()->where('pk_installation_report_id', $report->id)->exists()) {
                    return;
                }
                $semantic = (array) ($analysis['semantic'] ?? []);
                $tables = is_array($analysis['tables'] ?? null) ? $analysis['tables'] : null;
                $this->createBackfilled($report->tenant_id, $report->report_file, $report->report_file_name, $analysis, $report->created_at, [
                    'user_id' => $report->created_by,
                    'location_id' => $report->installation?->location_id,
                    'kind' => 'installation',
                    'pk_installation_id' => $report->pk_installation_id,
                    'pk_installation_report_id' => $report->id,
                    'file_hash' => $report->report_hash,
                    'detected_type' => $report->installation?->type?->name,
                    'report_no' => $report->report_no,
                    'control_date' => $report->control_date?->format('Y-m-d'),
                    'overall_status' => $semantic['extracted_data']['overall_result']['status'] ?? null,
                    'summary' => $this->summaryOf($semantic, $tables),
                    'semantic' => $semantic,
                    'tables' => $tables,
                ]);
                $added['installation']++;
            });

        return $added;
    }

    private function createBackfilled(int $tenantId, ?string $reportFile, ?string $fileName, array $analysis, $savedAt, array $data): void
    {
        $uuid = (string) Str::uuid();
        $path = null;
        $disk = Storage::disk('local');
        if ($reportFile && $disk->exists($reportFile)) {
            $path = self::DIR . "/{$tenantId}/{$uuid}." . (pathinfo($reportFile, PATHINFO_EXTENSION) ?: 'pdf');
            $disk->makeDirectory(dirname($path));
            if (!@link($disk->path($reportFile), $disk->path($path))) {
                $disk->copy($reportFile, $path);
            }
        }
        $readAt = !empty($analysis['analyzed_at']) ? \Carbon\CarbonImmutable::parse($analysis['analyzed_at']) : $savedAt;

        $row = new PkReportAnalysis($data + [
            'uuid' => $uuid,
            'tenant_id' => $tenantId,
            'status' => 'saved',
            'file_name' => $fileName,
            'file_path' => $path,
            'input' => $analysis['input'] ?? null,
            'provider' => $analysis['provider'] ?? null,
            'model' => $analysis['model'] ?? null,
            'duration_s' => $analysis['duration_s'] ?? null,
            'saved_at' => $savedAt,
        ]);
        $row->created_at = $readAt;
        $row->updated_at = $savedAt;
        $row->save();
    }

    private function base(string $kind, User $user, ?int $locationId, ?UploadedFile $file, ?int $equipmentId): array
    {
        return [
            'tenant_id' => $this->tenantContext->id(),
            'user_id' => $user->id,
            'location_id' => $locationId,
            'kind' => $kind,
            'pk_equipment_id' => $equipmentId,
            'file_name' => $file?->getClientOriginalName(),
            'provider' => PkAiProvider::name(),
            'model' => PkAiProvider::model(),
        ];
    }

    // Test sayfasındaki "Kayıtlı analizler" ile aynı özet.
    private function summaryOf(array $semantic, ?array $tables): array
    {
        return [
            'system_count' => count((array) ($semantic['template']['fire_systems']['systems'] ?? [])),
            'finding_count' => count((array) ($tables['findings'] ?? $semantic['extracted_data']['findings'] ?? [])),
            'equipment_summary' => $tables['equipment_summary'] ?? null,
        ];
    }

    private function shareFile(PkReportAnalysis $analysis, string $savedFile): void
    {
        $disk = Storage::disk('local');
        if (!$analysis->file_path || !$disk->exists($analysis->file_path) || !$disk->exists($savedFile)) {
            return;
        }
        $target = $disk->path($analysis->file_path);
        $temporary = $target . '.link';
        if (@link($disk->path($savedFile), $temporary) && !@rename($temporary, $target)) {
            @unlink($temporary);
        }
    }

    private function find(string $uuid): PkReportAnalysis
    {
        abort_unless(Str::isUuid($uuid), 404);

        return PkReportAnalysis::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function present(PkReportAnalysis $analysis): array
    {
        $equipment = $analysis->equipment;
        $installation = $analysis->installation;
        $bulk = $analysis->bulkReport;
        $target = match (true) {
            $analysis->status !== 'saved' => null,
            $analysis->kind === 'bulk' => $bulk ? [
                'type' => 'bulk', 'id' => $bulk->id, 'label' => 'Tüp kontrol formu' . ($bulk->report_no ? " · {$bulk->report_no}" : ''),
            ] : null,
            $analysis->kind === 'installation' && $installation !== null => [
                'type' => 'installation', 'id' => $installation->id, 'label' => $installation->name ?: $installation->type?->name,
            ],
            $analysis->kind !== 'installation' && $equipment !== null => [
                'type' => 'equipment', 'id' => $equipment->id,
                'label' => ($equipment->name ?: $equipment->type?->name) . ($equipment->code ? " · no {$equipment->code}" : ''),
            ],
            default => null,
        };

        return [
            'id' => $analysis->uuid,
            'kind' => $analysis->kind,
            'status' => $analysis->status,
            'file_name' => $analysis->file_name,
            'has_file' => (bool) $analysis->file_path,
            'input' => $analysis->input,
            'provider' => $analysis->provider,
            'model' => $analysis->model,
            'duration_s' => $analysis->duration_s,
            'input_tokens' => $analysis->input_tokens,
            'output_tokens' => $analysis->output_tokens,
            'detected_type' => $analysis->detected_type,
            'report_no' => $analysis->report_no,
            'control_date' => $analysis->control_date?->format('Y-m-d'),
            'overall_status' => $analysis->overall_status,
            'summary' => $analysis->summary ?? [],
            'error' => $analysis->error,
            'user' => $analysis->user?->name,
            'location' => $analysis->location ? ['id' => $analysis->location->id, 'name' => $analysis->location->name] : null,
            // Kaydedildiği yer; kayıt sonradan silindiyse boş (target_missing).
            'target' => $target,
            'target_missing' => $analysis->status === 'saved' && $target === null,
            'created_at' => $analysis->created_at?->toIso8601String(),
            'saved_at' => $analysis->saved_at?->toIso8601String(),
        ];
    }
}
