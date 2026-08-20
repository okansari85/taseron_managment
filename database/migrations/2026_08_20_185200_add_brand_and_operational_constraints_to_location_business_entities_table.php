<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            if (! Schema::hasColumn('location_business_entities', 'brand_id')) {
                $table->foreignId('brand_id')
                    ->nullable()
                    ->after('business_entity_id');
            }
        });

        // Existing location_business_entities rows were created before brand_id existed.
        // Clear only invalid brand references before adding the foreign key.
        DB::statement('
            UPDATE location_business_entities lbe
            LEFT JOIN brands b ON b.id = lbe.brand_id
            SET lbe.brand_id = NULL
            WHERE lbe.brand_id IS NOT NULL
              AND b.id IS NULL
        ');

        Schema::table('location_business_entities', function (Blueprint $table) {
            if (! $this->foreignKeyExists('location_business_entities', 'location_business_entities_brand_id_foreign')) {
                $table->foreign('brand_id')
                    ->references('id')
                    ->on('brands')
                    ->restrictOnDelete();
            }

            $this->dropUniqueIfExists(
                $table,
                'location_business_entities',
                'location_business_entities_location_id_business_entity_id_unique'
            );

            if (! $this->indexOrUniqueExists(
                'location_business_entities',
                'location_business_entities_operational_unit_id_unique'
            )) {
                // An operational unit can represent only one company + brand assignment.
                // Multiple NULL values remain allowed for location-level assignments.
                $table->unique('operational_unit_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $this->dropUniqueIfExists(
                $table,
                'location_business_entities',
                'location_business_entities_operational_unit_id_unique'
            );

            if ($this->foreignKeyExists('location_business_entities', 'location_business_entities_brand_id_foreign')) {
                $table->dropForeign('location_business_entities_brand_id_foreign');
            }

            if (Schema::hasColumn('location_business_entities', 'brand_id')) {
                $table->dropColumn('brand_id');
            }

            if (! $this->indexOrUniqueExists(
                'location_business_entities',
                'location_business_entities_location_id_business_entity_id_unique'
            )) {
                $table->unique([
                    'location_id',
                    'business_entity_id',
                ]);
            }
        });
    }

    private function foreignKeyExists(string $table, string $foreignName): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $foreignName)
            ->exists();
    }

    private function indexOrUniqueExists(string $table, string $indexName): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    private function dropUniqueIfExists(
        Blueprint $table,
        string $tableName,
        string $indexName
    ): void {
        if ($this->indexOrUniqueExists($tableName, $indexName)) {
            $table->dropUnique($indexName);
        }
    }
};
