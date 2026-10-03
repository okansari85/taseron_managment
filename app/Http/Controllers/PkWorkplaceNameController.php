<?php

namespace App\Http\Controllers;

use App\Models\BusinessEntity;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
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
    public function storeAdditional(Request $request, LocationExpertService $experts): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'business_entity_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'hazard_class' => ['required', 'string', 'max:100'],
            'nace_code' => ['nullable', 'string', 'max:50'],
            'activity' => ['nullable', 'string', 'max:255'],
            'sgk_workplace_number' => ['nullable', 'string', 'max:50'],
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
        $name = trim($data['name']);
        $taken = DB::table('pk_workplace_names')->whereIn('location_business_entity_id', $existing)->get(['name'])
            ->contains(fn ($row) => mb_strtolower($row->name, 'UTF-8') === mb_strtolower($name, 'UTF-8'));
        if ($taken) {
            throw ValidationException::withMessages(['name' => "Bu firmanın bu lokasyonda \"{$name}\" adlı işyeri zaten var."]);
        }

        $record = DB::transaction(function () use ($location, $entity, $data, $name, $experts, $request) {
            $id = DB::table('location_business_entities')->insertGetId([
                'location_id' => $location->id, 'business_entity_id' => $entity->id, 'hazard_class' => $data['hazard_class'],
                'nace_code' => $data['nace_code'] ?? null, 'activity' => $data['activity'] ?? null, 'sgk_workplace_number' => $data['sgk_workplace_number'] ?? null,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->setName($id, $name);
            $record = LocationBusinessEntity::query()->findOrFail($id);
            // Normal firma eklemedeki gibi işlemi yapan uzman işyerine atanır.
            $experts->attach($record, $request->user());

            return $record;
        });

        return response()->json(['id' => $record->id, 'location_id' => $record->location_id, 'business_entity_id' => $record->business_entity_id, 'name' => $name], 201);
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
