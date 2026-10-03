<?php

namespace App\Http\Controllers;

use App\Models\PkArea;
use App\Models\PkAreaType;
use App\Models\PkEquipment;
use App\Models\PkInstallation;
use App\Services\PkAreaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// pktakip alanları (Ayarlar → Lokasyonlar → Alanlar), alan türleri ve ekipmana / tesisata alan seçimi. Ayrıntı: PkAreaService.
class PkAreaController extends Controller
{
    public function __construct(private PkAreaService $service)
    {
    }

    public function types(): JsonResponse
    {
        return response()->json($this->service->types());
    }

    public function storeType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in([PkAreaType::OPERATIONAL, PkAreaType::COMMON])],
        ]);

        return response()->json($this->service->addType($request->user(), $data['name'], $data['kind']), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);

        return response()->json($this->service->list((int) $data['location_id']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + ['location_id' => ['required', 'integer']]);
        $this->service->save($request->user(), $data);

        return response()->json($this->service->list((int) $data['location_id']), 201);
    }

    public function update(Request $request, PkArea $pkArea): JsonResponse
    {
        $this->service->save($request->user(), $request->validate($this->rules()), $pkArea);

        return response()->json($this->service->list($pkArea->location_id));
    }

    public function destroy(Request $request, PkArea $pkArea): JsonResponse
    {
        $locationId = $pkArea->location_id;
        $this->service->delete($request->user(), $pkArea);

        return response()->json($this->service->list($locationId));
    }

    public function assignments(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);

        return response()->json($this->service->assignments((int) $data['location_id']));
    }

    public function equipmentArea(Request $request, PkEquipment $pkEquipment): JsonResponse
    {
        $data = $request->validate(['pk_area_id' => ['nullable', 'integer']]);
        $equipment = $this->service->assignEquipment($pkEquipment, isset($data['pk_area_id']) ? (int) $data['pk_area_id'] : null);

        return response()->json(['id' => $equipment->id, 'pk_area_id' => $equipment->pk_area_id, 'location_business_entity_id' => $equipment->location_business_entity_id]);
    }

    public function installationArea(Request $request, PkInstallation $pkInstallation): JsonResponse
    {
        $data = $request->validate(['pk_area_id' => ['nullable', 'integer']]);
        $installation = $this->service->assignInstallation($pkInstallation, isset($data['pk_area_id']) ? (int) $data['pk_area_id'] : null);

        return response()->json(['id' => $installation->id, 'pk_area_id' => $installation->pk_area_id]);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'pk_area_type_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'location_business_entity_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
