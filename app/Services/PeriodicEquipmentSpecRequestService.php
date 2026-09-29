<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\PeriodicEquipmentSpecRequest;
use App\Models\PkEquipment;
use App\Models\PkEquipmentPropertyValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Katalog dışı teknik özellik talepleri: uzman talep eder, yetkili onaylar (kataloğa yeni özellik ya da mevcut
 * özelliğe diğer ad olarak) ya da reddeder. Onayda talep edilen değer ekipmana yazılır; ekipmanda o özellik için
 * zaten değer varsa ezilmez, geçmişe eklenir.
 */
class PeriodicEquipmentSpecRequestService
{
    public function __construct(private TenantContext $tenantContext, private PeriodicEquipmentSpecCatalog $catalog)
    {
    }

    // Yetkili tüm kiracıların taleplerini, uzman yalnızca kendi kiracısınınkileri görür. Bekleyenler önce.
    public function list(User $user): array
    {
        $canManage = $this->catalog->canEdit($user);
        // Tesisat sistemi talepleri (kind=system) ayrı: PkInstallationSystemRequestService.
        $requests = PeriodicEquipmentSpecRequest::query()
            ->where('kind', 'spec')
            ->with(['type:id,name', 'spec:id,name,unit', 'requester:id,name', 'decider:id,name'])
            ->when(!$canManage, fn ($query) => $query->where('tenant_id', $this->tenantContext->id()))
            ->orderByRaw("status = 'pending' desc")
            ->latest('id')
            ->limit(500)
            ->get();
        $equipment = PkEquipment::withoutGlobalScopes()->whereIn('id', $requests->pluck('pk_equipment_id')->filter())->get(['id', 'name', 'code'])->keyBy('id');

        return [
            'can_manage' => $canManage,
            'requests' => $requests->map(fn (PeriodicEquipmentSpecRequest $request) => [
                'id' => $request->id,
                'status' => $request->status,
                'type' => $request->type ? ['id' => $request->type->id, 'name' => $request->type->name] : null,
                'name' => $request->name,
                'unit' => $request->unit,
                'raw_label' => $request->raw_label,
                'raw_value' => $request->raw_value,
                'equipment' => ($item = $equipment[$request->pk_equipment_id] ?? null) ? ['id' => $item->id, 'name' => $item->name, 'code' => $item->code] : null,
                'own_tenant' => $request->tenant_id === $this->tenantContext->id(),
                'requested_by' => $request->requester?->name,
                'created_at' => $request->created_at,
                'decided_by' => $request->decider?->name,
                'decided_at' => $request->decided_at,
                'decision_note' => $request->decision_note,
                'spec' => $request->spec ? ['name' => $request->spec->name, 'unit' => $request->spec->unit] : null,
                // Onayda "şu özellikle aynı" seçimi için türün kataloğu.
                'specs' => $canManage && $request->status === 'pending'
                    ? $this->catalog->specsFor($request->type)->map(fn ($spec) => ['key' => $spec->key, 'name' => $spec->name, 'unit' => $spec->unit])->values()
                    : [],
            ])->values(),
        ];
    }

    /** Yetkili: yeni özellik olarak (ad/birim düzeltilebilir) ya da mevcut bir özelliğin diğer adı olarak onaylar. */
    public function approve(PeriodicEquipmentSpecRequest $request, array $data, User $user): void
    {
        $this->assertPending($request, $user);

        DB::transaction(function () use ($request, $data, $user) {
            $type = $request->type;
            if (filled($data['map_key'] ?? null)) {
                $spec = $this->catalog->specsFor($type)->firstWhere('key', $data['map_key']);
                if (!$spec) {
                    throw ValidationException::withMessages(['map_key' => 'Özellik katalogda yok.']);
                }
                $this->catalog->addAlias($spec, (string) $request->raw_label, $user);
            } else {
                $spec = $this->catalog->addSpec($type, (string) ($data['name'] ?? $request->name), $data['unit'] ?? $request->unit, $request->raw_label, $user);
            }

            // Bekleyen değer: ekipmanda bu özellik için gerçek bir değer yoksa geçerli olur, varsa yalnızca geçmişe girer.
            foreach (PkEquipmentPropertyValue::withoutGlobalScopes()->where('spec_request_id', $request->id)->get() as $row) {
                $hasValue = PkEquipmentPropertyValue::withoutGlobalScopes()
                    ->where('pk_equipment_id', $row->pk_equipment_id)
                    ->where('field_key', $spec->key)
                    ->where('accepted', true)
                    ->where('is_empty', false)
                    ->where('is_removed', false)
                    ->exists();
                $row->update([
                    'field_key' => $spec->key,
                    'field' => $spec->name,
                    'value' => $this->catalog->plainValue((string) $row->value, $spec->unit),
                    'accepted' => !$hasValue,
                ]);
            }

            $request->update(['status' => 'approved', 'spec_id' => $spec->id, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null]);
        });
    }

    public function reject(PeriodicEquipmentSpecRequest $request, ?string $note, User $user): void
    {
        $this->assertPending($request, $user);
        $request->update(['status' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note]);
    }

    private function assertPending(PeriodicEquipmentSpecRequest $request, User $user): void
    {
        if (!$this->catalog->canEdit($user)) {
            abort(403, 'Katalog taleplerini yalnızca yetkili kullanıcı karara bağlayabilir.');
        }
        abort_if(($request->kind ?? 'spec') !== 'spec', 404);
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Bu talep zaten karara bağlanmış.']);
        }
    }
}
