<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip genel ayarları (örn. rapor okuyan yapay zeka: sağlayıcı, modeller, şifreli API anahtarları).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_settings');
    }
};
