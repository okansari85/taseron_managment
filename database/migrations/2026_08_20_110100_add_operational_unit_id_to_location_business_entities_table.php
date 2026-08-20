<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->foreignId('operational_unit_id')
                ->nullable()
                ->after('business_entity_id')
                ->constrained('operational_units')
                ->nullOnDelete();

            $table->index([
                'location_id',
                'operational_unit_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->dropForeign(['operational_unit_id']);
            $table->dropIndex([
                'location_id',
                'operational_unit_id',
            ]);
            $table->dropColumn('operational_unit_id');
        });
    }
};
