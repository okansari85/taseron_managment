<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\PkArea;
use App\Models\PkEquipment;
use App\Models\PkOperationalUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operasyonel birimler (pktakip): işyerinin (firmanın lokasyondaki kaydı) içinde ayrı yönetilen birimler, ör. Arçelik Pazarlama
 * Beylikdüzü'nde "Beyaz Eşya Depo" ve "Müşteri Hizmetleri". Birim fiziksel değildir; kullandığı alanlar seçilir. Ekipman firmaya
 * (işyerine) aittir; birimi isteğe bağlıdır ve yalnızca kendi işyerinin birimi olabilir. Birimleri OSGB / kurumsal hesapta
 * yönetici, bireysel uzman hesabında uzman düzenler; ekipmana birim seçmek ekipmanı düzenleyen herkese açık. İsteğe bağlıdır.
 */
class PkOperationalUnitService
{
    public function __construct(private TenantContext $tenantContext, private PkAccountService $accounts)
    {
    }

    // Lokasyonun birimleri (işyerine göre), işyerleri (ad · firma) ve birimlerdeki ekipman sayısı.
    public function list(int $locationId): array
    {
        $location = Location::query()->findOrFail($locationId);
        $workplaces = $this->workplacesOf($location->id);
        $units = PkOperationalUnit::query()->whereIn('location_business_entity_id', array_keys($workplaces))->with('areas:id,name')->orderBy('name')->get();
        $equipment = PkEquipment::query()->whereIn('pk_operational_unit_id', $units->pluck('id'))->selectRaw('pk_operational_unit_id, count(*) as total')->groupBy('pk_operational_unit_id')->pluck('total', 'pk_operational_unit_id');

        return [
            'units' => $units->map(fn (PkOperationalUnit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'manager' => $unit->manager,
                'is_active' => $unit->is_active,
                'workplace_id' => $unit->location_business_entity_id,
                'workplace' => $workplaces[$unit->location_business_entity_id] ?? null,
                'areas' => $unit->areas->map(fn (PkArea $area) => ['id' => $area->id, 'name' => $area->name])->values()->all(),
                'equipment_count' => (int) ($equipment[$unit->id] ?? 0),
            ])->values()->all(),
            'workplaces' => collect($workplaces)->map(fn (string $name, int $id) => ['workplace_id' => $id, 'name' => $name])->values()->all(),
        ];
    }

    public function save(User $user, array $data, ?PkOperationalUnit $unit = null): PkOperationalUnit
    {
        $this->assertWriter($user);
        $workplaceId = $unit?->location_business_entity_id ?? (int) $data['location_business_entity_id'];
        $locationId = (int) DB::table('location_business_entities')->where('id', $workplaceId)->value('location_id');
        if (!$locationId || !Location::query()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_business_entity_id' => 'İşyeri bulunamadı.']);
        }
        $name = trim((string) $data['name']);
        $duplicate = PkOperationalUnit::query()->where('location_business_entity_id', $workplaceId)->when($unit, fn ($query) => $query->whereKeyNot($unit->id))->get(['name'])
            ->contains(fn ($row) => mb_strtolower($row->name, 'UTF-8') === mb_strtolower($name, 'UTF-8'));
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => "Bu işyerinde \"{$name}\" adlı birim zaten var."]);
        }
        $areaIds = array_values(array_unique(array_map('intval', $data['area_ids'] ?? [])));
        if ($areaIds && PkArea::query()->where('location_id', $locationId)->whereIn('id', $areaIds)->count() !== count($areaIds)) {
            throw ValidationException::withMessages(['area_ids' => 'Seçilen alanlardan biri bu lokasyonda değil.']);
        }

        return DB::transaction(function () use ($unit, $data, $name, $workplaceId, $areaIds) {
            $values = ['name' => $name, 'manager' => trim((string) ($data['manager'] ?? '')) ?: null, 'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($unit?->is_active ?? true)];
            if ($unit) {
                $unit->update($values);
            } else {
                $unit = PkOperationalUnit::query()->create($values + ['tenant_id' => $this->tenantContext->id(), 'location_business_entity_id' => $workplaceId]);
            }
            $unit->areas()->sync($areaIds);

            return $unit->refresh();
        });
    }

    // Ekipmanı olan birim silinmez (ekipmanların birimi sessizce boşalmasın).
    public function delete(User $user, PkOperationalUnit $unit): void
    {
        $this->assertWriter($user);
        $count = PkEquipment::query()->where('pk_operational_unit_id', $unit->id)->count();
        if ($count) {
            throw ValidationException::withMessages(['unit' => "Bu birimde {$count} ekipman var; önce ekipmanların birimini değiştirin."]);
        }
        $unit->delete();
    }

    // Lokasyondaki ekipmanların birimi: kimlik → birim.
    public function assignments(int $locationId): array
    {
        Location::query()->findOrFail($locationId);

        return ['equipment' => (object) PkEquipment::query()->where('location_id', $locationId)->whereNotNull('pk_operational_unit_id')->pluck('pk_operational_unit_id', 'id')->all()];
    }

    // Ekipmanın birimi: yalnızca ekipmanın kendi işyerinin (firmasının) birimi; ya da boş. Firma değişmez.
    public function assignEquipment(PkEquipment $equipment, ?int $unitId): PkEquipment
    {
        if ($unitId) {
            $unit = PkOperationalUnit::query()->find($unitId);
            if (!$unit || (int) $unit->location_business_entity_id !== (int) $equipment->location_business_entity_id) {
                throw ValidationException::withMessages(['pk_operational_unit_id' => 'Birim, ekipmanın firmasının (işyerinin) birimi değil.']);
            }
        }
        $equipment->forceFill(['pk_operational_unit_id' => $unitId ?: null])->save();

        return $equipment;
    }

    // Lokasyonun işyerleri: kimlik → "İşyeri adı · Firma" (adı yoksa firma adı).
    private function workplacesOf(int $locationId): array
    {
        return DB::table('location_business_entities as l')
            ->join('business_entities as b', 'b.id', '=', 'l.business_entity_id')
            ->leftJoin('companies as c', 'c.business_entity_id', '=', 'b.id')
            ->leftJoin('pk_workplace_names as n', 'n.location_business_entity_id', '=', 'l.id')
            ->where('l.location_id', $locationId)
            ->orderByRaw('coalesce(c.name, b.name)')
            ->get(['l.id', DB::raw('coalesce(c.name, b.name) as firm'), 'n.name as workplace_name'])
            ->mapWithKeys(fn ($row) => [(int) $row->id => $row->workplace_name ? "{$row->workplace_name} · {$row->firm}" : $row->firm])
            ->all();
    }

    private function assertWriter(User $user): void
    {
        $tenant = $this->accounts->tenantOf($user);
        abort_unless($tenant, 403, 'Hesap bulunamadı.');
        abort_if(in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true) && $this->accounts->roleOf($tenant, $user) !== 'yonetici', 403, 'Birimleri yönetici düzenler.');
    }
}
