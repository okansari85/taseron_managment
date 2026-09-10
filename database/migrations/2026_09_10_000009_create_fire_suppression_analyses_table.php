<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FireSuppressionAnalysisProgress'in kalıcı (Cache yerine DB) durum deposu.
// Bilerek tenant_id İÇERMİYOR — analysis_id zaten tahmin edilemez bir UUID
// olduğu için doğal bir izolasyon sağlıyor; bu, kapsamı sadece "Cache'i
// DB ile değiştir" ile sınırlı tutmak için bilinçli bir karar (onaylandı).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_suppression_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('analysis_id')->unique();
            $table->string('status')->default('running');
            $table->string('current_stage')->nullable();
            $table->string('current_label')->nullable();
            $table->unsignedInteger('current_page')->nullable();
            $table->unsignedInteger('total_pages')->nullable();
            // started_at/finished_at bilerek STRING (ISO8601) — eski
            // Cache tabanlı sürüm de bunları Carbon değil, halihazırda
            // formatlanmış string olarak tutuyordu; payload'ı birebir
            // korumak için aynı temsil kullanılıyor.
            $table->string('started_at')->nullable();
            $table->string('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->json('result')->nullable();
            $table->json('events')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_suppression_analyses');
    }
};
