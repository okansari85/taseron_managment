<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('location_business_entities', 'brand_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                // Legacy location-business records may already exist. Keep this
                // nullable at the database level for the migration; the
                // application will require brand_id for new assignments.
                $table->foreignId('brand_id')
                    ->nullable()
                    ->after('business_entity_id');
            });
        }

        // The previous location + business_entity uniqueness is no longer
        // sufficient because the same company can appear in different
        // operational contexts.
        $hasLegacyUnique = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'location_business_entities')
            ->where('index_name', 'location_business_entities_location_id_business_entity_id_unique')
            ->exists();

        if ($hasLegacyUnique) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropUnique([
                    'location_id',
                    'business_entity_id',
                ]);
            });
        }

        // Add the FK after the nullable column exists so existing rows remain
        // valid during the migration.
        $hasBrandForeignKey = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'location_business_entities')
            ->where('constraint_name', 'location_business_entities_brand_id_foreign')
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();

        if (! $hasBrandForeignKey) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->foreign('brand_id')
                    ->references('id')
                    ->on('brands')
                    ->restrictOnDelete();
            });
        }

        // One operational unit represents one company + brand assignment.
        // MySQL allows multiple NULL values in a UNIQUE index, so legacy
        // location-level rows without an operational unit remain valid.
        $hasOperationalUnitUnique = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'location_business_entities')
            ->where('index_name', 'location_business_entities_operational_unit_id_unique')
            ->exists();

        if (! $hasOperationalUnitUnique) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->unique('operational_unit_id');
            });
        }
    }

    public function down(): void
    {
        $hasOperationalUnitUnique = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'location_business_entities')
            ->where('index_name', 'location_business_entities_operational_unit_id_unique')
            ->exists();

        if ($hasOperationalUnitUnique) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropUnique([
                    'operational_unit_id',
                ]);
            });
        }

        $hasBrandForeignKey = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'location_business_entities')
            ->where('constraint_name', 'location_business_entities_brand_id_foreign')
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();

        if ($hasBrandForeignKey) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropForeign(['brand_id']);
            });
        }

        if (Schema::hasColumn('location_business_entities', 'brand_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropColumn('brand_id');
            });
        }

        $hasLegacyUnique = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'location_business_entities')
            ->where('index_name', 'location_business_entities_location_id_business_entity_id_unique')
            ->exists();

        if (! $hasLegacyUnique) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->unique([
                    'location_id',
                    'business_entity_id',
                ]);
            });
        }
    }
};
