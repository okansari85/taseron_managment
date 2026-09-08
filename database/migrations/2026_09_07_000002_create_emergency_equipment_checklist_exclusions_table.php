<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_equipment_checklist_exclusions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('checklist_item_id');
            $table->foreign('checklist_item_id', 'eece_checklist_item_id_fk')
                ->references('id')->on('emergency_equipment_type_checklist_items')
                ->cascadeOnDelete();

            $table->foreignId('equipment_type_id');
            $table->foreign('equipment_type_id', 'eece_equipment_type_id_fk')
                ->references('id')->on('emergency_equipment_types')
                ->cascadeOnDelete();

            $table->unique(['checklist_item_id', 'equipment_type_id'], 'eece_item_type_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_equipment_checklist_exclusions');
    }
};
