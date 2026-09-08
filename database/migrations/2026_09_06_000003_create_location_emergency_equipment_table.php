<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_emergency_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_business_entity_id')->constrained('location_business_entities')->cascadeOnDelete();
            $table->foreignId('equipment_type_id')->constrained('emergency_equipment_types')->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('location_note')->nullable();
            $table->date('install_date')->nullable();
            $table->string('status')->default('active'); // active | inactive | needs_replacement
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_emergency_equipment');
    }
};
