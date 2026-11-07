<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pktakip (2026-11-07): tanıtım sitesinden ücretli paket kaydı, PayTR iFrame ödemesi. Sipariş ödeme başlarken "pending"
 * yazılır; hesap yalnız PayTR'nin bildirimi (callback) başarılı gelince açılır. Para alınıp hesap açılamazsa "attention"
 * (elle çözülür). Taşeron tablolarına dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_payment_orders', function (Blueprint $table) {
            $table->id();
            // PayTR kuralı: yalnız harf + rakam, en çok 64 karakter.
            $table->string('merchant_oid', 64)->unique();
            // pending | paid | failed | attention
            $table->string('status', 20)->default('pending')->index();
            // expert | osgb | corporate
            $table->string('type', 20);
            $table->string('name');
            $table->string('organization')->nullable();
            $table->string('email');
            $table->string('phone', 30);
            $table->string('address', 500);
            $table->foreignId('pk_package_id')->nullable()->constrained('pk_packages')->nullOnDelete();
            // monthly | yearly
            $table->string('period', 10);
            $table->decimal('net_amount', 10, 2);
            $table->decimal('vat_amount', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->string('user_ip', 45);
            // Hesap silinse de sipariş (muhasebe kaydı) kalır.
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('failed_reason', 500)->nullable();
            // PayTR bildiriminin alanları (hash hariç).
            $table->json('paytr_payload')->nullable();
            $table->timestamp('agreements_accepted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_payment_orders');
    }
};
