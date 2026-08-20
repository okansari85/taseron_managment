<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Brand;
use App\Models\Company;
use App\Repositories\Contracts\BrandRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class BrandService
{
    public function __construct(
        private BrandRepositoryInterface $repository,
        private TenantContext $tenantContext
    ) {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): Brand
    {
        return $this->repository->find($id);
    }

    public function create(array $data): Brand
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        return DB::transaction(function () use ($data) {
            $brand = $this->repository->create([
                'tenant_id' => $this->tenantContext->id(),
                'name' => $data['name'],
            ]);

            $this->syncCompanies(
                $brand,
                $data['company_ids'] ?? []
            );

            return $brand->load('companies');
        });
    }

    public function update(
        Brand $brand,
        array $data
    ): Brand {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        return DB::transaction(function () use ($brand, $data) {
            $tenantId = $this->tenantContext->id();

            if ($brand->tenant_id !== $tenantId) {
                throw new RuntimeException(
                    'Bu markaya erişim yetkiniz yok.'
                );
            }

            unset($data['tenant_id']);

            $companyIdsProvided = array_key_exists('company_ids', $data);
            $companyIds = $data['company_ids'] ?? [];
            unset($data['company_ids']);

            $brand = $this->repository->update(
                $brand,
                $data
            );

            if ($companyIdsProvided) {
                $this->syncCompanies($brand, $companyIds);
            }

            return $brand->load('companies');
        });
    }

    public function delete(Brand $brand): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        DB::transaction(function () use ($brand) {
            if ($brand->tenant_id !== $this->tenantContext->id()) {
                throw new RuntimeException(
                    'Bu markaya erişim yetkiniz yok.'
                );
            }

            $this->repository->delete($brand);
        });
    }

    /**
     * @param array<int, int|string> $companyIds
     */
    private function syncCompanies(Brand $brand, array $companyIds): void
    {
        if ($companyIds === []) {
            $brand->companies()->sync([]);
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $companyIds)));

        $validIds = Company::query()
            ->whereIn('id', $ids)
            ->whereHas('businessEntity', function ($query) {
                $query->where('tenant_id', $this->tenantContext->id());
            })
            ->pluck('id')
            ->all();

        if (count($validIds) !== count($ids)) {
            throw new RuntimeException(
                'Seçilen şirketlerden biri bu tenant içerisinde bulunamadı.'
            );
        }

        $brand->companies()->sync($validIds);
    }
}
