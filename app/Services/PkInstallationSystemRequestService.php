<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\PeriodicEquipmentSpecRequest;
use App\Models\PeriodicInstallationSystem;
use App\Models\PkInstallation;
use App\Models\PkInstallationReport;
use App\Models\PkInstallationReportSystem;
use App\Models\PkInstallationSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tesisat raporunda geçip katalogda olmayan sistem için kataloğa ekleme talebi (katalog talepleri tablosu, kind=system).
 * Rapordaki ad hiçbir sisteme kendiliğinden bağlanmaz: uzman kaydederken talep eder; yetkili yeni sistem olarak ekler ya
 * da katalogdaki bir sistemle aynı olduğunu seçer, veya reddeder. Onayda rapordaki durum ve bulgular o raporun sonucu
 * olarak tesisata yazılır (sistem tesisatta yoksa eklenir); retle hiçbir şey yazılmaz.
 */
class PkInstallationSystemRequestService
{
    public function __construct(private TenantContext $tenantContext, private PeriodicEquipmentSpecCatalog $catalog)
    {
    }

    // Rapor kaydında: işaretlenen katalog dışı sistemler için talep (aynı ad bir kez).
    public function createForReport(PkInstallationReport $report, int $installationTypeId, array $systems, User $user): void
    {
        $seen = [];
        foreach ($systems as $system) {
            $name = mb_substr(trim((string) ($system['name'] ?? '')), 0, 255);
            $key = mb_strtolower($name);
            if ($name === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            PeriodicEquipmentSpecRequest::create([
                'tenant_id' => $this->tenantContext->id(),
                'kind' => 'system',
                'equipment_type_id' => $installationTypeId,
                'pk_installation_report_id' => $report->id,
                'name' => $name,
                'raw_label' => $name,
                'payload' => [
                    'status' => in_array($system['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $system['status'] : null,
                    'findings' => collect((array) ($system['findings'] ?? []))->map(fn ($finding) => trim((string) $finding))->filter()->values()->all(),
                ],
                'status' => 'pending',
                'requested_by' => $user->id,
            ]);
        }
    }

    // Tesisatın onay bekleyen sistemleri (tesisat sayfasındaki not).
    public function pendingFor(PkInstallation $installation): array
    {
        return PeriodicEquipmentSpecRequest::query()
            ->where('kind', 'system')
            ->where('status', 'pending')
            ->where('tenant_id', $installation->tenant_id)
            ->whereIn('pk_installation_report_id', $installation->reports()->select('id'))
            ->with('installationReport:id,control_date')
            ->orderBy('id')
            ->get()
            ->map(fn (PeriodicEquipmentSpecRequest $request) => [
                'id' => $request->id,
                'name' => $request->name,
                'control_date' => $request->installationReport?->control_date?->format('Y-m-d'),
            ])
            ->all();
    }

    // Rapor ya da tesisat silinirken: bekleyen talepleri de silinir (karara bağlananlar kayıt olarak kalır).
    public function forgetPending(array $reportIds): void
    {
        if ($reportIds) {
            PeriodicEquipmentSpecRequest::query()->where('kind', 'system')->where('status', 'pending')->whereIn('pk_installation_report_id', $reportIds)->delete();
        }
    }

    // Yetkili tüm kiracıların taleplerini, uzman yalnızca kendi kiracısınınkileri görür. Bekleyenler önce.
    public function list(User $user): array
    {
        $canManage = $this->catalog->canEdit($user);
        $requests = PeriodicEquipmentSpecRequest::query()
            ->where('kind', 'system')
            ->with([
                'type:id,name',
                'installationSystem:id,name',
                'installationReport.installation' => fn ($query) => $query->withoutGlobalScopes()
                    ->with(['location' => fn ($inner) => $inner->withoutGlobalScopes()->select('id', 'name')]),
                'requester:id,name',
                'decider:id,name',
            ])
            ->when(!$canManage, fn ($query) => $query->where('tenant_id', $this->tenantContext->id()))
            ->orderByRaw("status = 'pending' desc")
            ->latest('id')
            ->limit(500)
            ->get();
        // Onayda "katalogdaki şu sistemle aynı" seçimi için türün sistemleri.
        $systems = $canManage
            ? PeriodicInstallationSystem::query()->where('is_active', true)->whereIn('installation_type_id', $requests->pluck('equipment_type_id')->unique())
                ->orderBy('sort_order')->get(['id', 'installation_type_id', 'name'])->groupBy('installation_type_id')
            : collect();

        return [
            'can_manage' => $canManage,
            'requests' => $requests->map(function (PeriodicEquipmentSpecRequest $request) use ($canManage, $systems) {
                $report = $request->installationReport;
                $installation = $report?->installation;

                return [
                    'id' => $request->id,
                    'status' => $request->status,
                    'type' => $request->type ? ['id' => $request->type->id, 'name' => $request->type->name] : null,
                    'name' => $request->name,
                    'result' => [
                        'status' => $request->payload['status'] ?? null,
                        'findings' => (array) ($request->payload['findings'] ?? []),
                    ],
                    'report' => $report ? [
                        'control_date' => $report->control_date?->format('Y-m-d'),
                        'report_no' => $report->report_no,
                        'installation' => $installation?->name ?: $request->type?->name,
                        'location' => $installation?->location?->name,
                    ] : null,
                    'own_tenant' => $request->tenant_id === $this->tenantContext->id(),
                    'requested_by' => $request->requester?->name,
                    'created_at' => $request->created_at,
                    'decided_by' => $request->decider?->name,
                    'decided_at' => $request->decided_at,
                    'decision_note' => $request->decision_note,
                    'system' => $request->installationSystem ? ['id' => $request->installationSystem->id, 'name' => $request->installationSystem->name] : null,
                    'systems' => $canManage && $request->status === 'pending'
                        ? $systems->get($request->equipment_type_id, collect())->map(fn ($system) => ['id' => $system->id, 'name' => $system->name])->values()
                        : [],
                ];
            })->values(),
        ];
    }

    /** Yetkili: yeni sistem olarak (ad düzeltilebilir) ya da katalogdaki bir sistemle aynı olarak onaylar. */
    public function approve(PeriodicEquipmentSpecRequest $request, array $data, User $user): void
    {
        $this->assertPending($request, $user);

        DB::transaction(function () use ($request, $data, $user) {
            if (!empty($data['system_id'])) {
                $system = PeriodicInstallationSystem::query()->where('installation_type_id', $request->equipment_type_id)->find($data['system_id']);
                if (!$system) {
                    throw ValidationException::withMessages(['system_id' => 'Sistem bu tesisat türünün kataloğunda yok.']);
                }
            } else {
                $system = $this->addSystem($request->equipment_type_id, trim((string) ($data['name'] ?? '')) ?: $request->name);
            }
            $this->applyResult($request, $system);
            $request->update([
                'status' => 'approved',
                'installation_system_id' => $system->id,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => $data['note'] ?? null,
            ]);
        });
    }

    public function reject(PeriodicEquipmentSpecRequest $request, ?string $note, User $user): void
    {
        $this->assertPending($request, $user);
        $request->update(['status' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note]);
    }

    // Kataloğa yeni sistem (türün sonuna). Aynı türde aynı adda etkin sistem varsa o kullanılır.
    private function addSystem(int $typeId, string $name): PeriodicInstallationSystem
    {
        $name = mb_substr($name, 0, 255);
        $existing = PeriodicInstallationSystem::query()->where('installation_type_id', $typeId)->where('is_active', true)->get()
            ->first(fn (PeriodicInstallationSystem $system) => mb_strtolower(trim($system->name)) === mb_strtolower($name));
        if ($existing) {
            return $existing;
        }
        $base = Str::slug($name) ?: 'sistem';
        $slug = $base;
        for ($suffix = 2; PeriodicInstallationSystem::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return PeriodicInstallationSystem::create([
            'installation_type_id' => $typeId,
            'slug' => $slug,
            'name' => $name,
            'sort_order' => (int) PeriodicInstallationSystem::query()->where('installation_type_id', $typeId)->max('sort_order') + 1,
            'is_active' => true,
        ]);
    }

    // Rapordaki durum ve bulgular o raporun sonucu olarak tesisata yazılır; rapor silinmişse yalnızca katalog güncellenir.
    private function applyResult(PeriodicEquipmentSpecRequest $request, PeriodicInstallationSystem $catalog): void
    {
        $report = $request->installationReport;
        $installation = $report ? PkInstallation::withoutGlobalScopes()->find($report->pk_installation_id) : null;
        if (!$installation) {
            return;
        }
        $system = PkInstallationSystem::withoutGlobalScopes()->firstOrCreate(
            ['pk_installation_id' => $installation->id, 'system_id' => $catalog->id],
            ['tenant_id' => $installation->tenant_id, 'name' => $catalog->name, 'sort_order' => $catalog->sort_order]
        );
        $status = $request->payload['status'] ?? null;
        $findings = (array) ($request->payload['findings'] ?? []);
        $result = PkInstallationReportSystem::query()->where('pk_installation_report_id', $report->id)->where('pk_installation_system_id', $system->id)->first();
        if ($result) {
            // Eşlenen sistem raporda zaten varsa birleşir: en kötü sonuç, bulgular eklenir.
            $result->update([
                'status' => in_array('uygun_degil', [$result->status, $status], true) ? 'uygun_degil' : ($result->status ?? $status),
                'findings' => array_values(array_unique([...(array) $result->findings, ...$findings])) ?: null,
                'source_names' => array_values(array_unique([...(array) $result->source_names, $request->name])),
            ]);
        } else {
            PkInstallationReportSystem::create([
                'pk_installation_report_id' => $report->id,
                'pk_installation_system_id' => $system->id,
                'status' => $status,
                'source_names' => [$request->name],
                'findings' => $findings ?: null,
            ]);
        }
        // Raporun bulgu listesi sistem bulgularını da içerir (kayıttaki gibi).
        $report->update(['findings' => array_values(array_unique([...(array) $report->findings, ...$findings])) ?: null]);
    }

    private function assertPending(PeriodicEquipmentSpecRequest $request, User $user): void
    {
        if (!$this->catalog->canEdit($user)) {
            abort(403, 'Katalog taleplerini yalnızca yetkili kullanıcı karara bağlayabilir.');
        }
        abort_if($request->kind !== 'system', 404);
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Bu talep zaten karara bağlanmış.']);
        }
    }
}
