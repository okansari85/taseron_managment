<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Brand;
use App\Models\Organization;
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
            $tenantId = $this->tenantContext->id();

            $organization = Organization::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['organization_id'])
                ->first();

            if ($organization === null) {
                throw new RuntimeException(
                    'Seçilen organizasyon bu tenant içerisinde bulunamadı.'
                );
            }

            return $this->repository->create([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'name' => $data['name'],
            ]);
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

            if (array_key_exists('organization_id', $data)) {
                $organizationExists = Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($data['organization_id'])
                    ->exists();

                if (! $organizationExists) {
                    throw new RuntimeException(
                        'Seçilen organizasyon bu tenant içerisinde bulunamadı.'
                    );
                }
            }

            return $this->repository->update(
                $brand,
                $data
            );
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
}
