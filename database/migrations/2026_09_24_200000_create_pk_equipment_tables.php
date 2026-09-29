<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip periyodik kontrole tabi ekipmanlar ve kontrol kayıtları.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            // Boşsa lokasyon geneli (bina/kampüs), doluysa o işyerine (firma @ lokasyon) ait.
            $table->foreignId('location_business_entity_id')->nullable()->constrained('location_business_entities')->nullOnDelete();
            $table->foreignId('equipment_type_id')->constrained('periodic_equipment_types');
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('place')->nullable();
            $table->json('properties')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'location_id']);
        });

        Schema::create('pk_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pk_equipment_id')->constrained('pk_equipment')->cascadeOnDelete();
            $table->date('control_date')->nullable();
            // Geçerlilik / gelecek kontrol tarihi: rapordan (yapay zeka) ya da elle girilir, hesaplanmaz.
            $table->date('next_control_date')->nullable();
            // rapor_bekleniyor: firma kontrolü yapmış, rapor sonucu henüz gelmemiş (elle girilir).
            $table->enum('status', ['uygun', 'uygun_degil', 'rapor_bekleniyor']);
            $table->enum('source', ['manual', 'ai'])->default('manual');
            $table->string('report_file')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pk_equipment_id', 'control_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_inspections');
        Schema::dropIfExists('pk_equipment');
    }
};
