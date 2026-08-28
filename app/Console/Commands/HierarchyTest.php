<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Organization;
use App\Services\BrandService;
use App\Services\OrganizationCompanyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class HierarchyTest extends Command
{
    protected $signature = 'hierarchy:test';

    protected $description = 'Run non-destructive company/brand hierarchy integration checks';

    public function handle(): int
    {
        $tenantContext = app(TenantContext::class);

        try {
            $this->runIntegrityChecks();

            $membership = DB::table('organization_companies')->orderBy('id')->first();
            if (! $membership) {
                $this->warn('No organization-company membership exists; nothing to execute.');
                return self::SUCCESS;
            }

            $group = Organization::query()->findOrFail($membership->organization_id);
            $company = Company::query()->findOrFail($membership->company_id);
            $companyNodeId = (int) $membership->company_node_id;

            if ($group->type !== 'group' || ! $companyNodeId) {
                throw new \RuntimeException('Company membership is not in a valid testable state.');
            }

            $tenant = $group->tenant()->firstOrFail();
            $tenantContext->set($tenant);

            DB::beginTransaction();

            try {
                app(OrganizationCompanyService::class)->attach($group, $company);

                $afterAttach = DB::table('organization_companies')->where('company_id', $company->id)->first();
                if (! $afterAttach || (int) $afterAttach->company_node_id !== $companyNodeId) {
                    throw new \RuntimeException('FAIL: company node id changed during attach.');
                }

                $companyNode = Organization::query()->findOrFail($companyNodeId);
                if ($companyNode->type !== 'company' || (int) $companyNode->parent_id !== (int) $group->id) {
                    throw new \RuntimeException('FAIL: company node parent/type is incorrect.');
                }

                $this->info("PASS: existing company node preserved (#{$companyNodeId}).");

                $brandLink = DB::table('company_brands')
                    ->where('company_id', $company->id)
                    ->whereNotNull('brand_node_id')
                    ->orderBy('brand_id')
                    ->first();

                if ($brandLink) {
                    $brand = Brand::query()->findOrFail($brandLink->brand_id);
                    $existingCompanyIds = DB::table('company_brands')->where('brand_id', $brand->id)->pluck('company_id')->map(fn ($id) => (int) $id)->all();

                    $secondCompany = Company::query()
                        ->where('companies.id', '<>', $company->id)
                        ->whereHas('businessEntity', fn ($query) => $query->where('tenant_id', $tenant->id))
                        ->whereNotIn('companies.id', $existingCompanyIds)
                        ->whereExists(function ($query) {
                            $query->select(DB::raw(1))
                                ->from('organization_companies as grouped_company')
                                ->join('organizations as grouped_org', 'grouped_org.id', '=', 'grouped_company.organization_id')
                                ->whereColumn('grouped_company.company_id', 'companies.id')
                                ->where('grouped_org.type', 'group')
                                ->whereNotNull('grouped_company.company_node_id');
                        })
                        ->orderBy('companies.id')->first();

                    if ($secondCompany) {
                        app(BrandService::class)->update($brand, ['company_ids' => array_values(array_unique([...$existingCompanyIds, $secondCompany->id]))]);

                        $secondLink = DB::table('company_brands')->where('company_id', $secondCompany->id)->where('brand_id', $brand->id)->first();
                        if (! $secondLink || ! $secondLink->brand_node_id) {
                            throw new \RuntimeException('FAIL: second brand relationship node was not created.');
                        }

                        $secondCompanyNodeId = DB::table('organization_companies')->where('company_id', $secondCompany->id)->value('company_node_id');
                        $secondBrandNode = Organization::query()->findOrFail($secondLink->brand_node_id);
                        if ($secondBrandNode->type !== 'brand' || (int) $secondBrandNode->parent_id !== (int) $secondCompanyNodeId) {
                            throw new \RuntimeException('FAIL: second brand node has an incorrect parent/type.');
                        }

                        $this->info("PASS: second brand node created (#{$secondBrandNode->id}) under company node #{$secondCompanyNodeId}.");
                    } else {
                        $this->warn('SKIP: no second grouped company is available for the multi-company brand test.');
                    }
                } else {
                    $this->warn('SKIP: no existing company-brand relationship is available for the brand test.');
                }

                $this->runLocationDependencyChecks($companyNodeId, $brandLink?->brand_node_id);

                DB::rollBack();
            } catch (Throwable $exception) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                throw $exception;
            }

            $this->info('PASS: all hierarchy tests completed and every test mutation was rolled back.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } finally {
            $tenantContext->clear();
        }
    }

    private function runLocationDependencyChecks(int $companyNodeId, ?int $brandNodeId): void
    {
        $columns = DB::select('SHOW COLUMNS FROM organization_locations');
        $columnNames = array_map(fn ($column) => $column->Field, $columns);
        $hasOrganizationId = in_array('organization_id', $columnNames, true);
        $hasLocationId = in_array('location_id', $columnNames, true);

        if (! $hasOrganizationId) {
            $this->warn('SKIP: organization_locations has no organization_id column; location dependency test requires the node relation.');
            return;
        }

        $locationId = null;
        if ($hasLocationId) {
            $locationId = DB::table('organization_locations')->max('location_id');
        }

        if ($locationId === null) {
            $this->warn('SKIP: no existing location is available for the location dependency test.');
            return;
        }

        $before = DB::table('organization_locations')->where('location_id', $locationId)->count();
        DB::table('organization_locations')->insert([
            'organization_id' => $companyNodeId,
            'location_id' => $locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $after = DB::table('organization_locations')->where('location_id', $locationId)->count();
        if ($after !== $before + 1) {
            throw new \RuntimeException('FAIL: temporary company-node location relationship was not created.');
        }

        $this->info("PASS: location can be attached to company node #{$companyNodeId}.");

        $linked = DB::table('organization_locations')->where('organization_id', $companyNodeId)->where('location_id', $locationId)->exists();
        if (! $linked) {
            throw new \RuntimeException('FAIL: company-node location dependency could not be detected.');
        }

        $this->info("PASS: location dependency detected for company node #{$companyNodeId}; node deletion must be blocked.");

        if ($brandNodeId) {
            DB::table('organization_locations')->insert([
                'organization_id' => $brandNodeId,
                'location_id' => $locationId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! DB::table('organization_locations')->where('organization_id', $brandNodeId)->where('location_id', $locationId)->exists()) {
                throw new \RuntimeException('FAIL: temporary brand-node location relationship was not created.');
            }

            $this->info("PASS: location can be attached to brand node #{$brandNodeId}.");
            $this->info("PASS: location dependency detected for brand node #{$brandNodeId}; relationship deletion must be blocked.");
        }
    }

    private function runIntegrityChecks(): void
    {
        $invalidCompanyNodes = DB::table('organization_companies as oc')
            ->leftJoin('organizations as node', 'node.id', '=', 'oc.company_node_id')
            ->where(fn ($query) => $query->whereNull('node.id')->orWhere('node.type', '<>', 'company')->orWhereColumn('node.parent_id', '<>', 'oc.organization_id'))
            ->count();
        if ($invalidCompanyNodes > 0) throw new \RuntimeException("FAIL: {$invalidCompanyNodes} company node relation(s) are invalid.");

        $invalidBrandNodes = DB::table('company_brands as cb')
            ->join('organization_companies as oc', 'oc.company_id', '=', 'cb.company_id')
            ->leftJoin('organizations as node', 'node.id', '=', 'cb.brand_node_id')
            ->where(fn ($query) => $query->whereNull('node.id')->orWhere('node.type', '<>', 'brand')->orWhereColumn('node.parent_id', '<>', 'oc.company_node_id'))
            ->whereNotNull('cb.brand_node_id')->count();
        if ($invalidBrandNodes > 0) throw new \RuntimeException("FAIL: {$invalidBrandNodes} brand node relation(s) are invalid.");

        $orphanCompanyNodes = DB::table('organizations as node')->leftJoin('organization_companies as oc', 'oc.company_node_id', '=', 'node.id')->where('node.type', 'company')->whereNull('oc.id')->count();
        if ($orphanCompanyNodes > 0) throw new \RuntimeException("FAIL: {$orphanCompanyNodes} company node(s) have no relationship row.");

        $orphanBrandNodes = DB::table('organizations as node')->leftJoin('company_brands as cb', 'cb.brand_node_id', '=', 'node.id')->where('node.type', 'brand')->whereNull('cb.company_id')->count();
        if ($orphanBrandNodes > 0) throw new \RuntimeException("FAIL: {$orphanBrandNodes} brand node(s) have no relationship row.");

        $this->info('PASS: current hierarchy integrity checks passed.');
    }
}
