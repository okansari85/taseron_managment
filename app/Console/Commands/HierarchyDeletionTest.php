<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Location;
use App\Models\Organization;
use App\Services\BrandService;
use App\Services\CompanyService;
use App\Services\OrganizationLocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class HierarchyDeletionTest extends Command
{
    protected $signature = 'hierarchy:test-deletion';

    protected $description = 'Test hierarchy deletion guards and cleanup without persisting changes';

    public function handle(): int
    {
        $membership = DB::table('organization_companies')
            ->whereNotNull('company_node_id')
            ->orderBy('id')
            ->first();

        if (! $membership) {
            $this->warn('SKIP: no company membership with a company node is available.');
            return self::SUCCESS;
        }

        $company = Company::query()->findOrFail($membership->company_id);
        $companyNode = Organization::query()->findOrFail($membership->company_node_id);
        $brandLink = DB::table('company_brands')
            ->where('company_id', $company->id)
            ->whereNotNull('brand_node_id')
            ->orderBy('brand_id')
            ->first();

        $brand = $brandLink ? Brand::query()->find($brandLink->brand_id) : null;
        $brandNode = $brandLink?->brand_node_id ? Organization::query()->find($brandLink->brand_node_id) : null;

        $location = Location::query()
            ->where('tenant_id', $company->businessEntity?->tenant_id)
            ->whereNotExists(function ($query) use ($companyNode) {
                $query->select(DB::raw(1))
                    ->from('organization_locations')
                    ->whereColumn('organization_locations.location_id', 'locations.id')
                    ->where('organization_locations.organization_id', $companyNode->id);
            })
            ->when($brandNode, function ($query) use ($brandNode) {
                $query->whereNotExists(function ($subQuery) use ($brandNode) {
                    $subQuery->select(DB::raw(1))
                        ->from('organization_locations')
                        ->whereColumn('organization_locations.location_id', 'locations.id')
                        ->where('organization_locations.organization_id', $brandNode->id);
                });
            })
            ->orderBy('id')
            ->first();

        if (! $location) {
            $this->warn('SKIP: no reusable location is available for deletion tests.');
            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            $organizationLocationService = app(OrganizationLocationService::class);
            $companyService = app(CompanyService::class);
            $brandService = app(BrandService::class);

            $organizationLocationService->attach($companyNode, $location);

            try {
                $companyService->delete($company);
                throw new \RuntimeException('FAIL: company deletion was allowed while a company-node location was attached.');
            } catch (LogicException $exception) {
                $this->info('PASS: company deletion blocked by company-node location dependency.');
            }

            $organizationLocationService->detach($companyNode, $location);

            if ($brand && $brandNode) {
                $organizationLocationService->attach($brandNode, $location);

                $currentCompanyIds = DB::table('company_brands')
                    ->where('brand_id', $brand->id)
                    ->pluck('company_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $remainingCompanyIds = array_values(array_filter(
                    $currentCompanyIds,
                    fn (int $id) => $id !== (int) $company->id
                ));

                try {
                    $brandService->update($brand, ['company_ids' => $remainingCompanyIds]);
                    throw new \RuntimeException('FAIL: brand-company relationship removal was allowed while its brand node had a location.');
                } catch (Throwable $exception) {
                    if ($exception instanceof \RuntimeException && str_starts_with($exception->getMessage(), 'FAIL:')) {
                        throw $exception;
                    }
                    $this->info('PASS: brand-company relationship removal blocked by brand-node location dependency.');
                }

                $organizationLocationService->detach($brandNode, $location);
            }

            $companyNodeId = (int) $companyNode->id;
            $brandNodeIds = DB::table('company_brands')
                ->where('company_id', $company->id)
                ->whereNotNull('brand_node_id')
                ->pluck('brand_node_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $brandId = $brand?->id;

            $companyService->delete($company);

            if (Organization::query()->whereKey($companyNodeId)->exists()) {
                throw new \RuntimeException('FAIL: company node survived a successful company deletion.');
            }

            if ($brandNodeIds && Organization::query()->whereIn('id', $brandNodeIds)->exists()) {
                throw new \RuntimeException('FAIL: a company-owned brand node survived company deletion.');
            }

            if ($brandId !== null && ! DB::table('brands')->where('id', $brandId)->exists()) {
                throw new \RuntimeException('FAIL: deleting a company deleted the real Brand record.');
            }

            $this->info('PASS: company deletion cleans only its company/brand relationship nodes; real Brand survives.');

            DB::rollBack();
            $this->info('PASS: deletion test completed and every mutation was rolled back.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
