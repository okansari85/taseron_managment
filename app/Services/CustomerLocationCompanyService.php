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
// KSY-SIS yapısı: işyeri ve firma ana firmaya aittir (customer_id); işyeri isteğe bağlı bir alt tesiste olabilir.
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

        // KSY-SIS yapısı: ortak lokasyonda yalnız bu ana firmanın işyerleri (sahibi boş eski kayıtlar herkese görünür).
        $subFacilities = DB::table('sub_facilities')->where('location_id', $location->id)->pluck('name', 'id');

        return LocationBusinessEntity::query()
            ->where('location_id', $location->id)
            ->where(fn ($query) => $query->whereNull('customer_id')->orWhere('customer_id', $customer->id))
            ->whereHas('businessEntity', fn ($query) => $query->where('type', 'company'))
            ->with('businessEntity.company')
            ->orderBy('id')
            ->get()
            ->map(function (LocationBusinessEntity $item) use ($subFacilities) {
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
                    'customer_id' => $item->customer_id,
                    'sub_facility_id' => $item->sub_facility_id,
                    'sub_facility_name' => $item->sub_facility_id ? $subFacilities->get($item->sub_facility_id) : null,
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
        $this->assertCustomerCompany($customer, $businessEntity);
        $subFacilityId = $this->subFacilityId($location, $data['sub_facility_id'] ?? null);
        // KSY-SIS: aynı firma aynı lokasyonda birden çok işyeri açabilir; her biri ayrı alt tesiste (ad ile ayırma yok).
        $taken = DB::table('location_business_entities')->where('location_id', $location->id)
            ->where('business_entity_id', $businessEntity->id)
            ->where(fn ($query) => $query->whereNull('customer_id')->orWhere('customer_id', $customer->id))->pluck('sub_facility_id');
        if ($taken->isNotEmpty() && ($subFacilityId === null || $taken->contains(fn ($id) => (int) $id === $subFacilityId))) {
            throw ValidationException::withMessages([
                'sub_facility_id' => $subFacilityId === null
                    ? 'Bu firmanın bu lokasyonda işyeri zaten var. İkinci işyeri için bir alt tesis seçin.'
                    : 'Bu firmanın bu alt tesiste işyeri zaten var.',
            ]);
        }

        return DB::transaction(function () use ($customer, $location, $businessEntity, $expert, $data, $subFacilityId, $taken) {
            $fields = [
                'hazard_class' => $data['hazard_class'],
                'nace_code' => $data['nace_code'] ?? null,
                'activity' => $data['activity'] ?? null,
                'sgk_workplace_number' => $data['sgk_workplace_number'] ?? null,
                'address' => $data['address'] ?? null,
            ];
            if ($taken->isEmpty()) {
                $this->locationBusinessEntityService->attach($location, $businessEntity, $fields);
                $id = LocationBusinessEntity::query()
                    ->where('location_id', $location->id)
                    ->where('business_entity_id', $businessEntity->id)
                    ->whereNull('operational_region_id')
                    ->latest('id')
                    ->value('id');
            } else {
                // İkinci işyeri: aynı firma başka alt tesiste (mevcut servis bölgesiz ikinci kaydı reddeder).
                $id = DB::table('location_business_entities')->insertGetId($fields + [
                    'location_id' => $location->id, 'business_entity_id' => $businessEntity->id,
                    'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $locationBusinessEntity = LocationBusinessEntity::query()->findOrFail($id);

            // İşyeri ana firmaya aittir; sahibi olmayan firma da bu ana firmaya geçer.
            DB::table('location_business_entities')->where('id', $locationBusinessEntity->id)
                ->update(['customer_id' => $customer->id, 'sub_facility_id' => $subFacilityId]);
            DB::table('business_entities')->where('id', $businessEntity->id)->whereNull('customer_id')->update(['customer_id' => $customer->id]);
            $locationBusinessEntity->refresh();

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

    // KSY-SIS: işyeri yalnız ana firmanın kendi firmalarıyla açılır (alt yüklenici KSY-SIS'te).
    public function assertCustomerCompany(Customer $customer, BusinessEntity $businessEntity): void
    {
        if ($businessEntity->customer_id && (int) $businessEntity->customer_id !== (int) $customer->id) {
            throw ValidationException::withMessages([
                'business_entity_id' => 'Bu firma başka bir ana firmaya ait; işyeri yalnız bu ana firmanın firmalarıyla açılır.',
            ]);
        }
    }

    // Alt tesis bu lokasyonun olmalı; boşsa işyeri lokasyonun genelindedir.
    public function subFacilityId(Location $location, mixed $id): ?int
    {
        if (! $id) {
            return null;
        }
        if (! DB::table('sub_facilities')->where('id', $id)->where('location_id', $location->id)->exists()) {
            throw ValidationException::withMessages(['sub_facility_id' => 'Seçilen alt tesis bu lokasyonda değil.']);
        }

        return (int) $id;
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
