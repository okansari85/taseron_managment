<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_equipment_inspection_photos', function (Blueprint $table) {
            $table->foreignId('inspection_item_id')
                ->nullable()
                ->after('inspection_id')
                ->constrained('emergency_equipment_inspection_items', 'id', 'eei_photos_item_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emergency_equipment_inspection_photos', function (Blueprint $table) {
            $table->dropForeign('eei_photos_item_fk');
            $table->dropColumn('inspection_item_id');
        });
    }
};
