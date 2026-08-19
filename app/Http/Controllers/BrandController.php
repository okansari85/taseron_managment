<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Models\Brand;
use App\Services\BrandService;
use Illuminate\Http\JsonResponse;

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

    public function store(CreateBrandRequest $request): JsonResponse
    {
        $brand = $this->service->create(
            $request->validated()
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
        UpdateBrandRequest $request,
        Brand $brand
    ): JsonResponse {
        $brand = $this->service->update(
            $brand,
            $request->validated()
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
