<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('location_business_entities')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropForeign(['operational_unit_id']);
                $table->dropIndex(['location_id', 'operational_unit_id']);
            });
        }

        if (Schema::hasTable('operational_units') && ! Schema::hasTable('operational_regions')) {
            Schema::rename('operational_units', 'operational_regions');
        }

        if (Schema::hasColumn('location_business_entities', 'operational_unit_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->renameColumn('operational_unit_id', 'operational_region_id');
            });
        }

        if (Schema::hasTable('location_business_entities') && Schema::hasColumn('location_business_entities', 'operational_region_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->foreign('operational_region_id', 'lbe_operational_region_fk')
                    ->references('id')
                    ->on('operational_regions')
                    ->nullOnDelete();

                $table->index(
                    ['location_id', 'operational_region_id'],
                    'lbe_location_operational_region_index'
                );
            });
        }

        if (Schema::hasTable('location_business_entities')) {
            $this->renameUniqueIndexIfPresent(
                'lbe_location_business_entity_operational_unit_unique',
                'lbe_location_business_entity_operational_region_unique'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('location_business_entities') && Schema::hasColumn('location_business_entities', 'operational_region_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropForeign('lbe_operational_region_fk');
                $table->dropIndex('lbe_location_operational_region_index');
                $table->renameColumn('operational_region_id', 'operational_unit_id');
            });
        }

        if (Schema::hasTable('operational_regions') && ! Schema::hasTable('operational_units')) {
            Schema::rename('operational_regions', 'operational_units');
        }

        if (Schema::hasTable('location_business_entities') && Schema::hasColumn('location_business_entities', 'operational_unit_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->foreign('operational_unit_id')
                    ->references('id')
                    ->on('operational_units')
                    ->nullOnDelete();

                $table->index([
                    'location_id',
                    'operational_unit_id',
                ]);
            });
        }

        if (Schema::hasTable('location_business_entities')) {
            $this->renameUniqueIndexIfPresent(
                'lbe_location_business_entity_operational_region_unique',
                'lbe_location_business_entity_operational_unit_unique'
            );
        }
    }

    private function renameUniqueIndexIfPresent(string $from, string $to): void
    {
        $exists = \DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', \DB::getDatabaseName())
            ->where('TABLE_NAME', 'location_business_entities')
            ->where('INDEX_NAME', $from)
            ->exists();

        if ($exists) {
            \DB::statement("ALTER TABLE location_business_entities RENAME INDEX {$from} TO {$to}");
        }
    }
};
