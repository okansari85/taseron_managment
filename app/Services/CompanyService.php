<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\BusinessEntity;
use App\Models\Company;
use App\Models\Organization;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class CompanyService
{
    public function __construct(
        private CompanyRepositoryInterface $repository,
        private TenantContext $tenantContext
    ) {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): Company
    {
        return $this->repository->find($id);
    }

    public function create(array $data): Company
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        return DB::transaction(function () use ($data) {
            $businessEntity = BusinessEntity::query()->create([
                'tenant_id' => $this->tenantContext->id(),
                'type' => 'company',
                'name' => $data['name'],
            ]);

            return $this->repository->create([
                'name' => $data['name'],
                'short_name' => $data['short_name'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'company_type' => $data['company_type'] ?? null,
                'business_entity_id' => $businessEntity->id,
            ]);
        });
    }

    public function update(Company $company, array $data): Company
    {
        $this->ensureTenant($company);

        return DB::transaction(function () use ($company, $data) {
            $companyData = [];

            foreach ([
                'name',
                'short_name',
                'description',
                'is_active',
                'company_type',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $companyData[$field] = $data[$field];
                }
            }

            $updated = $this->repository->update($company, $companyData);

            // A Company has one organization membership and therefore one company node.
            // Update the existing node in place so all location/child relationships survive.
            $nodeId = DB::table('organization_companies')
                ->where('company_id', $company->id)
                ->value('company_node_id');

            if ($nodeId) {
                Organization::query()
                    ->whereKey($nodeId)
                    ->update(['name' => $updated->name]);
            }

            // BusinessEntity remains the legacy identity source for the Company model.
            if ($updated->business_entity_id) {
                BusinessEntity::query()
                    ->whereKey($updated->business_entity_id)
                    ->update(['name' => $updated->name]);
            }

            return $updated;
        });
    }

    public function delete(Company $company): void
    {
        $this->ensureTenant($company);

        DB::transaction(function () use ($company) {
            $membership = DB::table('organization_companies')
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();

            $companyNodeId = $membership?->company_node_id
                ? (int) $membership->company_node_id
                : null;

            // Collect every relationship node belonging to this Company's brand links,
            // even if the company membership is already missing. This prevents orphan
            // brand nodes from surviving a Company deletion.
            $brandNodeIds = DB::table('company_brands')
                ->where('company_id', $company->id)
                ->whereNotNull('brand_node_id')
                ->pluck('brand_node_id')
                ->map(fn ($id) => (int) $id);

            $nodeIds = collect($companyNodeId ? [$companyNodeId] : [])
                ->merge($brandNodeIds)
                ->unique()
                ->values();

            if ($nodeIds->isNotEmpty() && DB::table('organization_locations')
                ->whereIn('organization_id', $nodeIds)
                ->exists()) {
                throw new LogicException(
                    'Lokasyon bağlantısı olan şirket veya marka ilişkisi silinemez.'
                );
            }

            // Only relationship nodes are removed here. Real Brand records are never
            // deleted by deleting a Company, even when that Brand belongs to other Companies.
            if ($brandNodeIds->isNotEmpty()) {
                Organization::query()->whereIn('id', $brandNodeIds)->delete();
            }

            if ($companyNodeId) {
                Organization::query()->whereKey($companyNodeId)->delete();
            }

            $businessEntityId = $company->business_entity_id;

            $this->repository->delete($company);

            if ($businessEntityId !== null) {
                BusinessEntity::query()
                    ->whereKey($businessEntityId)
                    ->delete();
            }
        });
    }

    private function ensureTenant(Company $company): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        if ($company->businessEntity?->tenant_id !== $this->tenantContext->id()) {
            throw new LogicException('Bu şirkete erişim yetkiniz yok.');
        }
    }
}
