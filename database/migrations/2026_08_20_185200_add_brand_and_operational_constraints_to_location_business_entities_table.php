<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy location-business assignments are disposable during this schema refactor.
        DB::table('location_business_entities')->delete();

        if (! Schema::hasColumn('location_business_entities', 'brand_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->foreignId('brand_id')
                    ->nullable()
                    ->after('business_entity_id');
            });
        }

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
                $table->dropUnique('location_business_entities_location_id_business_entity_id_unique');
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
