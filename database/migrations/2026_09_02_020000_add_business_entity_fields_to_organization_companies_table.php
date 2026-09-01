<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_companies', function (Blueprint $table) {
            $table->foreignId('business_entity_id')
                ->nullable()
                ->after('company_id')
                ->constrained('business_entities')
                ->nullOnDelete();

            $table->foreignId('business_entity_node_id')
                ->nullable()
                ->after('company_node_id');
        });

        DB::statement(<<<'SQL'
            UPDATE organization_companies oc
            INNER JOIN companies c ON c.id = oc.company_id
            SET oc.business_entity_id = c.business_entity_id
            WHERE oc.business_entity_id IS NULL
        SQL);

        Schema::table('organization_companies', function (Blueprint $table) {
            $table->foreign('business_entity_node_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->unique(
                ['organization_id', 'business_entity_id'],
                'organization_companies_org_business_entity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('organization_companies', function (Blueprint $table) {
            $table->dropUnique('organization_companies_org_business_entity_unique');
            $table->dropForeign(['business_entity_node_id']);
            $table->dropForeign(['business_entity_id']);
            $table->dropColumn(['business_entity_node_id', 'business_entity_id']);
        });
    }
};
