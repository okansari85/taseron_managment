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
        $this->ensureGroup($organization);

        return $this->repository->all($organization);
    }

    public function allForTenant(): Collection
    {
        return $this->repository->allForTenant();
    }

    public function attach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): void {
        $this->ensureGroup($organization);
        $this->ensureCompany($businessEntity);
        $this->ensureSameTenant($organization, $businessEntity);
        $this->ensureCompanyNotInAnotherGroup($organization, $businessEntity);

        DB::transaction(function () use ($organization, $businessEntity) {
            $this->repository->attach($organization, $businessEntity);
        });
    }

    public function detach(
        Organization $organization,
        BusinessEntity $businessEntity
    ): void {
        $this->ensureGroup($organization);
        $this->ensureCompany($businessEntity);
        $this->ensureSameTenant($organization, $businessEntity);

        DB::transaction(function () use ($organization, $businessEntity) {
            $this->repository->detach($organization, $businessEntity);
        });
    }

    public function sync(
        Organization $organization,
        array $businessEntityIds
    ): void {
        $this->ensureGroup($organization);

        $businessEntityIds = array_values(array_unique($businessEntityIds));

        $businessEntities = BusinessEntity::query()
            ->whereIn('id', $businessEntityIds)
            ->get();

        if ($businessEntities->count() !== count($businessEntityIds)) {
            throw new RuntimeException(
                'Seçilen BusinessEntity kayıtlarından biri veya birkaçı bulunamadı.'
            );
        }

        foreach ($businessEntities as $businessEntity) {
            $this->ensureCompany($businessEntity);
            $this->ensureSameTenant($organization, $businessEntity);
            $this->ensureCompanyNotInAnotherGroup($organization, $businessEntity);
        }

        DB::transaction(function () use ($organization, $businessEntityIds) {
            $this->repository->sync($organization, $businessEntityIds);
        });
    }

    private function ensureGroup(Organization $organization): void
    {
        if ($organization->type !== 'group') {
            throw new RuntimeException(
                'Şirket yalnızca Grup tipindeki Organization ile eşleştirilebilir.'
            );
        }
    }

    private function ensureCompany(BusinessEntity $businessEntity): void
    {
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
        if ($organization->tenant_id !== $businessEntity->tenant_id) {
            throw new RuntimeException(
                'Organization ve BusinessEntity aynı tenant içerisinde olmalıdır.'
            );
        }
    }

    private function ensureCompanyNotInAnotherGroup(
        Organization $organization,
        BusinessEntity $businessEntity
    ): void {
        $existing = DB::table('organization_companies')
            ->where('business_entity_id', $businessEntity->id)
            ->where('organization_id', '!=', $organization->id)
            ->exists();

        if ($existing) {
            throw new RuntimeException(
                'Bu şirket zaten başka bir gruba bağlıdır. Bir şirket yalnızca bir gruba ait olabilir.'
            );
        }
    }
}
