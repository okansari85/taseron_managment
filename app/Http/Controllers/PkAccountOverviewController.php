<?php

namespace App\Http\Controllers;

use App\Services\PkAccountOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Genel Bakış: OSGB / kurumsal hesabın yöneticisi ve operasyon yöneticisi için uzman / müşteri bazında durum, uzmanı olmayan
// firmalar ve okuma kullanımı. location_ids: sayfanın kapsamı (filtre / il).
class PkAccountOverviewController extends Controller
{
    public function __construct(private PkAccountOverviewService $service)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['location_ids' => ['nullable', 'array', 'max:500'], 'location_ids.*' => ['integer']]);
        $tenant = $this->service->viewerTenant($request->user());

        return response()->json($this->service->overview($tenant, array_map('intval', $data['location_ids'] ?? [])));
    }
}
