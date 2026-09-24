<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Services\CustomerLocationCompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerLocationCompanyController extends Controller
{
    public function __construct(
        private CustomerLocationCompanyService $service
    ) {
    }

    public function index(Customer $customer, Location $location): JsonResponse
    {
        return response()->json($this->service->list($customer, $location));
    }

    public function store(Request $request, Customer $customer, Location $location): JsonResponse
    {
        $locationBusinessEntity = $this->service->attach(
            $customer,
            $location,
            $request->user(),
            $request->validate([
                'business_entity_id' => ['required', 'integer'],
                'hazard_class' => ['required', 'string', 'max:100'],
                'nace_code' => ['nullable', 'string', 'max:50'],
                'activity' => ['nullable', 'string', 'max:255'],
                'sgk_workplace_number' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:255'],
            ])
        );

        return response()->json($locationBusinessEntity, 201);
    }

    public function destroy(Customer $customer, Location $location, LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        $this->service->detach($customer, $location, $locationBusinessEntity);

        return response()->json(['message' => 'Firma lokasyondan kaldırıldı.']);
    }
}
