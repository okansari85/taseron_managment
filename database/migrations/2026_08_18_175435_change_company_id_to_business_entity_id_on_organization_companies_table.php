<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_companies', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['company_id']);
            $table->dropUnique([
                'organization_id',
                'company_id',
            ]);

            $table->dropColumn('company_id');

            $table->foreignId('business_entity_id')
                ->after('organization_id')
                ->constrained('business_entities')
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->unique([
                'organization_id',
                'business_entity_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('organization_companies', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['business_entity_id']);

            $table->dropUnique([
                'organization_id',
                'business_entity_id',
            ]);

            $table->dropColumn('business_entity_id');

            $table->foreignId('company_id')
                ->after('organization_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->unique([
                'organization_id',
                'company_id',
            ]);
        });
    }
};
