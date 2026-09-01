<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The previous experiment stored Contractor memberships in organization_companies.
        // Remove only those rows; existing Company memberships are left untouched.
        DB::table('organization_companies')
            ->whereNull('company_id')
            ->delete();

        if (Schema::hasColumn('organization_companies', 'business_entity_node_id')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->dropForeign(['business_entity_node_id']);
            });
        }

        if (Schema::hasColumn('organization_companies', 'business_entity_id')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->dropUnique('organization_companies_org_business_entity_unique');
                $table->dropForeign(['business_entity_id']);
                $table->dropColumn(['business_entity_node_id', 'business_entity_id']);
            });
        }

        Schema::table('organization_companies', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });

        // Contractor was never a valid Organization node in the intended model.
        DB::table('organizations')
            ->where('type', 'contractor')
            ->delete();

        DB::statement("ALTER TABLE organizations MODIFY type ENUM('holding','group','company','brand') NOT NULL");
    }

    public function down(): void
    {
        // Intentionally do not recreate the removed experimental BusinessEntity columns.
        DB::statement("ALTER TABLE organizations MODIFY type ENUM('holding','group','company','brand','contractor') NOT NULL");

        Schema::table('organization_companies', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
        });
    }
};
