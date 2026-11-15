<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip kontrol planlama (2026-11-15): bir lokasyonda (isteğe bağlı işyeri, ekipman türü, seçili ekipmanlar ya da tesisat)
// planlanan kontrol günü. workplace_id: location_business_entities.id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_control_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('location_id')->index();
            $table->foreignId('workplace_id')->nullable();
            $table->foreignId('equipment_type_id')->nullable();
            $table->json('equipment_ids')->nullable();
            $table->foreignId('installation_id')->nullable();
            $table->date('date');
            $table->string('title')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_control_plans');
    }
};
