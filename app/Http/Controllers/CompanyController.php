<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\Services\CompanyService;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    public function __construct(
        private CompanyService $service
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json(
            $this->service->all()
        );
    }

    public function store(
        StoreCompanyRequest $request
    ): JsonResponse {
        $company = $this->service->create(
            $request->validated()
        );

        return response()->json(
            $company,
            201
        );
    }

    public function show(
        Company $company
    ): JsonResponse {
        return response()->json(
            $this->service->find($company->id)
        );
    }

    public function update(
        UpdateCompanyRequest $request,
        Company $company
    ): JsonResponse {
        $company = $this->service->update(
            $company,
            $request->validated()
        );

        return response()->json(
            $company
        );
    }

    public function destroy(
        Company $company
    ): JsonResponse {
        $this->service->delete($company);

        return response()->json([
            'message' => 'Şirket başarıyla silindi.',
        ]);
    }
}