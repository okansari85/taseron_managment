<?php

namespace App\Http\Controllers;

use App\Models\LocationBusinessEntity;
use App\Services\FireSuppressionInventoryService;
use Illuminate\Http\JsonResponse;

class FireSuppressionSystemComponentController extends Controller
{
    public function __construct(
        private FireSuppressionInventoryService $service
    ) {
    }

    // "Tesisat Durumu > Sistem" detay ekranı — bir sistemin (kategori) kalıcı
    // bileşenlerini ve son raporun o sisteme ait kontrol/bulgu verisini
    // birlikte döner.
    public function show(LocationBusinessEntity $locationBusinessEntity, string $category): JsonResponse
    {
        return response()->json([
            'data' => $this->service->componentDetail($locationBusinessEntity, $category),
        ]);
    }
}
