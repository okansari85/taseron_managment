<?php

namespace App\Http\Controllers;

use App\Models\BusinessEntity;
use App\Models\Customer;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Services\CustomerLocationCompanyService;
use App\Services\CustomerLocationService;
use App\Services\LocationExpertService;
use App\Services\PkAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * İşyeri adı (pktakip): firmanın lokasyondaki kaydının (işyeri) isteğe bağlı adı. Firma tüzel kişi (Arçelik A.Ş.), işyeri onun
 * lokasyondaki kaydı (SGK, NACE, tehlike sınıfı, uzman); aynı firmanın aynı lokasyonda birden çok işyeri olabilir (Eskişehir'de
 * Buzdolabı ve Kompresör işletmeleri) ve adlarıyla ayırt edilir. Mevcut firma ekleme işlemi aynı firmayı ikinci kez eklemediği
 * için ikinci işyeri burada açılır. Adı değiştirmek: OSGB / kurumsal hesapta yönetici, bireysel uzman hesabında uzman.
 */
class PkWorkplaceNameController extends Controller
{
    public function __construct(private PkAccountService $accounts)
    {
    }

    // Hesabın işyeri adları: işyeri kimliği → ad.
    public function index(): JsonResponse
    {
        $names = DB::table('pk_workplace_names as n')
            ->join('location_business_entities as l', 'l.id', '=', 'n.location_business_entity_id')
            ->whereIn('l.location_id', Location::query()->select('id'))
            ->pluck('n.name', 'n.location_business_entity_id');

        return response()->json((object) $names->all());
    }

    public function update(Request $request, int $workplace): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255']]);
        $this->assertWriter($request);
        $record = LocationBusinessEntity::query()->whereKey($workplace)->whereIn('location_id', Location::query()->select('id'))->firstOrFail();
        $this->setName($record->id, $data['name'] ?? null);

        return response()->json(['id' => $record->id, 'name' => trim((string) ($data['name'] ?? '')) ?: null]);
    }

    // Aynı firmanın bu lokasyondaki ek işyeri (adı zorunlu); firma lokasyonda yoksa normal firma ekleme kullanılır.
    // KSY-SIS: işyeri bir ana firmaya aittir (customer_id; boşsa lokasyonun ilk ana firması), isteğe bağlı alt tesiste olabilir.
    public function storeAdditional(Request $request, LocationExpertService $experts, CustomerLocationService $customerLocations, CustomerLocationCompanyService $companies): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'business_entity_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'hazard_class' => ['required', 'string', 'max:100'],
            'nace_code' => ['nullable', 'string', 'max:50'],
            'activity' => ['nullable', 'string', 'max:255'],
            'sgk_workplace_number' => ['nullable', 'string', 'max:50'],
            'customer_id' => ['nullable', 'integer'],
            'sub_facility_id' => ['nullable', 'integer'],
        ]);
        $this->assertWriter($request);
        $location = Location::query()->findOrFail($data['location_id']);
        $entity = BusinessEntity::query()->find($data['business_entity_id']);
        if (!$entity || $entity->type !== 'company') {
            throw ValidationException::withMessages(['business_entity_id' => 'Yalnızca firmalar eklenebilir.']);
        }
        $existing = LocationBusinessEntity::query()->where('location_id', $location->id)->where('business_entity_id', $entity->id)->pluck('id');
        if ($existing->isEmpty()) {
            throw ValidationException::withMessages(['business_entity_id' => 'Firma bu lokasyonda yok; normal firma ekleme kullanılır.']);
        }
        $customer = $this->ownerOf($location, $data['customer_id'] ?? null, $customerLocations);
        if ($customer) {
            $companies->assertCustomerCompany($customer, $entity);
        }
        $subFacilityId = $companies->subFacilityId($location, $data['sub_facility_id'] ?? null);
        $name = trim($data['name']);
        $taken = DB::table('pk_workplace_names')->whereIn('location_business_entity_id', $existing)->get(['name'])
            ->contains(fn ($row) => mb_strtolower($row->name, 'UTF-8') === mb_strtolower($name, 'UTF-8'));
        if ($taken) {
            throw ValidationException::withMessages(['name' => "Bu firmanın bu lokasyonda \"{$name}\" adlı işyeri zaten var."]);
        }

        $record = DB::transaction(function () use ($location, $entity, $data, $name, $experts, $request, $customer, $subFacilityId) {
            $id = DB::table('location_business_entities')->insertGetId([
                'location_id' => $location->id, 'business_entity_id' => $entity->id, 'hazard_class' => $data['hazard_class'],
                'nace_code' => $data['nace_code'] ?? null, 'activity' => $data['activity'] ?? null, 'sgk_workplace_number' => $data['sgk_workplace_number'] ?? null,
                'customer_id' => $customer?->id, 'sub_facility_id' => $subFacilityId,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($customer) {
                DB::table('business_entities')->where('id', $entity->id)->whereNull('customer_id')->update(['customer_id' => $customer->id]);
            }
            $this->setName($id, $name);
            $record = LocationBusinessEntity::query()->findOrFail($id);
            // Normal firma eklemedeki gibi işlemi yapan uzman işyerine atanır.
            $experts->attach($record, $request->user());

            return $record;
        });

        return response()->json(['id' => $record->id, 'location_id' => $record->location_id, 'business_entity_id' => $record->business_entity_id, 'name' => $name,
            'customer_id' => $record->customer_id, 'sub_facility_id' => $record->sub_facility_id], 201);
    }

    // İşyerinin ana firması: verilen ana firma bu hesabın olmalı ve lokasyon onun ağacında; verilmezse lokasyonun ilk ana firması.
    private function ownerOf(Location $location, mixed $customerId, CustomerLocationService $customerLocations): ?Customer
    {
        $customers = Customer::query()->where('tenant_id', $location->tenant_id)
            ->when($customerId, fn ($query) => $query->whereKey($customerId))->orderBy('id')->get();
        $owner = $customers->first(fn (Customer $customer) => $customerLocations->list($customer)->contains('id', $location->id));
        if ($customerId && ! $owner) {
            throw ValidationException::withMessages(['customer_id' => 'Seçilen ana firma bu hesapta değil ya da lokasyon bu ana firmaya bağlı değil.']);
        }

        return $owner;
    }

    private function setName(int $workplaceId, ?string $name): void
    {
        $name = trim((string) $name);
        if ($name === '') {
            DB::table('pk_workplace_names')->where('location_business_entity_id', $workplaceId)->delete();

            return;
        }
        DB::table('pk_workplace_names')->updateOrInsert(['location_business_entity_id' => $workplaceId], ['name' => mb_substr($name, 0, 255), 'updated_at' => now(), 'created_at' => now()]);
    }

    private function assertWriter(Request $request): void
    {
        $tenant = $this->accounts->tenantOf($request->user());
        abort_unless($tenant, 403, 'Hesap bulunamadı.');
        abort_if(in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true) && $this->accounts->roleOf($tenant, $request->user()) !== 'yonetici', 403, 'İşyeri adını ve işyerlerini yönetici düzenler.');
    }
}
