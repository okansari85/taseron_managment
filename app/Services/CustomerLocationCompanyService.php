<?php

namespace App\Services;

use App\Models\BusinessEntity;
use App\Models\Customer;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Müşteri lokasyonlarına company tipindeki firmaları bağlar; ekleyen uzmanı otomatik atar.
// Mevcut servisleri yalnızca çağırır, davranışlarını değiştirmez.
class CustomerLocationCompanyService
{
    // location_business_entities silinince bu tablolardaki kayıtlar da cascade ile silinir.
    private const DEPENDENT_TABLES = [
        'location_emergency_equipment',
        'emergency_equipment_annual_control_reports',
        'field_findings',
        'fire_suppression_inventory_items',
        'fire_suppression_reports',
    ];

    public function __construct(
        private CustomerLocationService $customerLocationService,
        private LocationBusinessEntityService $locationBusinessEntityService,
        private LocationExpertService $locationExpertService
    ) {
    }

    public function list(Customer $customer, Location $location): Collection
    {
        $this->assertCustomerLocation($customer, $location);

        return LocationBusinessEntity::query()
            ->where('location_id', $location->id)
            ->whereHas('businessEntity', fn ($query) => $query->where('type', 'company'))
            ->with('businessEntity.company')
            ->orderBy('id')
            ->get()
            ->map(function (LocationBusinessEntity $item) {
                $experts = DB::table('location_experts')
                    ->join('users', 'users.id', '=', 'location_experts.user_id')
                    ->where('location_experts.location_business_entity_id', $item->id)
                    ->pluck('users.name');

                return [
                    'id' => $item->id,
                    'business_entity_id' => $item->business_entity_id,
                    'name' => $item->businessEntity?->company?->name ?? $item->businessEntity?->name,
                    'nace_code' => $item->nace_code,
                    'activity' => $item->activity,
                    'hazard_class' => $item->hazard_class,
                    'sgk_workplace_number' => $item->sgk_workplace_number,
                    'address' => $item->address,
                    'is_active' => (bool) $item->is_active,
                    'experts' => $experts->values(),
                    'created_at' => $item->created_at,
                ];
            })
            ->values();
    }

    public function attach(Customer $customer, Location $location, User $expert, array $data): LocationBusinessEntity
    {
        $this->assertCustomerLocation($customer, $location);

        $businessEntity = BusinessEntity::query()->find($data['business_entity_id']);

        if (! $businessEntity || $businessEntity->type !== 'company') {
            throw ValidationException::withMessages([
                'business_entity_id' => 'Yalnızca firmalar (company) lokasyona eklenebilir.',
            ]);
        }

        return DB::transaction(function () use ($location, $businessEntity, $expert, $data) {
            $this->locationBusinessEntityService->attach($location, $businessEntity, [
                'hazard_class' => $data['hazard_class'],
                'nace_code' => $data['nace_code'] ?? null,
                'activity' => $data['activity'] ?? null,
                'sgk_workplace_number' => $data['sgk_workplace_number'] ?? null,
                'address' => $data['address'] ?? null,
            ]);

            $locationBusinessEntity = LocationBusinessEntity::query()
                ->where('location_id', $location->id)
                ->where('business_entity_id', $businessEntity->id)
                ->whereNull('operational_region_id')
                ->latest('id')
                ->firstOrFail();

            $this->locationExpertService->attach($locationBusinessEntity, $expert);

            return $locationBusinessEntity;
        });
    }

    public function detach(Customer $customer, Location $location, LocationBusinessEntity $locationBusinessEntity): void
    {
        $this->assertCustomerLocation($customer, $location);

        foreach (self::DEPENDENT_TABLES as $table) {
            if (DB::table($table)->where('location_business_entity_id', $locationBusinessEntity->id)->exists()) {
                throw ValidationException::withMessages([
                    'location_business_entity' => 'Bu firmaya bağlı ekipman, rapor veya bulgu kayıtları var; firma lokasyondan kaldırılamaz.',
                ]);
            }
        }

        $this->locationBusinessEntityService->detach($location, $locationBusinessEntity);
    }

    private function assertCustomerLocation(Customer $customer, Location $location): void
    {
        $locationIds = $this->customerLocationService->list($customer)->pluck('id');

        if (! $locationIds->contains($location->id)) {
            throw ValidationException::withMessages([
                'location' => 'Seçilen lokasyon bu müşteriye bağlı değil.',
            ]);
        }
    }
}
