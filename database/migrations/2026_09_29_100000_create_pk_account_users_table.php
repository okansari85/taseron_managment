<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PKTakip OSGB / kurumsal hesap kullanıcıları: pktakip rolü (yonetici | operasyon | uzman) ve pasiflik. Bireysel uzman
// hesaplarında kayıt yok (tek kullanıcı, bugünkü gibi).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_account_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_account_users');
    }
};
