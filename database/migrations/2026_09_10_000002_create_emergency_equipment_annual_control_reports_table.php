<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_equipment_annual_control_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_business_entity_id')
                ->constrained('location_business_entities', 'id', 'eeacr_lbe_foreign')
                ->cascadeOnDelete();
            $table->date('control_date');
            $table->date('next_control_date')->nullable();
            // uygun | uygun_degil | null — raporun genel sonucu (section 5).
            $table->string('result')->nullable();
            $table->string('company_name')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users', 'id', 'eeacr_uploader_foreign')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('emergency_equipment_annual_control_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')
                ->constrained('emergency_equipment_annual_control_reports', 'id', 'eeaci_report_foreign')
                ->cascadeOnDelete();
            $table->foreignId('location_emergency_equipment_id')
                ->constrained('location_emergency_equipment', 'id', 'eeaci_equipment_foreign')
                ->cascadeOnDelete();
            // uygun | uygun_degil | null — section 10/18: cihaz bazında sonuç
            // rapor genelinden farklı olabilir.
            $table->string('result')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['report_id', 'location_emergency_equipment_id'], 'eeaci_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_equipment_annual_control_items');
        Schema::dropIfExists('emergency_equipment_annual_control_reports');
    }
};
