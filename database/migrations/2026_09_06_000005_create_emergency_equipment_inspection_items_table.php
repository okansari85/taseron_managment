<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_equipment_inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_id')->constrained('emergency_equipment_inspections')->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained('emergency_equipment_type_checklist_items')->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_equipment_inspection_items');
    }
};
