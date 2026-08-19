<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Services\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function __construct(
        private BrandService $service
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $brand = $this->service->create(
            $request->validate([
                'organization_id' => [
                    'required',
                    'integer',
                ],
                'name' => [
                    'required',
                    'string',
                    'max:255',
                ],
            ])
        );

        return response()->json([
            'message' => 'Marka başarıyla oluşturuldu.',
            'data' => $brand,
        ], 201);
    }

    public function show(Brand $brand): JsonResponse
    {
        return response()->json([
            'data' => $this->service->find($brand->id),
        ]);
    }

    public function update(
        Request $request,
        Brand $brand
    ): JsonResponse {
        $data = $request->validate([
            'organization_id' => [
                'sometimes',
                'integer',
            ],
            'name' => [
                'sometimes',
                'string',
                'max:255',
            ],
        ]);

        $brand = $this->service->update(
            $brand,
            $data
        );

        return response()->json([
            'message' => 'Marka başarıyla güncellendi.',
            'data' => $brand,
        ]);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->service->delete($brand);

        return response()->json([
            'message' => 'Marka başarıyla silindi.',
        ]);
    }
}
