<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Organization;
use App\Services\CustomerOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerOrganizationController extends Controller
{
    public function __construct(
        private CustomerOrganizationService $service
    ) {
    }

    public function tree(Customer $customer): JsonResponse
    {
        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
            ],
            'organizations' => $this->service->tree($customer),
        ]);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $organization = $this->service->createOrganization(
            $customer,
            $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'type' => ['nullable', 'string', 'max:50'],
                'parent_id' => ['nullable', 'integer'],
                'slug' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'code' => ['nullable', 'string', 'max:100'],
                'display_order' => ['nullable', 'integer'],
                'is_active' => ['nullable', 'boolean'],
                'color' => ['nullable', 'string', 'max:50'],
                'default_brand_id' => ['nullable', 'integer'],
            ])
        );

        return response()->json($organization, 201);
    }

    public function attach(Customer $customer, Organization $organization): JsonResponse
    {
        $this->service->attachOrganization($customer, $organization);

        return response()->json([
            'message' => 'Organizasyon müşteriye bağlandı.',
        ]);
    }

    public function detach(Customer $customer, Organization $organization): JsonResponse
    {
        $this->service->detachOrganization($customer, $organization);

        return response()->json([
            'message' => 'Organizasyon müşteriden ayrıldı.',
        ]);
    }
}
