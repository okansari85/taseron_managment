<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Organization;
use App\Repositories\Contracts\BrandRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Throwable;

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
            throw new LogicException('Tenant context has not been initialized.');
        }

        $newLogoPath = null;

        try {
            return DB::transaction(function () use ($data, &$newLogoPath) {
                if (($data['logo'] ?? null) instanceof UploadedFile) {
                    $newLogoPath = $data['logo']->store('brand-logos', 'public');
                    $data['logo_path'] = $newLogoPath;
                }

                unset($data['logo']);

                $brand = $this->repository->create([
                    'tenant_id' => $this->tenantContext->id(),
                    'name' => $data['name'],
                    'short_name' => $data['short_name'] ?? null,
                    'description' => $data['description'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'logo_path' => $data['logo_path'] ?? null,
                ]);

                $this->syncCompanies($brand, $data['company_ids'] ?? []);

                return $brand->load('companies.organizations');
            });
        } catch (Throwable $exception) {
            if ($newLogoPath !== null) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $exception;
        }
    }

    public function update(Brand $brand, array $data): Brand
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $oldLogoPath = $brand->logo_path;
        $newLogoPath = null;

        try {
            $updatedBrand = DB::transaction(function () use ($brand, $data, &$newLogoPath) {
                $tenantId = $this->tenantContext->id();

                if ($brand->tenant_id !== $tenantId) {
                    throw new RuntimeException('Bu markaya erişim yetkiniz yok.');
                }

                if (($data['logo'] ?? null) instanceof UploadedFile) {
                    $newLogoPath = $data['logo']->store('brand-logos', 'public');
                    $data['logo_path'] = $newLogoPath;
                }

                unset($data['tenant_id'], $data['logo']);

                $companyIdsProvided = array_key_exists('company_ids', $data);
                $companyIds = $data['company_ids'] ?? [];
                unset($data['company_ids']);

                $brand = $this->repository->update($brand, $data);

                if ($companyIdsProvided) {
                    $this->syncCompanies($brand, $companyIds);
                }

                Organization::query()
                    ->whereIn('id', DB::table('company_brands')
                        ->where('brand_id', $brand->id)
                        ->whereNotNull('brand_node_id')
                        ->pluck('brand_node_id'))
                    ->update(['name' => $brand->name]);

                return $brand->load('companies.organizations');
            });

            if ($newLogoPath !== null && $oldLogoPath && $oldLogoPath !== $newLogoPath) {
                Storage::disk('public')->delete($oldLogoPath);
            }

            return $updatedBrand;
        } catch (Throwable $exception) {
            if ($newLogoPath !== null) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $exception;
        }
    }

    public function delete(Brand $brand): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $logoPath = $brand->logo_path;

        $brandNodeIds = DB::table('company_brands')
            ->where('brand_id', $brand->id)
            ->whereNotNull('brand_node_id')
            ->pluck('brand_node_id');

        if (DB::table('organization_locations')
            ->whereIn('organization_id', $brandNodeIds)
            ->exists()) {
            throw new RuntimeException('Lokasyon bağlantısı olan marka silinemez.');
        }

        DB::transaction(function () use ($brand, $brandNodeIds) {
            if ($brand->tenant_id !== $this->tenantContext->id()) {
                throw new RuntimeException('Bu markaya erişim yetkiniz yok.');
            }

            Organization::query()->whereIn('id', $brandNodeIds)->delete();
            $this->repository->delete($brand);
        });

        if ($logoPath) {
            Storage::disk('public')->delete($logoPath);
        }
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
            throw new RuntimeException('Seçilen şirketlerden biri bu tenant içerisinde bulunamadı.');
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
            throw new RuntimeException('Bir marka yalnızca aynı grup içerisindeki şirketlere bağlanabilir.');
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
