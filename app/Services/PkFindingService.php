<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\PkFinding;
use App\Models\PkFindingEvent;
use App\Models\PkFindingSource;
use App\Models\PkInspection;
use App\Models\PkInstallationReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Bulgu Takip ekranı (2026-11-12): liste + sayılar, detay ve geçmiş, sorumlu / termin / yapılacak iş / durum, not, kanıt dosyası,
 * kapatma ve kapatma önerisi. Bulguları raporlardan PkFindingSyncService üretir. Sorumlu yalnız hesabın aktif kullanıcısı.
 */
class PkFindingService
{
    public const UPCOMING_DAYS = 10;

    public function __construct(private TenantContext $tenantContext)
    {
    }

    public function list(array $filters): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $soon = CarbonImmutable::today()->addDays(self::UPCOMING_DAYS)->toDateString();
        $scoped = fn () => $this->scoped($filters);

        $open = fn ($q) => $q->where('status', '!=', 'kapandi');
        $counts = [
            'acik' => $scoped()->whereIn('status', ['acik', 'devam'])->count(),
            'tekrar_kontrol' => $scoped()->where('status', 'tekrar_kontrol')->count(),
            'kapandi' => $scoped()->where('status', 'kapandi')->count(),
            'suresi_gecen' => $scoped()->tap($open)->where('due_date', '<', $today)->count(),
            'yaklasan' => $scoped()->tap($open)->whereBetween('due_date', [$today, $soon])->count(),
        ];

        $page = $scoped()
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'suresi_gecen' => $q->tap($open)->where('due_date', '<', $today),
                'yaklasan' => $q->tap($open)->whereBetween('due_date', [$today, $soon]),
                default => $q->where('status', $status),
            })
            ->when($filters['responsible_user_id'] ?? null, fn ($q, $id) => $q->where('responsible_user_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('last_seen_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('last_seen_date', '<=', $date))
            ->with($this->relations())
            // Kapananlar sonda; açıklarda önce termini geçmiş / yakın olan, termini olmayanlar en yeni rapor önce.
            ->orderByRaw("status = 'kapandi'")->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('last_seen_date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 10), 100), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'counts' => $counts,
            'data' => collect($page->items())->map(fn (PkFinding $finding) => $this->present($finding))->values(),
            'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
        ];
    }

    // Menüdeki rozet: kullanıcının lokasyonlarındaki kapanmamış bulgular.
    public function openCount(array $filters): int
    {
        return $this->scoped($filters)->where('status', '!=', 'kapandi')->count();
    }

    // Üstteki lokasyon / firma seçimi: location_ids (Genel Bakış'taki gibi seçimdeki lokasyonlar) ya da location_id; işyeri
    // seçiliyse lokasyon geneli (işyerine bağlı olmayan) bulgular da gelir.
    private function scoped(array $filters)
    {
        return PkFinding::query()
            ->when(array_key_exists('location_ids', $filters), fn ($q) => $q->whereIn('location_id', (array) $filters['location_ids']))
            ->when($filters['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['workplace_id'] ?? null, fn ($q, $id) => $q->where(fn ($inner) => $inner->whereNull('location_business_entity_id')->orWhere('location_business_entity_id', $id)));
    }

    public function show(PkFinding $finding): array
    {
        $finding->load($this->relations());
        $sources = PkFindingSource::query()->where('pk_finding_id', $finding->id)->orderByDesc('control_date')->orderByDesc('id')->get();
        $events = PkFindingEvent::query()->with('user:id,name')->where('pk_finding_id', $finding->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        return $this->present($finding) + [
            'sources' => $sources->map(fn (PkFindingSource $source) => $this->presentSource($source))->filter()->values(),
            'events' => $events->map(fn (PkFindingEvent $event) => [
                'id' => $event->id, 'type' => $event->type, 'note' => $event->note, 'file_name' => $event->file_name,
                'meta' => $event->meta, 'user' => $event->user?->name, 'created_at' => $event->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    public function update(PkFinding $finding, array $data, User $user): PkFinding
    {
        if (array_key_exists('responsible_user_id', $data) && $data['responsible_user_id'] && !$this->responsibles()->contains('id', (int) $data['responsible_user_id'])) {
            throw ValidationException::withMessages(['responsible_user_id' => 'Sorumlu bu hesabın aktif kullanıcılarından seçilmelidir.']);
        }
        if (($data['status'] ?? null) === 'kapandi') {
            throw ValidationException::withMessages(['status' => 'Bulgu "Kapat" ile kapatılır.']);
        }
        $fields = array_intersect_key($data, array_flip(['work', 'responsible_user_id', 'due_date', 'recheck_date', 'status']));
        $changes = [];
        foreach ($fields as $field => $value) {
            $old = $finding->{$field} instanceof \DateTimeInterface ? $finding->{$field}->format('Y-m-d') : $finding->{$field};
            if ((string) $old !== (string) $value) {
                $changes[$field] = ['old' => $old, 'new' => $value];
            }
        }
        if ($changes === []) {
            return $finding;
        }
        DB::transaction(function () use ($finding, $fields, $changes, $user) {
            $finding->update($fields);
            if (isset($changes['responsible_user_id'])) {
                $names = User::query()->whereKey(array_filter([$changes['responsible_user_id']['old'], $changes['responsible_user_id']['new']]))->pluck('name', 'id');
                $changes['responsible_user_id'] += ['old_name' => $names[$changes['responsible_user_id']['old']] ?? null, 'new_name' => $names[$changes['responsible_user_id']['new']] ?? null];
            }
            $this->event($finding, 'guncellendi', $user, meta: $changes);
        });

        return $finding;
    }

    public function addNote(PkFinding $finding, string $note, User $user): void
    {
        $this->event($finding, 'not', $user, trim($note));
    }

    public function addFile(PkFinding $finding, UploadedFile $file, ?string $note, User $user): void
    {
        $path = $file->store('pk-findings/' . $finding->tenant_id, 'local');
        try {
            $this->event($finding, 'dosya', $user, trim((string) $note) ?: null, ['file_path' => $path, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 255)]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    /** @return array{0: string, 1: string} [tam yol, dosya adı] */
    public function file(PkFinding $finding, PkFindingEvent $event): array
    {
        abort_unless($event->pk_finding_id === $finding->id && $event->file_path && Storage::disk('local')->exists($event->file_path), 404);

        return [Storage::disk('local')->path($event->file_path), $event->file_name ?: basename($event->file_path)];
    }

    public function close(PkFinding $finding, ?string $note, User $user): void
    {
        DB::transaction(function () use ($finding, $note, $user) {
            $fromSuggestion = $finding->close_suggested_by;
            $finding->update(['status' => 'kapandi', 'closed_at' => now(), 'closed_by' => $user->id, 'close_suggested_by' => null, 'close_suggested_date' => null]);
            $this->event($finding, 'kapandi', $user, trim((string) $note) ?: null, meta: $fromSuggestion ? ['suggestion' => $fromSuggestion] : null);
        });
    }

    public function reopen(PkFinding $finding, User $user): void
    {
        abort_unless($finding->status === 'kapandi', 422, 'Bulgu kapalı değil.');
        DB::transaction(function () use ($finding, $user) {
            $finding->update(['status' => 'acik', 'closed_at' => null, 'closed_by' => null]);
            $this->event($finding, 'yeniden_acildi', $user);
        });
    }

    public function rejectSuggestion(PkFinding $finding, User $user): void
    {
        abort_unless((bool) $finding->close_suggested_by, 422, 'Bu bulgu için kapatma önerisi yok.');
        DB::transaction(function () use ($finding, $user) {
            $finding->update(['close_suggested_by' => null, 'close_suggested_date' => null]);
            $this->event($finding, 'oneri_reddedildi', $user);
        });
    }

    // Sorumlu seçilebilecek kişiler: hesabın aktif kullanıcıları (PkAccountUserService::members ile aynı ölçüt, pasifler hariç).
    public function responsibles(): Collection
    {
        $tenantId = $this->tenantContext->id();
        $passive = DB::table('pk_account_users')->where('tenant_id', $tenantId)->whereNotNull('deactivated_at')->pluck('user_id');

        return User::query()->where('is_expert', true)
            ->whereHas('scopes', fn ($query) => $query->where('scope_type', 'tenant')->where('scope_id', $tenantId))
            ->whereNotIn('id', $passive)->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->values();
    }

    // Kontrol Takvimi: kapanmamış bulguların tekrar kontrol tarihleri.
    public function rechecks(array $filters): Collection
    {
        return $this->scoped($filters)->whereNotNull('recheck_date')->where('status', '!=', 'kapandi')
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('recheck_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('recheck_date', '<=', $date))
            ->with($this->relations())->orderBy('recheck_date')->get()
            ->map(fn (PkFinding $finding) => $this->present($finding))->values();
    }

    // --- Sunum ---

    private function relations(): array
    {
        return ['equipment.type:id,name', 'installation.type:id,name', 'installationSystem.catalog:id,name', 'location:id,name',
            'workplace.businessEntity.company', 'responsible:id,name'];
    }

    public function present(PkFinding $finding): array
    {
        $today = CarbonImmutable::today();
        $business = $finding->workplace?->businessEntity;
        $equipment = $finding->equipment;
        $installation = $finding->installation;
        $subject = $equipment
            ? ['kind' => 'equipment', 'id' => $equipment->id, 'name' => $equipment->name ?: $equipment->type?->name, 'code' => $equipment->code, 'detail' => null]
            : ['kind' => 'installation', 'id' => $installation?->id, 'name' => $installation?->name ?: $installation?->type?->name,
                'code' => null, 'detail' => $finding->installationSystem ? ($finding->installationSystem->name ?: $finding->installationSystem->catalog?->name) : null];

        return [
            'id' => $finding->id,
            'text' => $finding->text,
            'component' => $finding->component,
            'work' => $finding->work,
            'status' => $finding->status,
            'due_date' => $finding->due_date?->format('Y-m-d'),
            'days_left' => $finding->due_date ? (int) $today->diffInDays(CarbonImmutable::parse($finding->due_date), false) : null,
            'recheck_date' => $finding->recheck_date?->format('Y-m-d'),
            'last_seen_date' => $finding->last_seen_date?->format('Y-m-d'),
            'closed_at' => $finding->closed_at?->toIso8601String(),
            'close_suggestion' => $finding->close_suggested_by ? ['date' => $finding->close_suggested_date?->format('Y-m-d')] : null,
            'subject' => $subject,
            'location' => $finding->location ? ['id' => $finding->location->id, 'name' => $finding->location->name] : null,
            'workplace' => $finding->workplace ? ['id' => $finding->workplace->id, 'company_name' => $business?->company?->name ?? $business?->name] : null,
            'responsible' => $finding->responsible ? ['id' => $finding->responsible->id, 'name' => $finding->responsible->name] : null,
        ];
    }

    // Bulgunun görüldüğü rapor ve dosya bağlantısı (rapor silindiyse görünmez).
    private function presentSource(PkFindingSource $source): ?array
    {
        if ($source->source_type === 'inspection') {
            $inspection = PkInspection::query()->find($source->source_id);

            return $inspection ? [
                'type' => 'inspection', 'id' => $inspection->id, 'control_date' => $inspection->control_date?->format('Y-m-d'), 'status' => $inspection->status,
                'report_no' => $inspection->report_no, 'file_url' => $inspection->report_file ? "pk-equipment/{$inspection->pk_equipment_id}/inspections/{$inspection->id}/report" : null,
            ] : null;
        }
        $report = PkInstallationReport::query()->find($source->source_id);

        return $report ? [
            'type' => 'installation_report', 'id' => $report->id, 'control_date' => $report->control_date?->format('Y-m-d'), 'status' => $report->status,
            'report_no' => $report->report_no, 'file_url' => $report->report_file ? "pk-installations/{$report->pk_installation_id}/reports/{$report->id}/file" : null,
        ] : null;
    }

    private function event(PkFinding $finding, string $type, ?User $user, ?string $note = null, array $extra = [], ?array $meta = null): PkFindingEvent
    {
        return PkFindingEvent::query()->create(['pk_finding_id' => $finding->id, 'type' => $type, 'note' => $note, 'meta' => $meta, 'user_id' => $user?->id] + $extra);
    }
}
