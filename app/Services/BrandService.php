<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BrandService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<int, int|string> $companyIds
     */
    private function syncCompanies(Brand $brand, array $companyIds): void
    {
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

        $groupRows = DB::table('organization_companies as oc')
            ->join('organizations as o', 'o.id', '=', 'oc.organization_id')
            ->whereIn('oc.company_id', $ids)
            ->where('o.tenant_id', $this->tenantContext->id())
            ->where('o.type', 'group')
            ->select('oc.company_id', 'oc.organization_id')
            ->get();

        $groupIds = $groupRows->pluck('organization_id')->unique()->values();
        $companiesWithGroup = $groupRows->pluck('company_id')->unique()->values();

        if (
            $ids !== []
            && ($groupIds->count() !== 1 || $companiesWithGroup->count() !== count($ids))
        ) {
            throw new RuntimeException(
                'Bir marka yalnızca aynı grup içerisindeki şirketlere bağlanabilir.'
            );
        }

        $existing = DB::table('company_brands')
            ->where('brand_id', $brand->id)
            ->get()
            ->keyBy('company_id');

        foreach ($existing->keys()->diff($validIds) as $companyId) {
            $nodeId = $existing[$companyId]->brand_node_id;

            if ($nodeId && DB::table('organization_locations')->where('organization_id', $nodeId)->exists()) {
                throw new RuntimeException('Lokasyon bağlantısı olan marka ilişkisi kaldırılamaz.');
            }

            if ($nodeId) {
                Organization::query()->whereKey($nodeId)->delete();
            }

            DB::table('company_brands')
                ->where('company_id', $companyId)
                ->where('brand_id', $brand->id)
                ->delete();
        }

        foreach ($validIds as $companyId) {
            $current = $existing->get($companyId);
            if ($current?->brand_node_id) {
                continue;
            }

            $companyNodeId = DB::table('organization_companies')
                ->where('company_id', $companyId)
                ->value('company_node_id');

            if (! $companyNodeId) {
                throw new RuntimeException('Marka eklenmeden önce şirket bir gruba bağlanmalıdır.');
            }

            $companyNode = Organization::query()->findOrFail($companyNodeId);
            $brandNode = Organization::query()->create([
                'tenant_id' => $companyNode->tenant_id,
                'parent_id' => $companyNode->id,
                'name' => $brand->name,
                'type' => 'brand',
            ]);

            DB::table('company_brands')->updateOrInsert(
                ['company_id' => $companyId, 'brand_id' => $brand->id],
                ['brand_node_id' => $brandNode->id, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
