<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_experts', function (Blueprint $table): void {
            // The FK must be dropped before the unique index, because the
            // composite unique(location_id, user_id) index is the only index
            // backing that foreign key (InnoDB requires one to exist).
            $table->dropForeign(['location_id']);
            $table->dropUnique(['location_id', 'user_id']);
            $table->dropColumn('location_id');

            $table->foreignId('location_business_entity_id')
                ->after('id')
                ->constrained('location_business_entities')
                ->cascadeOnDelete();

            $table->unique(['location_business_entity_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('location_experts', function (Blueprint $table): void {
            $table->dropForeign(['location_business_entity_id']);
            $table->dropUnique(['location_business_entity_id', 'user_id']);
            $table->dropColumn('location_business_entity_id');

            $table->foreignId('location_id')
                ->after('id')
                ->constrained('locations')
                ->cascadeOnDelete();

            $table->unique(['location_id', 'user_id']);
        });
    }
};
