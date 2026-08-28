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
            // company_id is unique: a company can belong to only one group.
            // If it already belongs to another group, this is a MOVE, not a
            // delete/recreate operation. The existing company node is preserved.
            $membership = DB::table('organization_companies')
                ->where('company_id', $company->id)
                ->lockForUpdate()
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

            if (! $membership->company_node_id) {
                throw new RuntimeException(
                    'Şirket ilişkisi mevcut ancak company node bulunamadı. Veri kaybını önlemek için işlem durduruldu.'
                );
            }

            $node = Organization::query()->find($membership->company_node_id);

            if (! $node) {
                throw new RuntimeException(
                    'Company node bulunamadı. Veri kaybını önlemek için şirket yeniden oluşturulmadı.'
                );
            }

            if ($node->tenant_id !== $organization->tenant_id || $node->type !== 'company') {
                throw new RuntimeException(
                    'Mevcut company node geçersiz tenant veya tip bilgisine sahip.'
                );
            }

            // IMPORTANT: keep the same node id. Only the parent changes.
            $node->update([
                'parent_id' => $organization->id,
                'name' => $company->name,
            ]);

            DB::table('organization_companies')
                ->where('id', $membership->id)
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

        DB::transaction(function () use ($organization, $company) {
            $membership = DB::table('organization_companies')
                ->where('organization_id', $organization->id)
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                return;
            }

            if (! $membership->company_node_id) {
                throw new RuntimeException(
                    'Şirket ilişkisi company node olmadan bulundu. Veri kaybını önlemek için işlem durduruldu.'
                );
            }

            $companyNodeId = (int) $membership->company_node_id;

            $brandNodeIds = DB::table('company_brands')
                ->where('company_id', $company->id)
                ->whereNotNull('brand_node_id')
                ->pluck('brand_node_id')
                ->map(fn ($id) => (int) $id);

            // A company cannot leave its group while any of its relationship
            // nodes are used by locations. Otherwise those location links would
            // become invalid/orphaned.
            $locationNodeIds = collect([$companyNodeId])
                ->merge($brandNodeIds)
                ->unique()
                ->values();

            if (DB::table('organization_locations')
                ->whereIn('organization_id', $locationNodeIds)
                ->exists()) {
                throw new RuntimeException(
                    'Lokasyon bağlantısı olan şirket veya marka ilişkisi gruptan çıkarılamaz.'
                );
            }

            // Brand nodes belong to the company relationship. Remove only those
            // nodes and their relationship rows; never delete the real Brand.
            if ($brandNodeIds->isNotEmpty()) {
                Organization::query()->whereIn('id', $brandNodeIds)->delete();
                DB::table('company_brands')
                    ->where('company_id', $company->id)
                    ->whereIn('brand_node_id', $brandNodeIds)
                    ->update(['brand_node_id' => null]);
            }

            DB::table('organization_companies')
                ->where('id', $membership->id)
                ->delete();

            Organization::query()->whereKey($companyNodeId)->delete();
        });
    }

    public function sync(Organization $organization, array $companyIds): void
    {
        $this->ensureGroup($organization);

        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));

        $companies = Company::query()
            ->whereIn('id', $companyIds)
            ->get()
            ->keyBy('id');

        if ($companies->count() !== count($companyIds)) {
            throw new RuntimeException('Seçilen şirketlerden biri veya birkaçı bulunamadı.');
        }

        foreach ($companies as $company) {
            $this->ensureSameTenant($organization, $company);
        }

        DB::transaction(function () use ($organization, $companyIds) {
            // sync() is an exact synchronization for this group. Companies not
            // present in the requested list are detached, which also removes
            // their relationship nodes only after location checks pass.
            $currentCompanyIds = DB::table('organization_companies')
                ->where('organization_id', $organization->id)
                ->pluck('company_id')
                ->map(fn ($id) => (int) $id);

            foreach ($currentCompanyIds->diff($companyIds) as $companyId) {
                $company = Company::query()->findOrFail($companyId);
                $this->detach($organization, $company);
            }

            foreach ($companyIds as $companyId) {
                $company = Company::query()->findOrFail($companyId);
                $this->attach($organization, $company);
            }
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

    private function ensureSameTenant(Organization $organization, Company $company): void
    {
        if ($company->businessEntity?->tenant_id !== $organization->tenant_id) {
            throw new RuntimeException(
                'Organization ve Company aynı tenant içerisinde olmalıdır.'
            );
        }
    }
}
