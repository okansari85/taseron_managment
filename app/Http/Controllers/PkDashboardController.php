<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Services\PkDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Uzman paneli Genel Bakış ve Durum Raporu: seçilen lokasyonların (uzmanın tüm lokasyonları, bir müşterininkiler ya da
// tek lokasyon / işyeri) ekipman ve tesisat özeti. Lokasyonlar tenant'a ait olmalı (TenantScope).
class PkDashboardController extends Controller
{
    public function __construct(private PkDashboardService $service)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        [$locationIds, $workplaceId] = $this->scope($request);

        return response()->json($this->service->overview($locationIds, $workplaceId));
    }

    public function report(Request $request): JsonResponse
    {
        [$locationIds, $workplaceId] = $this->scope($request);

        return response()->json($this->service->report($locationIds, $workplaceId));
    }

    // Genel Bakış tür tablosu (Durum Raporu'nun tamamı yerine yalnızca türler).
    public function types(Request $request): JsonResponse
    {
        [$locationIds, $workplaceId] = $this->scope($request);

        return response()->json($this->service->typeRows($locationIds, $workplaceId));
    }

    // Kontrol takviminde bir günün bir grubu (tesisat, tüp ya da tür; overview agenda'daki grup anahtarı): o gün kontrolü
    // dolan ekipmanlar.
    public function due(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'group' => ['required', 'string', 'max:40']]);
        [$locationIds, $workplaceId] = $this->scope($request);

        return response()->json($this->service->due($locationIds, $workplaceId, $data['date'], $data['group']));
    }

    private function scope(Request $request): array
    {
        $data = $request->validate([
            'location_ids' => ['nullable', 'array', 'max:500'],
            'location_ids.*' => ['integer'],
            'workplace_id' => ['nullable', 'integer'],
        ]);
        $locationIds = Location::query()->whereIn('id', (array) ($data['location_ids'] ?? []))->pluck('id')->all();
        // İşyeri yalnızca tek lokasyon seçiliyken anlamlı.
        $workplaceId = count($locationIds) === 1 && !empty($data['workplace_id']) ? (int) $data['workplace_id'] : null;

        return [$locationIds, $workplaceId];
    }
}
