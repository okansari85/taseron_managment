<?php

namespace App\Services;

use App\Models\Company;
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

    public function all(Organization $organization): Collection
    {
        $this->ensureGroup($organization);

        return $this->repository->all($organization);
    }

    public function allForTenant(): Collection
    {
        return $this->repository->allForTenant();
    }

    public function attach(Organization $organization, Company $company): void
    {
        $this->ensureGroup($organization);
        $this->ensureSameTenant($organization, $company);

        DB::transaction(function () use ($organization, $company) {
            $membership = DB::table('organization_companies')
                ->where('company_id', $company->id)
                ->first();

            if ($membership === null) {
                $node = Organization::query()->create([
                    'tenant_id' => $organization->tenant_id,
                    'parent_id' => $organization->id,
                    'name' => $company->name,
                    'type' => 'company',
                ]);

                DB::table('organization_companies')->insert([
                    'organization_id' => $organization->id,
                    'company_id' => $company->id,
                    'company_node_id' => $node->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            $node = Organization::query()->findOrFail($membership->company_node_id);
            $node->update([
                'parent_id' => $organization->id,
                'name' => $company->name,
                'type' => 'company',
            ]);

            DB::table('organization_companies')
                ->where('company_id', $company->id)
                ->update([
                    'organization_id' => $organization->id,
                    'updated_at' => now(),
                ]);
        });
    }

    public function detach(Organization $organization, Company $company): void
    {
        $this->ensureGroup($organization);
        $this->ensureSameTenant($organization, $company);

        $membership = DB::table('organization_companies')
            ->where('organization_id', $organization->id)
            ->where('company_id', $company->id)
            ->first();

        if ($membership === null) return;

        $hasLocations = DB::table('organization_locations')
            ->where('organization_id', $membership->company_node_id)
            ->exists();

        if ($hasLocations) {
            throw new RuntimeException('Lokasyon bağlantısı olan şirket gruptan çıkarılamaz.');
        }

        DB::transaction(function () use ($membership, $organization, $company) {
            DB::table('company_brands')
                ->where('company_id', $company->id)
                ->whereNotNull('brand_node_id')
                ->pluck('brand_node_id')
                ->each(function ($nodeId) {
                    $hasLocations = DB::table('organization_locations')
                        ->where('organization_id', $nodeId)
                        ->exists();
                    if ($hasLocations) {
                        throw new RuntimeException('Lokasyon bağlantısı olan marka ilişkisi kaldırılamaz.');
                    }
                    Organization::query()->whereKey($nodeId)->delete();
                });

            DB::table('organization_companies')
                ->where('organization_id', $organization->id)
                ->where('company_id', $company->id)
                ->delete();

            Organization::query()->whereKey($membership->company_node_id)->delete();
        });
    }

    public function sync(Organization $organization, array $companyIds): void
    {
        $this->ensureGroup($organization);

        foreach (array_values(array_unique($companyIds)) as $companyId) {
            $company = Company::query()->findOrFail($companyId);
            $this->attach($organization, $company);
        }
    }

    private function ensureGroup(Organization $organization): void
    {
        if ($organization->type !== 'group') {
            throw new RuntimeException('Şirket yalnızca Grup tipindeki Organization ile eşleştirilebilir.');
        }
    }

    private function ensureSameTenant(Organization $organization, Company $company): void
    {
        if ($company->businessEntity?->tenant_id !== $organization->tenant_id) {
            throw new RuntimeException('Organization ve Company aynı tenant içerisinde olmalıdır.');
        }
    }
}
