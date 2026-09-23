<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerLocationService
{
    public function __construct(
        private TenantContext $tenantContext,
        private CustomerOrganizationService $customerOrganizationService,
        private OrganizationLocationService $organizationLocationService
    ) {
    }

    public function list(Customer $customer): Collection
    {
        $organizationIds = $this->organizationIdsForCustomer($customer);

        if ($organizationIds->isEmpty()) {
            return collect();
        }

        $organizationNames = Organization::query()
            ->whereIn('id', $organizationIds->all())
            ->pluck('name', 'id');

        return Location::query()
            ->whereHas('organizations', fn ($query) => $query->whereIn('organizations.id', $organizationIds->all()))
            ->with([
                'organizations' => fn ($query) => $query->whereIn('organizations.id', $organizationIds->all()),
                'city:id,name',
                'district:id,name',
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Location $location) use ($organizationNames) {
                $organizationId = $location->organizations->first()?->id;

                return [
                    'id' => $location->id,
                    'name' => $location->name,
                    'address' => $location->address,
                    'city_id' => $location->city_id,
                    'city' => $location->city?->name,
                    'district_id' => $location->district_id,
                    'district' => $location->district?->name,
                    'is_active' => $location->is_active,
                    'organization_id' => $organizationId,
                    'organization_name' => $organizationId ? $organizationNames->get($organizationId) : null,
                    'created_at' => $location->created_at,
                ];
            })
            ->values();
    }

    public function create(Customer $customer, Organization $organization, array $data): Location
    {
        if (! $this->organizationIdsForCustomer($customer)->contains($organization->id)) {
            throw ValidationException::withMessages([
                'organization' => 'Seçilen organizasyon bu müşteriye bağlı değil.',
            ]);
        }

        return DB::transaction(function () use ($organization, $data) {
            $location = Location::create([
                'tenant_id' => $this->tenantContext->id(),
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'city_id' => $data['city_id'] ?? null,
                'district_id' => $data['district_id'] ?? null,
                'is_active' => true,
            ]);

            $this->organizationLocationService->attach($organization, $location);

            return $location;
        });
    }

    // Müşteri ağacındaki tüm organizasyon id'leri (tenant kontrolü tree() içinde yapılır).
    private function organizationIdsForCustomer(Customer $customer): Collection
    {
        $ids = collect();
        $walk = function (array $nodes) use (&$walk, $ids): void {
            foreach ($nodes as $node) {
                $ids->push((int) $node['id']);
                $walk($node['children'] ?? []);
            }
        };

        $walk($this->customerOrganizationService->tree($customer));

        return $ids;
    }
}
