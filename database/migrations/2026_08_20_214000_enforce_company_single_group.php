<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company can belong to exactly one Organization Group.
        // Clean duplicate legacy rows first, keeping the oldest membership.
        DB::statement(<<<'SQL'
            DELETE oc1
            FROM organization_companies oc1
            INNER JOIN organization_companies oc2
                ON oc2.business_entity_id = oc1.business_entity_id
               AND (
                    oc2.organization_id < oc1.organization_id
                    OR (
                        oc2.organization_id = oc1.organization_id
                        AND oc2.id < oc1.id
                    )
               )
        SQL);

        $indexes = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'organization_companies')
            ->pluck('INDEX_NAME')
            ->unique()
            ->values();

        if ($indexes->contains('organization_companies_organization_id_business_entity_id_unique')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->dropUnique(
                    'organization_companies_organization_id_business_entity_id_unique'
                );
            });
        }

        if (! $indexes->contains('organization_companies_business_entity_id_unique')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->unique(
                    'business_entity_id',
                    'organization_companies_business_entity_id_unique'
                );
            });
        }
    }

    public function down(): void
    {
        $indexes = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'organization_companies')
            ->pluck('INDEX_NAME')
            ->unique()
            ->values();

        if ($indexes->contains('organization_companies_business_entity_id_unique')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->dropUnique(
                    'organization_companies_business_entity_id_unique'
                );
            });
        }

        if (! $indexes->contains('organization_companies_organization_id_business_entity_id_unique')) {
            Schema::table('organization_companies', function (Blueprint $table) {
                $table->unique(
                    ['organization_id', 'business_entity_id'],
                    'organization_companies_organization_id_business_entity_id_unique'
                );
            });
        }
    }
};
