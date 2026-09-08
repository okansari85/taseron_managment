<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_equipment_type_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_type_id');
            $table->foreign('equipment_type_id', 'eetci_equipment_type_id_fk')
                ->references('id')->on('emergency_equipment_types')
                ->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_equipment_type_checklist_items');
    }
};
