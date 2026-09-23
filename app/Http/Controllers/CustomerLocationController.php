<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Organization;
use App\Services\CustomerLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerLocationController extends Controller
{
    public function __construct(
        private CustomerLocationService $service
    ) {
    }

    public function index(Customer $customer): JsonResponse
    {
        return response()->json($this->service->list($customer));
    }

    public function store(Request $request, Customer $customer, Organization $organization): JsonResponse
    {
        $location = $this->service->create(
            $customer,
            $organization,
            $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'address' => ['nullable', 'string'],
                'city_id' => ['nullable', 'integer', 'exists:cities,id'],
                'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            ])
        );

        return response()->json($location, 201);
    }
}
