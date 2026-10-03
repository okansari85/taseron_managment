<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\PkArea;
use App\Models\PkAreaType;
use App\Models\PkEquipment;
use App\Models\PkInstallation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * pktakip alanları: lokasyonun içindeki operasyonel alanlar (hizmet veren firmayla) ve ortak alanlar (firmasız); alt alan tek kat.
 * İsteğe bağlıdır: alanı olmayan lokasyonda hiçbir şey değişmez. Alanları OSGB / kurumsal hesapta yönetici, bireysel uzman
 * hesabında uzman düzenler (firma tanımları gibi); ekipmana / tesisata alan seçmek ekipmanı düzenleyen herkese açık.
 * Alanın firması: kendi firması, yoksa üst alanınki (Bilişim Depo'daki tuvalet Bilişim Depo'nun firmasında). Ekipmanın alanı ile
 * firması bağımsızdır: alan yer, firma sahip / kullanan (formlar alanın firmasını yalnızca öneri olarak doldurur).
 */
class PkAreaService
{
    public function __construct(private TenantContext $tenantContext, private PkAccountService $accounts)
    {
    }

    // Hazır türler ve hesabın eklediği türler: önce operasyonel, sonra ortak; sırayla.
    public function types(): array
    {
        return PkAreaType::query()
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $this->tenantContext->id()))
            ->orderByRaw("kind = 'common'")->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'kind'])
            ->map(fn (PkAreaType $type) => ['id' => $type->id, 'name' => $type->name, 'kind' => $type->kind, 'custom' => $type->tenant_id !== null])
            ->values()->all();
    }

    // Yeni tür: aynı adda (yazımdan bağımsız) tür varsa o döner.
    public function addType(User $user, string $name, string $kind): array
    {
        $this->assertWriter($user);
        $name = trim($name);
        $existing = collect($this->types())->first(fn (array $type) => $this->key($type['name']) === $this->key($name));
        if ($existing) {
            return $existing;
        }
        $type = PkAreaType::query()->create(['tenant_id' => $this->tenantContext->id(), 'name' => $name, 'kind' => $kind, 'sort_order' => 1000]);

        return ['id' => $type->id, 'name' => $type->name, 'kind' => $type->kind, 'custom' => true];
    }

    // Lokasyonun alanları (ağaç, tek kat), lokasyondaki firmalar (operasyonel alan için seçenekler) ve alanlardaki kayıt sayıları.
    public function list(int $locationId): array
    {
        $location = Location::query()->findOrFail($locationId);
        $firms = $this->firmsOf($location->id);
        $areas = PkArea::query()->where('location_id', $location->id)->with('type')->orderBy('name')->get();
        $equipment = PkEquipment::query()->whereIn('pk_area_id', $areas->pluck('id'))->selectRaw('pk_area_id, count(*) as total')->groupBy('pk_area_id')->pluck('total', 'pk_area_id');
        $installations = PkInstallation::query()->whereIn('pk_area_id', $areas->pluck('id'))->selectRaw('pk_area_id, count(*) as total')->groupBy('pk_area_id')->pluck('total', 'pk_area_id');

        $present = function (PkArea $area) use ($firms, $equipment, $installations, $areas) {
            $parent = $area->parent_id ? $areas->firstWhere('id', $area->parent_id) : null;
            $firmId = $area->location_business_entity_id ?? $parent?->location_business_entity_id;

            return [
                'id' => $area->id,
                'name' => $area->name,
                'parent_id' => $area->parent_id,
                'type' => ['id' => $area->type?->id, 'name' => $area->type?->name, 'kind' => $area->type?->kind],
                'workplace_id' => $area->location_business_entity_id,
                // Alanın firması (kendi ya da üst alanın): ekipmanın firması buradan gelir.
                'firm' => $firmId ? ['workplace_id' => $firmId, 'name' => $firms[$firmId] ?? null, 'inherited' => !$area->location_business_entity_id] : null,
                'is_active' => $area->is_active,
                'equipment_count' => (int) ($equipment[$area->id] ?? 0),
                'installation_count' => (int) ($installations[$area->id] ?? 0),
            ];
        };
        $tree = $areas->whereNull('parent_id')->map(fn (PkArea $area) => $present($area) + [
            'children' => $areas->where('parent_id', $area->id)->map($present)->values()->all(),
        ])->values()->all();

        return [
            'areas' => $tree,
            'firms' => collect($firms)->map(fn (?string $name, int $id) => ['workplace_id' => $id, 'name' => $name])->values()->all(),
        ];
    }

    public function save(User $user, array $data, ?PkArea $area = null): PkArea
    {
        $this->assertWriter($user);
        $locationId = $area?->location_id ?? (int) $data['location_id'];
        Location::query()->findOrFail($locationId);
        $type = PkAreaType::query()->whereKey($data['pk_area_type_id'])
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $this->tenantContext->id()))->first();
        if (!$type) {
            throw ValidationException::withMessages(['pk_area_type_id' => 'Alan türü bulunamadı.']);
        }

        $parent = !empty($data['parent_id']) ? PkArea::query()->where('location_id', $locationId)->find($data['parent_id']) : null;
        if (!empty($data['parent_id']) && !$parent) {
            throw ValidationException::withMessages(['parent_id' => 'Üst alan bu lokasyonda değil.']);
        }
        if ($parent && ($parent->parent_id || ($area && $parent->id === $area->id))) {
            throw ValidationException::withMessages(['parent_id' => 'Alt alan tek kattır: alt alanın içine alan eklenmez.']);
        }
        if ($parent && $area && $area->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Alt alanları olan alan başka bir alanın içine taşınamaz.']);
        }

        // Alan fiziksel yerdir; firma bağı ekranda kullanılmaz (birimler ayrı: PkOperationalUnitService). Alan düzenlenince mevcut
        // bağ korunur; ortak alana firma bağlanmaz.
        $workplaceId = array_key_exists('location_business_entity_id', $data)
            ? (!empty($data['location_business_entity_id']) ? (int) $data['location_business_entity_id'] : null)
            : $area?->location_business_entity_id;
        if ($type->kind === PkAreaType::COMMON) {
            $workplaceId = null;
        }
        if ($workplaceId && !array_key_exists($workplaceId, $this->firmsOf($locationId))) {
            throw ValidationException::withMessages(['location_business_entity_id' => 'Firma bu lokasyonda değil.']);
        }

        $values = [
            'parent_id' => $parent?->id,
            'pk_area_type_id' => $type->id,
            'name' => trim((string) $data['name']),
            'location_business_entity_id' => $workplaceId,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($area?->is_active ?? true),
        ];
        if ($area) {
            $area->update($values);

            return $area->refresh();
        }

        return PkArea::query()->create($values + ['tenant_id' => $this->tenantContext->id(), 'location_id' => $locationId]);
    }

    // Alt alanı, ekipmanı ya da tesisatı olan alan silinmez (kayıtların alanı sessizce boşalmasın).
    public function delete(User $user, PkArea $area): void
    {
        $this->assertWriter($user);
        $blockers = array_filter([
            $area->children()->count() ? $area->children()->count() . ' alt alan' : null,
            ($count = PkEquipment::query()->where('pk_area_id', $area->id)->count()) ? "{$count} ekipman" : null,
            ($count = PkInstallation::query()->where('pk_area_id', $area->id)->count()) ? "{$count} tesisat" : null,
        ]);
        if ($blockers) {
            throw ValidationException::withMessages(['area' => 'Bu alan silinemez: ' . implode(', ', $blockers) . ' var. Önce onları başka alana taşıyın.']);
        }
        $area->delete();
    }

    // Lokasyondaki ekipman ve tesisatların alanı (liste ekranları için): kimlik → alan.
    public function assignments(int $locationId): array
    {
        Location::query()->findOrFail($locationId);

        return [
            'equipment' => (object) PkEquipment::query()->where('location_id', $locationId)->whereNotNull('pk_area_id')->pluck('pk_area_id', 'id')->all(),
            'installations' => (object) PkInstallation::query()->where('location_id', $locationId)->whereNotNull('pk_area_id')->pluck('pk_area_id', 'id')->all(),
        ];
    }

    // Ekipmanın alanı: aynı lokasyondan bir alan ya da boş. Yalnızca yer; ekipmanın firması (sahibi / kullananı) değişmez
    // (ör. İdari Bina'daki ekipmanı Arçelik Pazarlama kullanır).
    public function assignEquipment(PkEquipment $equipment, ?int $areaId): PkEquipment
    {
        $area = $this->areaFor($equipment->location_id, $areaId);
        $equipment->forceFill(['pk_area_id' => $area?->id])->save();

        return $equipment;
    }

    // Tesisatın alanı (bina bazında kayıt için; tesisat yine lokasyon geneli kalır, firması değişmez).
    public function assignInstallation(PkInstallation $installation, ?int $areaId): PkInstallation
    {
        $area = $this->areaFor($installation->location_id, $areaId);
        $installation->forceFill(['pk_area_id' => $area?->id])->save();

        return $installation;
    }

    private function areaFor(int $locationId, ?int $areaId): ?PkArea
    {
        if (!$areaId) {
            return null;
        }
        $area = PkArea::query()->where('location_id', $locationId)->with('parent')->find($areaId);
        if (!$area) {
            throw ValidationException::withMessages(['pk_area_id' => 'Alan bu lokasyonda değil.']);
        }

        return $area;
    }

    // Lokasyondaki firmalar: işyeri kimliği → firma adı.
    private function firmsOf(int $locationId): array
    {
        return DB::table('location_business_entities as l')
            ->join('business_entities as b', 'b.id', '=', 'l.business_entity_id')
            ->leftJoin('companies as c', 'c.business_entity_id', '=', 'b.id')
            ->where('l.location_id', $locationId)
            ->orderByRaw('coalesce(c.name, b.name)')
            ->get(['l.id', DB::raw('coalesce(c.name, b.name) as name')])
            ->mapWithKeys(fn ($row) => [(int) $row->id => $row->name])
            ->all();
    }

    private function assertWriter(User $user): void
    {
        $tenant = $this->accounts->tenantOf($user);
        abort_unless($tenant, 403, 'Hesap bulunamadı.');
        abort_if(in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true) && $this->accounts->roleOf($tenant, $user) !== 'yonetici', 403, 'Alanları yönetici düzenler.');
    }

    private function key(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
