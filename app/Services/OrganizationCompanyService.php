<?php

namespace App\Services;

use App\Models\BusinessEntity;
use App\Models\Organization;
use App\Repositories\Contracts\OrganizationCompanyRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrganizationCompanyService
{
    public function __construct(
        private OrganizationCompanyRepositoryInterface $repository
    ) {
    }

    public function all(
        Organization $organization
    ): Collection {
        return $this->repository->all($organization);
    }

    public function attach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): void {
        $this->ensureCompany($businessEntity);

        $this->ensureSameTenant(
            $organization,
            $businessEntity
        );

        DB::transaction(function () use (
            $organization,
            $businessEntity
        ) {
            $this->repository->attach(
                $organization,
                $businessEntity
            );
        });
    }

    public function detach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): void {
        $this->ensureCompany($businessEntity);

        $this->ensureSameTenant(
            $organization,
            $businessEntity
        );

        DB::transaction(function () use (
            $organization,
            $businessEntity
        ) {
            $this->repository->detach(
                $organization,
                $businessEntity
            );
        });
    }

    public function sync(
        Organization $organization,
        array $businessEntityIds
    ): void {
        $businessEntityIds = array_values(
            array_unique($businessEntityIds)
        );

        $businessEntities = BusinessEntity::query()
            ->whereIn('id', $businessEntityIds)
            ->get();

        if (
            $businessEntities->count() !==
            count($businessEntityIds)
        ) {
            throw new RuntimeException(
                'Seçilen BusinessEntity kayıtlarından biri veya birkaçı bulunamadı.'
            );
        }

        foreach ($businessEntities as $businessEntity) {
            $this->ensureCompany($businessEntity);

            $this->ensureSameTenant(
                $organization,
                $businessEntity
            );
        }

        DB::transaction(function () use (
            $organization,
            $businessEntityIds
        ) {
            $this->repository->sync(
                $organization,
                $businessEntityIds
            );
        });
    }

    private function ensureCompany(
        BusinessEntity $businessEntity
    ): void {
        if ($businessEntity->type !== 'company') {
            throw new RuntimeException(
                'Organization yalnızca company tipindeki BusinessEntity ile eşleştirilebilir.'
            );
        }
    }

    private function ensureSameTenant(
        Organization $organization,
        BusinessEntity $businessEntity
    ): void {
        if (
            $organization->tenant_id !==
            $businessEntity->tenant_id
        ) {
            throw new RuntimeException(
                'Organization ve BusinessEntity aynı tenant içerisinde olmalıdır.'
            );
        }
    }
}
