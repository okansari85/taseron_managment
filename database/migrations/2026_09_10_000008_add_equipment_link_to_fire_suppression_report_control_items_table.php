<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fire_suppression_report_control_items', function (Blueprint $table) {
            $table->string('equipment_code', 100)->nullable()->after('template_id');
            $table->foreignId('inventory_item_id')
                ->nullable()
                ->after('equipment_code')
                ->constrained('fire_suppression_inventory_items', 'id', 'fsrci_inventory_item_foreign')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fire_suppression_report_control_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropColumn('equipment_code');
        });
    }
};
