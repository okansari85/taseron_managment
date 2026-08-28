<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TenantDelete extends Command
{
    protected $signature = 'tenant:delete
                            {tenant : Tenant ID to delete}
                            {--force : Skip confirmation}';

    protected $description = 'Delete a tenant and all tenant-owned organization data from the database';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant');
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            $this->error("Tenant #{$tenantId} bulunamadı.");
            return self::FAILURE;
        }

        $summary = $this->collectSummary($tenantId);

        $this->warn("TENANT SİLİNECEK: #{$tenant->id} - {$tenant->name}");
        $this->table(['Veri', 'Adet'], $summary);

        if (! $this->option('force') && ! $this->confirm('Bu tenant ve bağlı tüm veriler kalıcı olarak silinsin mi?', false)) {
            $this->info('İşlem iptal edildi.');
            return self::SUCCESS;
        }

        $logoPath = $tenant->logo_path;
        $storagePaths = [];

        try {
            DB::transaction(function () use ($tenantId, &$storagePaths): void {
                $organizationIds = DB::table('organizations')
                    ->where('tenant_id', $tenantId)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();

                $brandIds = DB::table('brands')
                    ->where('tenant_id', $tenantId)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();

                $locationIds = DB::table('locations')
                    ->where('tenant_id', $tenantId)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();

                $businessEntityIds = DB::table('business_entities')
                    ->where('tenant_id', $tenantId)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();

                $companyIds = $businessEntityIds === []
                    ? []
                    : DB::table('companies')
                        ->whereIn('business_entity_id', $businessEntityIds)
                        ->pluck('id')->map(fn ($id) => (int) $id)->all();

                $contractorIds = $businessEntityIds !== [] && $this->tableExists('contractors')
                    ? DB::table('contractors')->whereIn('business_entity_id', $businessEntityIds)->pluck('id')->map(fn ($id) => (int) $id)->all()
                    : [];

                $operationalRegionIds = $locationIds !== [] && $this->tableExists('operational_regions')
                    ? DB::table('operational_regions')->whereIn('location_id', $locationIds)->pluck('id')->map(fn ($id) => (int) $id)->all()
                    : [];

                // Remove all known relationship/pivot rows first.
                $this->deleteByIds('organization_locations', 'organization_id', $organizationIds);
                $this->deleteByIds('organization_locations', 'location_id', $locationIds);
                $this->deleteByIds('organization_companies', 'organization_id', $organizationIds);
                $this->deleteByIds('organization_companies', 'company_id', $companyIds);
                $this->deleteByIds('company_brands', 'company_id', $companyIds);
                $this->deleteByIds('company_brands', 'brand_id', $brandIds);
                $this->deleteByIds('location_business_entities', 'location_id', $locationIds);
                $this->deleteByIds('location_business_entities', 'business_entity_id', $businessEntityIds);
                $this->deleteByIds('user_organizations', 'organization_id', $organizationIds);

                foreach (['brand_locations', 'company_users', 'company_location_users'] as $table) {
                    if (! $this->tableExists($table)) {
                        continue;
                    }

                    $this->deleteByIds($table, 'brand_id', $brandIds);
                    $this->deleteByIds($table, 'company_id', $companyIds);
                    $this->deleteByIds($table, 'location_id', $locationIds);
                }

                // Delete child records that are tenant-owned.
                foreach (['operational_regions' => $operationalRegionIds, 'contractors' => $contractorIds, 'companies' => $companyIds] as $table => $ids) {
                    if ($ids !== [] && $this->tableExists($table)) {
                        DB::table($table)->whereIn('id', $ids)->delete();
                    }
                }

                if ($organizationIds !== []) {
                    DB::table('organizations')->whereIn('id', $organizationIds)->delete();
                }

                if ($brandIds !== []) {
                    DB::table('brands')->whereIn('id', $brandIds)->delete();
                }

                if ($locationIds !== []) {
                    DB::table('locations')->whereIn('id', $locationIds)->delete();
                }

                if ($businessEntityIds !== []) {
                    DB::table('business_entities')->whereIn('id', $businessEntityIds)->delete();
                }

                DB::table('tenants')->where('id', $tenantId)->delete();
            });
        } catch (Throwable $exception) {
            $this->error('Tenant silme başarısız: '.$exception->getMessage());
            return self::FAILURE;
        }

        if ($logoPath) {
            Storage::disk('public')->delete($logoPath);
        }

        $this->info("Tenant #{$tenantId} başarıyla silindi.");
        return self::SUCCESS;
    }

    private function collectSummary(int $tenantId): array
    {
        $businessEntityIds = DB::table('business_entities')->where('tenant_id', $tenantId)->pluck('id');

        return [
            ['Tenants', DB::table('tenants')->where('id', $tenantId)->count()],
            ['Organizations', DB::table('organizations')->where('tenant_id', $tenantId)->count()],
            ['Brands', DB::table('brands')->where('tenant_id', $tenantId)->count()],
            ['Locations', DB::table('locations')->where('tenant_id', $tenantId)->count()],
            ['Business entities', $businessEntityIds->count()],
            ['Companies', DB::table('companies')->whereIn('business_entity_id', $businessEntityIds)->count()],
            ['Contractors', $this->tableExists('contractors') ? DB::table('contractors')->whereIn('business_entity_id', $businessEntityIds)->count() : 0],
        ];
    }

    private function deleteByIds(string $table, string $column, array $ids): void
    {
        if ($ids === [] || ! $this->tableExists($table) || ! $this->columnExists($table, $column)) {
            return;
        }

        DB::table($table)->whereIn($column, $ids)->delete();
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, $column);
    }
}
