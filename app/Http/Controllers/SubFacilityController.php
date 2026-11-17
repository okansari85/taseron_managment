<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * KSY-SIS yapısı: lokasyonun alt tesisleri (bina, hangar, kat…). Aynı firmanın aynı lokasyondaki ikinci işyeri ayrı alt tesiste açılır.
 * Location TenantScope ile başka hesabın lokasyonu bulunamaz (404).
 */
class SubFacilityController extends Controller
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    public function index(Location $location): JsonResponse
    {
        $items = DB::table('sub_facilities')->where('location_id', $location->id)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name,
                'workplaces_count' => DB::table('location_business_entities')->where('sub_facility_id', $row->id)->count()]);

        return response()->json($items->values());
    }

    public function store(Request $request, Location $location): JsonResponse
    {
        $name = trim($request->validate(['name' => ['required', 'string', 'max:255']])['name']);
        $taken = DB::table('sub_facilities')->where('location_id', $location->id)->get(['name'])
            ->contains(fn ($row) => mb_strtolower($row->name, 'UTF-8') === mb_strtolower($name, 'UTF-8'));
        if ($taken) {
            throw ValidationException::withMessages(['name' => "Bu lokasyonda \"{$name}\" adlı alt tesis zaten var."]);
        }
        $id = DB::table('sub_facilities')->insertGetId([
            'tenant_id' => $this->tenantContext->id(), 'location_id' => $location->id, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'name' => $name, 'workplaces_count' => 0], 201);
    }

    public function destroy(Location $location, int $subFacility): JsonResponse
    {
        if (DB::table('location_business_entities')->where('sub_facility_id', $subFacility)->exists()) {
            throw ValidationException::withMessages(['sub_facility' => 'Bu alt tesiste işyeri var; önce işyerlerini kaldırın.']);
        }
        DB::table('sub_facilities')->where('id', $subFacility)->where('location_id', $location->id)->delete();

        return response()->json(['message' => 'Alt tesis silindi.']);
    }
}
