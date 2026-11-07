<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip: Kullanıcı Profili sayfası (bireysel uzman). Ad, e-posta, kayıt tarihi users tablosunda kalır.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('avatar', 10)->nullable(); // erkek | kadin
            $table->string('phone', 30)->nullable();
            $table->string('profession', 20)->nullable(); // isg_uzmani | isyeri_hekimi | dsp
            $table->string('expert_class', 1)->nullable(); // A | B | C, yalnız iş güvenliği uzmanında
            $table->string('specialty', 150)->nullable();
            $table->string('employer', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_user_profiles');
    }
};
