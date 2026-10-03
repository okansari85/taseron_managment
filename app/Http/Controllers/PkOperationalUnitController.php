<?php

namespace App\Http\Controllers;

use App\Models\PkEquipment;
use App\Models\PkOperationalUnit;
use App\Services\PkOperationalUnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Operasyonel birimler (Ayarlar → Lokasyonlar → Firmalar → "Birimler") ve ekipmana birim seçimi. Ayrıntı: PkOperationalUnitService.
class PkOperationalUnitController extends Controller
{
    public function __construct(private PkOperationalUnitService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);

        return response()->json($this->service->list((int) $data['location_id']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + ['location_business_entity_id' => ['required', 'integer']]);
        $unit = $this->service->save($request->user(), $data);

        return response()->json($this->service->list($this->locationOf($unit)), 201);
    }

    public function update(Request $request, PkOperationalUnit $pkOperationalUnit): JsonResponse
    {
        $unit = $this->service->save($request->user(), $request->validate($this->rules()), $pkOperationalUnit);

        return response()->json($this->service->list($this->locationOf($unit)));
    }

    public function destroy(Request $request, PkOperationalUnit $pkOperationalUnit): JsonResponse
    {
        $locationId = $this->locationOf($pkOperationalUnit);
        $this->service->delete($request->user(), $pkOperationalUnit);

        return response()->json($this->service->list($locationId));
    }

    public function assignments(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);

        return response()->json($this->service->assignments((int) $data['location_id']));
    }

    public function equipmentUnit(Request $request, PkEquipment $pkEquipment): JsonResponse
    {
        $data = $request->validate(['pk_operational_unit_id' => ['nullable', 'integer']]);
        $equipment = $this->service->assignEquipment($pkEquipment, isset($data['pk_operational_unit_id']) ? (int) $data['pk_operational_unit_id'] : null);

        return response()->json(['id' => $equipment->id, 'pk_operational_unit_id' => $equipment->pk_operational_unit_id]);
    }

    private function locationOf(PkOperationalUnit $unit): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('location_business_entities')->where('id', $unit->location_business_entity_id)->value('location_id');
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'manager' => ['nullable', 'string', 'max:255'],
            'area_ids' => ['sometimes', 'array', 'max:200'],
            'area_ids.*' => ['integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
