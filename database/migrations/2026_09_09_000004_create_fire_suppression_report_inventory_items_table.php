<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Rapor ↔ Envanter periyodik kontrol ilişkisi (section 6/10): bir rapor
    // hangi envanter kalemlerini kontrol ettiyse burada işaretlenir. Envanter
    // kalemlerinin son/sonraki kontrol tarihleri BUNDAN türetilir — mevcut
    // FireSuppressionInventoryItem CRUD'una dokunulmadan ayrı bir ilişki
    // katmanı olarak eklendi.
    public function up(): void
    {
        Schema::create('fire_suppression_report_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')
                ->constrained('fire_suppression_reports', 'id', 'fsrii_report_foreign')
                ->cascadeOnDelete();
            $table->foreignId('inventory_item_id')
                ->constrained('fire_suppression_inventory_items', 'id', 'fsrii_item_foreign')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['report_id', 'inventory_item_id'], 'fsrii_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_suppression_report_inventory_items');
    }
};
