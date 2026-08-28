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
            $table->enum('type', ['holding', 'group', 'company', 'brand'])
                ->nullable()
                ->change();
        });

        Schema::table('organization_companies', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('organization_id');
            $table->foreignId('company_node_id')->nullable()->after('company_id');
        });

        DB::statement(<<<'SQL'
            UPDATE organization_companies oc
            INNER JOIN companies c ON c.business_entity_id = oc.business_entity_id
            SET oc.company_id = c.id
        SQL);

        $unmapped = DB::table('organization_companies')
            ->whereNull('company_id')
            ->exists();

        if ($unmapped) {
            throw new RuntimeException('Organization şirket ilişkisinin Company karşılığı bulunamadı.');
        }

        Schema::table('organization_companies', function (Blueprint $table) {
            $table->dropForeign(['business_entity_id']);
            $table->dropUnique('organization_companies_business_entity_id_unique');
            $table->dropColumn('business_entity_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('company_node_id')->references('id')->on('organizations')->nullOnDelete();
            $table->unique('company_id', 'organization_companies_company_id_unique');
        });

        Schema::table('company_brands', function (Blueprint $table) {
            $table->foreignId('brand_node_id')->nullable()->after('brand_id');
            $table->foreign('brand_node_id')->references('id')->on('organizations')->nullOnDelete();
        });

        $now = now();
        $memberships = DB::table('organization_companies')
            ->join('companies', 'companies.id', '=', 'organization_companies.company_id')
            ->join('organizations as groups', 'groups.id', '=', 'organization_companies.organization_id')
            ->select('organization_companies.id', 'organization_companies.organization_id', 'organization_companies.company_id', 'companies.name', 'groups.tenant_id')
            ->orderBy('organization_companies.id')
            ->get();

        foreach ($memberships as $membership) {
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
            ->select('company_brands.company_id', 'company_brands.brand_id', 'brands.name', 'organization_companies.company_node_id', 'brands.tenant_id')
            ->get();

        foreach ($brandLinks as $brandLink) {
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

    public function down(): void
    {
        throw new RuntimeException('Bu migration, mevcut hiyerarşi düğümlerini korumak için geri alınmamalıdır.');
    }
};
