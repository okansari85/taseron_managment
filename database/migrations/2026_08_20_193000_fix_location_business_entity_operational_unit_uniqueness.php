<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A location can contain multiple companies in the same operational unit.
        // The same company can only be registered once within the same unit.
        if ($this->indexExists(
            'location_business_entities',
            'location_business_entities_operational_unit_id_unique'
        )) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropUnique('location_business_entities_operational_unit_id_unique');
            });
        }

        if (! Schema::hasColumn('location_business_entities', 'operational_unit_key')) {
            DB::statement('
                ALTER TABLE location_business_entities
                ADD COLUMN operational_unit_key BIGINT
                GENERATED ALWAYS AS (COALESCE(operational_unit_id, 0)) STORED
                AFTER operational_unit_id
            ');
        }

        if (! $this->indexExists(
            'location_business_entities',
            'lbe_location_business_entity_operational_unit_unique'
        )) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->unique(
                    [
                        'location_id',
                        'business_entity_id',
                        'operational_unit_key',
                    ],
                    'lbe_location_business_entity_operational_unit_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists(
            'location_business_entities',
            'lbe_location_business_entity_operational_unit_unique'
        )) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropUnique('lbe_location_business_entity_operational_unit_unique');
            });
        }

        if (Schema::hasColumn('location_business_entities', 'operational_unit_key')) {
            DB::statement('
                ALTER TABLE location_business_entities
                DROP COLUMN operational_unit_key
            ');
        }

        if (! $this->indexExists(
            'location_business_entities',
            'location_business_entities_operational_unit_id_unique'
        )) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->unique('operational_unit_id');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }
};
