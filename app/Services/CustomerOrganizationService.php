<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class CustomerOrganizationService
{
    public function __construct(
        private TenantContext $tenantContext,
        private OrganizationService $organizationService
    ) {
    }

    public function customers(): Collection
    {
        $tenantId = $this->tenantId();

        return Customer::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get()
            ->map(function (Customer $customer) {
                $organizationIds = $this->organizationIdsForCustomer($customer);

                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'notes' => $customer->notes,
                    'locations_count' => $organizationIds->isEmpty()
                        ? 0
                        : DB::table('organization_locations')
                            ->whereIn('organization_id', $organizationIds)
                            ->distinct('location_id')
                            ->count('location_id'),
                    'companies_count' => $organizationIds->isEmpty()
                        ? 0
                        : DB::table('organization_companies')
                            ->whereIn('organization_id', $organizationIds)
                            ->distinct('company_id')
                            ->count('company_id'),
                    'created_at' => $customer->created_at,
                ];
            });
    }

    public function createCustomer(array $data): Customer
    {
        $tenantId = $this->tenantId();

        return Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function updateCustomer(Customer $customer, array $data): Customer
    {
        $this->assertCustomerTenant($customer);

        $customer->update([
            'name' => $data['name'] ?? $customer->name,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $customer->notes,
        ]);

        return $customer->refresh();
    }

    public function deleteCustomer(Customer $customer): void
    {
        $this->assertCustomerTenant($customer);
        $customer->delete();
    }

    public function tree(Customer $customer): array
    {
        $this->assertCustomerTenant($customer);

        $organizations = $this->organizationsForCustomer($customer);

        $nodes = $organizations->keyBy('id')->map(function (Organization $organization) {
            return [
                'id' => $organization->id,
                'name' => $organization->name,
                'type' => $organization->type,
                'parent_id' => $organization->parent_id,
                'children' => [],
            ];
        });

        $roots = [];

        foreach ($nodes as $id => $node) {
            $parentId = $node['parent_id'];

            if ($parentId !== null && $nodes->has($parentId)) {
                $nodes[$parentId]['children'][] = &$nodes[$id];
            } else {
                $roots[] = &$nodes[$id];
            }
        }

        return array_values($roots);
    }

    public function createOrganization(Customer $customer, array $data): Organization
    {
        $this->assertCustomerTenant($customer);

        return DB::transaction(function () use ($customer, $data) {
            $parentId = $data['parent_id'] ?? null;

            if ($parentId !== null) {
                $parent = Organization::query()
                    ->whereKey($parentId)
                    ->first();

                if (! $parent || ! $this->organizationIdsForCustomer($customer)->contains($parent->id)) {
                    throw new RuntimeException('Seçilen üst organizasyon bu müşteriye bağlı değil.');
                }
            }

            $organization = $this->organizationService->create([
                'name' => $data['name'],
                'type' => $data['type'] ?? 'group',
                'parent_id' => $parentId,
                'slug' => $data['slug'] ?? null,
                'description' => $data['description'] ?? null,
                'code' => $data['code'] ?? null,
                'display_order' => $data['display_order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'color' => $data['color'] ?? null,
                'default_brand_id' => $data['default_brand_id'] ?? null,
            ]);

            $customer->organizations()->syncWithoutDetaching([$organization->id]);

            return $organization;
        });
    }

    public function attachOrganization(Customer $customer, Organization $organization): void
    {
        $this->assertCustomerTenant($customer);

        if ($organization->tenant_id !== $this->tenantId()) {
            throw new RuntimeException('Bu organizasyona erişim yetkiniz yok.');
        }

        $customer->organizations()->syncWithoutDetaching([$organization->id]);
    }

    public function detachOrganization(Customer $customer, Organization $organization): void
    {
        $this->assertCustomerTenant($customer);

        if ($organization->tenant_id !== $this->tenantId()) {
            throw new RuntimeException('Bu organizasyona erişim yetkiniz yok.');
        }

        $customer->organizations()->detach($organization->id);
    }

    private function organizationsForCustomer(Customer $customer): Collection
    {
        $organizationIds = $customer->organizations()
            ->pluck('organizations.id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($organizationIds->isEmpty()) {
            return collect();
        }

        $allIds = $organizationIds->unique()->values();

        do {
            $childIds = Organization::query()
                ->whereIn('parent_id', $allIds->all())
                ->whereNotIn('id', $allIds->all())
                ->pluck('id');

            if ($childIds->isEmpty()) {
                break;
            }

            $allIds = $allIds->merge($childIds)->unique()->values();
        } while (true);

        return Organization::query()
            ->whereIn('id', $allIds->all())
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    private function organizationIdsForCustomer(Customer $customer): Collection
    {
        return $this->organizationsForCustomer($customer)->pluck('id');
    }

    private function tenantId(): int
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        return $this->tenantContext->id();
    }

    private function assertCustomerTenant(Customer $customer): void
    {
        if ($customer->tenant_id !== $this->tenantId()) {
            throw new RuntimeException('Bu müşteriye erişim yetkiniz yok.');
        }
    }
}
