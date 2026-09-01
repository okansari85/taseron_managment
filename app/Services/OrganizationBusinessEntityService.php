<?php

namespace App\Services;

use App\Models\BusinessEntity;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrganizationBusinessEntityService
{
    public function allContractorsForTenant(): Collection
    {
        $contractors = BusinessEntity::query()
            ->where('type', 'contractor')
            ->whereHas('contractor')
            ->with('contractor')
            ->orderBy('name')
            ->get();

        if ($contractors->isEmpty()) {
            return $contractors;
        }

        $memberships = DB::table('organization_companies as oc')
            ->join('organizations as o', 'o.id', '=', 'oc.organization_id')
            ->whereIn('oc.business_entity_id', $contractors->pluck('id'))
            ->select('oc.business_entity_id', 'o.id', 'o.name', 'o.type', 'o.parent_id')
            ->orderBy('o.name')
            ->get()
            ->groupBy('business_entity_id');

        return $contractors->each(function (BusinessEntity $entity) use ($memberships) {
            $entity->setAttribute(
                'organizations',
                $memberships->get($entity->id, collect())->values()
            );
        });
    }

    public function attachBusinessEntity(Organization $organization, BusinessEntity $businessEntity): void
    {
        $this->ensureAttachableOrganization($organization);
        $this->ensureContractor($businessEntity);
        $this->ensureSameTenant($organization, $businessEntity);

        DB::transaction(function () use ($organization, $businessEntity) {
            $membership = DB::table('organization_companies')
                ->where('organization_id', $organization->id)
                ->where('business_entity_id', $businessEntity->id)
                ->lockForUpdate()
                ->first();

            if ($membership !== null) {
                return;
            }

            $node = Organization::query()->create([
                'tenant_id' => $organization->tenant_id,
                'parent_id' => $organization->id,
                'name' => $businessEntity->name,
                'type' => 'contractor',
            ]);

            DB::table('organization_companies')->insert([
                'organization_id' => $organization->id,
                'company_id' => null,
                'business_entity_id' => $businessEntity->id,
                'company_node_id' => null,
                'business_entity_node_id' => $node->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function detachBusinessEntity(Organization $organization, BusinessEntity $businessEntity): void
    {
        $this->ensureAttachableOrganization($organization);
        $this->ensureContractor($businessEntity);
        $this->ensureSameTenant($organization, $businessEntity);

        DB::transaction(function () use ($organization, $businessEntity) {
            $membership = DB::table('organization_companies')
                ->where('organization_id', $organization->id)
                ->where('business_entity_id', $businessEntity->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                return;
            }

            if ($membership->business_entity_node_id) {
                if (DB::table('organization_locations')
                    ->where('organization_id', $membership->business_entity_node_id)
                    ->exists()) {
                    throw new RuntimeException(
                        'Lokasyon bağlantısı bulunan alt yüklenici organizasyondan çıkarılamaz.'
                    );
                }

                Organization::query()->whereKey($membership->business_entity_node_id)->delete();
            }

            DB::table('organization_companies')
                ->where('id', $membership->id)
                ->delete();
        });
    }

    private function ensureAttachableOrganization(Organization $organization): void
    {
        if (! in_array($organization->type, ['holding', 'group'], true)) {
            throw new RuntimeException(
                'Alt yüklenici yalnızca Holding veya Grup tipindeki organizasyon ile eşleştirilebilir.'
            );
        }
    }

    private function ensureContractor(BusinessEntity $businessEntity): void
    {
        if ($businessEntity->type !== 'contractor' || ! $businessEntity->contractor()->exists()) {
            throw new RuntimeException('Seçilen BusinessEntity bir alt yüklenici değildir.');
        }
    }

    private function ensureSameTenant(Organization $organization, BusinessEntity $businessEntity): void
    {
        if ($businessEntity->tenant_id !== $organization->tenant_id) {
            throw new RuntimeException(
                'Organization ve alt yüklenici aynı tenant içerisinde olmalıdır.'
            );
        }
    }
}
