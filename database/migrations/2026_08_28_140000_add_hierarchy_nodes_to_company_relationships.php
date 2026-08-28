<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->enum('type', ['holding', 'group', 'company', 'brand', 'location'])
                ->nullable()
                ->change();
        });

        if (! Schema::hasColumn('organization_companies', 'company_id')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('organization_id');
            });
        }

        if (! Schema::hasColumn('organization_companies', 'company_node_id')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->foreignId('company_node_id')->nullable()->after('company_id');
            });
        }

        if (Schema::hasColumn('organization_companies', 'business_entity_id')) {
            if (! Schema::hasColumn('companies', 'business_entity_id')) {
                throw new \RuntimeException(
                    'organization_companies.business_entity_id bulundu ancak companies.business_entity_id bulunamadı; güvenli Company eşlemesi yapılamıyor.'
                );
            }

            DB::statement(<<<'SQL'
                UPDATE organization_companies oc
                INNER JOIN companies c ON c.business_entity_id = oc.business_entity_id
                SET oc.company_id = c.id
                WHERE oc.company_id IS NULL
            SQL);

            $unmapped = DB::table('organization_companies')
                ->whereNull('company_id')
                ->pluck('id');

            if ($unmapped->isNotEmpty()) {
                throw new \RuntimeException(
                    'Organization şirket ilişkisinin Company karşılığı bulunamadı. Kayıt ID: ' . $unmapped->implode(', ')
                );
            }
        }

        $duplicates = DB::table('organization_companies')
            ->select('company_id')
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('company_id');

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Birden fazla gruba bağlı şirket bulundu. Company ID: ' . $duplicates->implode(', ')
            );
        }

        if (Schema::hasColumn('organization_companies', 'business_entity_id')) {
            if ($this->foreignKeyExists('organization_companies', 'business_entity_id')) {
                Schema::table('organization_companies', function (Blueprint $table) {
                    $table->dropForeign(['business_entity_id']);
                });
            }

            if ($this->indexExists('organization_companies', 'organization_companies_business_entity_id_unique')) {
                Schema::table('organization_companies', function (Blueprint $table) {
                    $table->dropUnique('organization_companies_business_entity_id_unique');
                });
            }

            Schema::table('organization_companies', function (Blueprint $table) {
                $table->dropColumn('business_entity_id');
            });
        }

        if (! $this->foreignKeyExists('organization_companies', 'company_id')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->foreign('company_id')
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->foreignKeyExists('organization_companies', 'company_node_id')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->foreign('company_node_id')
                    ->references('id')
                    ->on('organizations')
                    ->nullOnDelete();
            });
        }

        if (! $this->indexExists('organization_companies', 'organization_companies_company_id_unique')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->unique('company_id', 'organization_companies_company_id_unique');
            });
        }

        if (! Schema::hasColumn('company_brands', 'brand_node_id')) {
            Schema::table('company_brands', function (Blueprint $table) {
                $table->foreignId('brand_node_id')->nullable()->after('brand_id');
            });
        }

        if (! $this->foreignKeyExists('company_brands', 'brand_node_id')) {
            Schema::table('company_brands', function (Blueprint $table) {
                $table->foreign('brand_node_id')
                    ->references('id')
                    ->on('organizations')
                    ->nullOnDelete();
            });
        }

        $now = now();

        $memberships = DB::table('organization_companies')
            ->join('companies', 'companies.id', '=', 'organization_companies.company_id')
            ->join('organizations as groups', 'groups.id', '=', 'organization_companies.organization_id')
            ->whereNull('organization_companies.company_node_id')
            ->select(
                'organization_companies.id',
                'organization_companies.organization_id',
                'organization_companies.company_id',
                'companies.name',
                'groups.tenant_id',
                'groups.type as organization_type'
            )
            ->orderBy('organization_companies.id')
            ->get();

        foreach ($memberships as $membership) {
            if ($membership->organization_type !== 'group') {
                throw new \RuntimeException(
                    'Şirket ilişkisi Grup tipinde olmayan bir Organization altında bulundu. Organization ID: ' . $membership->organization_id
                );
            }

            $nodeId = DB::table('organizations')->insertGetId([
                'tenant_id' => $membership->tenant_id,
                'parent_id' => $membership->organization_id,
                'name' => $membership->name,
                'type' => 'company',
                'display_order' => 0,
                'is_active' => true,
                'color' => '#465FFF',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('organization_companies')
                ->where('id', $membership->id)
                ->update(['company_node_id' => $nodeId]);
        }

        $brandLinks = DB::table('company_brands')
            ->join('brands', 'brands.id', '=', 'company_brands.brand_id')
            ->join('organization_companies', 'organization_companies.company_id', '=', 'company_brands.company_id')
            ->whereNull('company_brands.brand_node_id')
            ->select(
                'company_brands.company_id',
                'company_brands.brand_id',
                'brands.name',
                'brands.tenant_id',
                'organization_companies.company_node_id'
            )
            ->orderBy('company_brands.company_id')
            ->orderBy('company_brands.brand_id')
            ->get();

        foreach ($brandLinks as $brandLink) {
            if (! $brandLink->company_node_id) {
                throw new \RuntimeException(
                    'Marka ilişkisi için Company node bulunamadı. Company ID: ' . $brandLink->company_id . ', Brand ID: ' . $brandLink->brand_id
                );
            }

            $nodeId = DB::table('organizations')->insertGetId([
                'tenant_id' => $brandLink->tenant_id,
                'parent_id' => $brandLink->company_node_id,
                'name' => $brandLink->name,
                'type' => 'brand',
                'display_order' => 0,
                'is_active' => true,
                'color' => '#465FFF',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('company_brands')
                ->where('company_id', $brandLink->company_id)
                ->where('brand_id', $brandLink->brand_id)
                ->update(['brand_node_id' => $nodeId]);
        }
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    public function down(): void
    {
        throw new \RuntimeException('Bu migration, mevcut hiyerarşi düğümlerini korumak için geri alınmamalıdır.');
    }
};
