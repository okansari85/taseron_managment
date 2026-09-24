<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Organization;
use App\Services\CustomerOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

// Müşteri organizasyonunun yalnızca adını günceller; diğer alanlara dokunmaz.
class CustomerOrganizationRenameController extends Controller
{
    public function __construct(
        private CustomerOrganizationService $customerOrganizationService
    ) {
    }

    public function __invoke(Request $request, Customer $customer, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        // tree() müşterinin tenant kontrolünü de yapar.
        if (! $this->organizationIdsInTree($this->customerOrganizationService->tree($customer))->contains($organization->id)) {
            throw ValidationException::withMessages([
                'organization' => 'Seçilen organizasyon bu müşteriye bağlı değil.',
            ]);
        }

        if (in_array($organization->type, ['company', 'brand'], true)) {
            throw ValidationException::withMessages([
                'organization' => 'Company ve Brand düğümleri buradan düzenlenemez.',
            ]);
        }

        $organization->update(['name' => $data['name']]);

        return response()->json($organization->fresh());
    }

    private function organizationIdsInTree(array $nodes): \Illuminate\Support\Collection
    {
        $ids = collect();
        $walk = function (array $nodes) use (&$walk, $ids): void {
            foreach ($nodes as $node) {
                $ids->push((int) $node['id']);
                $walk($node['children'] ?? []);
            }
        };
        $walk($nodes);

        return $ids;
    }
}
