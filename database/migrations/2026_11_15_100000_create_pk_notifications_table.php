<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip panel bildirimleri (2026-11-15): pk:notifications-generate her gün üretir (kontrol tarihi yaklaşan / geçen, bulgu
// termini). Aynı bildirim aynı kullanıcıya ikinci kez üretilmez (user_id + dedupe_key tekil).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('user_id');
            $table->string('type', 40);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->json('payload')->nullable();
            $table->string('dedupe_key', 150);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_notifications');
    }
};
