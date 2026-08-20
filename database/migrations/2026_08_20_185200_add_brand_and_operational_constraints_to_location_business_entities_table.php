<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->foreignId('brand_id')
                ->after('business_entity_id')
                ->constrained('brands')
                ->restrictOnDelete();

            $table->dropUnique([
                'location_id',
                'business_entity_id',
            ]);

            // An operational unit can represent only one company + brand assignment.
            // NULL remains allowed for location-level assignments without an operational unit.
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
