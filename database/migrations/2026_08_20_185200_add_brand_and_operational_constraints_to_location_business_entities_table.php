<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('location_business_entities', 'brand_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->foreignId('brand_id')
                    ->nullable()
                    ->after('business_entity_id');
            });
        }

        // Existing records were created before brand_id existed. Preserve valid values
        // and clear only invalid references before creating the foreign key.
        DB::statement('
            UPDATE location_business_entities lbe
            LEFT JOIN brands b ON b.id = lbe.brand_id
            SET lbe.brand_id = NULL
            WHERE lbe.brand_id IS NOT NULL
              AND b.id IS NULL
        ');

        if (! $this->foreignKeyExists(
            'location_business_entities',
            'location_business_entities_brand_id_foreign'
        )) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->foreign('brand_id')
                    ->references('id')
                    ->on('brands')
                    ->restrictOnDelete();
            });
        }

        if ($this->indexOrUniqueExists(
            'location_business_entities',
            'location_business_entities_location_id_business_entity_id_unique'
        )) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropUnique(
                    'location_business_entities_location_id_business_entity_id_unique'
                );
            });
        }

        if (! $this->indexOrUniqueExists(
            'location_business_entities',
            'location_business_entities_operational_unit_id_unique'
        )) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                // One operational unit represents one company + brand assignment.
                // Multiple NULL values remain allowed for rows without an operational unit.
                $table->unique('operational_unit_id');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexOrUniqueExists(
            'location_business_entities',
            'location_business_entities_operational_unit_id_unique'
        )) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropUnique('location_business_entities_operational_unit_id_unique');
            });
        }

        if ($this->foreignKeyExists(
            'location_business_entities',
            'location_business_entities_brand_id_foreign'
        )) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropForeign('location_business_entities_brand_id_foreign');
            });
        }

        if (Schema::hasColumn('location_business_entities', 'brand_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropColumn('brand_id');
            });
        }

        if (! $this->indexOrUniqueExists(
            'location_business_entities',
            'location_business_entities_location_id_business_entity_id_unique'
        )) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->unique([
                    'location_id',
                    'business_entity_id',
                ]);
            });
        }
    }

    private function foreignKeyExists(string $table, string $foreignName): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $foreignName)
            ->exists();
    }

    private function indexOrUniqueExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }
};
