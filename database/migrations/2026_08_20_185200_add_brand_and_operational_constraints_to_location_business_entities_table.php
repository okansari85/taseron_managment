<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing location-business records are disposable legacy data at this stage.
        // Clear them before applying the new brand relationship so the new required FK
        // can be introduced without orphaned/invalid brand references.
        DB::table('location_business_entities')->delete();

        if (! Schema::hasColumn('location_business_entities', 'brand_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->foreignId('brand_id')
                    ->after('business_entity_id');
            });
        }

        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->dropUnique([
                'location_id',
                'business_entity_id',
            ]);
        });

        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->foreign('brand_id')
                ->references('id')
                ->on('brands')
                ->restrictOnDelete();
        });

        // One operational unit represents exactly one company + brand assignment.
        // A NULL operational_unit_id means the company + brand applies to the whole location.
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->unique('operational_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->dropUnique(['operational_unit_id']);
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
            $table->unique([
                'location_id',
                'business_entity_id',
            ]);
        });
    }
};
