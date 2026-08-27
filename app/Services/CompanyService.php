<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\BusinessEntity;
use App\Models\Company;
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

            return $this->repository->update(
                $company,
                $companyData
            );
        });
    }

    public function delete(Company $company): void
    {
        DB::transaction(function () use ($company) {
            $businessEntityId = $company->business_entity_id;

            $this->repository->delete($company);

            if ($businessEntityId !== null) {
                BusinessEntity::query()
                    ->whereKey($businessEntityId)
                    ->delete();
            }
        });
    }
}
