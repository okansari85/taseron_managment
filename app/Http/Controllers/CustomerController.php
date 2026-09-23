<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private CustomerOrganizationService $service
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->service->customers());
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->service->createCustomer(
            $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],
            ])
        );

        return response()->json($customer, 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $customer = $this->service->updateCustomer(
            $customer,
            $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],
            ])
        );

        return response()->json($customer);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->service->deleteCustomer($customer);

        return response()->json([
            'message' => 'Müşteri başarıyla silindi.',
        ]);
    }
}
