<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip periyodik kontrol ekipman kataloğu: tüm kullanıcılarda ortak (tenant'a bağlı değil).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodic_equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            // Ekipman ve tesisat yazılımda ayrı değerlendirilir.
            $table->enum('kind', ['equipment', 'installation'])->default('equipment');
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('periodic_equipment_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('periodic_equipment_categories')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            // Ek-III'teki azami periyot (ay); null = ilgili standarda/üretici talimatına göre.
            $table->unsignedSmallInteger('default_period_months')->nullable();
            // Ekipman eklerken önerilecek kapsam: lokasyon geneli ya da işyerine özel.
            $table->enum('default_scope', ['location', 'workplace'])->default('workplace');
            $table->string('regulation_note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodic_equipment_types');
        Schema::dropIfExists('periodic_equipment_categories');
    }
};
