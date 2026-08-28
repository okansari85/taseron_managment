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
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
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

    public function update(
        Company $company,
        array $data
    ): Company {
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

            $updated = $this->repository->update(
                $company,
                $companyData
            );

            $nodeId = DB::table('organization_companies')
                ->where('company_id', $company->id)
                ->value('company_node_id');

            if ($nodeId) {
                Organization::query()->whereKey($nodeId)->update(['name' => $updated->name]);
            }

            return $updated;
        });
    }

    public function delete(Company $company): void
    {
        $nodeId = DB::table('organization_companies')
            ->where('company_id', $company->id)
            ->value('company_node_id');

        if ($nodeId && DB::table('organization_locations')->where('organization_id', $nodeId)->exists()) {
            throw new LogicException('Lokasyon bağlantısı olan şirket silinemez.');
        }

        $brandNodeIds = DB::table('company_brands')
            ->where('company_id', $company->id)
            ->whereNotNull('brand_node_id')
            ->pluck('brand_node_id');

        if (DB::table('organization_locations')->whereIn('organization_id', $brandNodeIds)->exists()) {
            throw new LogicException('Lokasyon bağlantısı olan marka ilişkisi bulunan şirket silinemez.');
        }

        DB::transaction(function () use ($company) {
            $businessEntityId = $company->business_entity_id;

            $brandNodeIds = DB::table('company_brands')
                ->where('company_id', $company->id)
                ->whereNotNull('brand_node_id')
                ->pluck('brand_node_id');
            Organization::query()->whereIn('id', $brandNodeIds)->delete();

            $nodeId = DB::table('organization_companies')
                ->where('company_id', $company->id)
                ->value('company_node_id');
            if ($nodeId) Organization::query()->whereKey($nodeId)->delete();

            $this->repository->delete($company);

            if ($businessEntityId !== null) {
                BusinessEntity::query()
                    ->whereKey($businessEntityId)
                    ->delete();
            }
        });
    }
}
