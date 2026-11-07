<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pktakip (2026-11-07): uygulama içinden paket satın alma. Hesap başına fatura bilgisi (ödemeyi yapan yazar). Adres ile fatura
 * adresi ayrı saklanır (kullanıcı istedi). pk_payment_orders: ödeyen kullanıcı ve fatura bilgisinin sipariş anındaki kopyası.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            // bireysel | kurumsal
            $table->string('invoice_type', 10);
            // Ad soyad (bireysel) ya da unvan (kurumsal).
            $table->string('name');
            $table->string('tckn', 11)->nullable();
            $table->string('tax_number', 10)->nullable();
            $table->string('tax_office', 100)->nullable();
            $table->string('phone', 30);
            $table->string('address', 500);
            $table->string('billing_address', 500);
            $table->timestamps();
        });
        Schema::table('pk_payment_orders', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->json('billing')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('pk_payment_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('billing');
        });
        Schema::dropIfExists('pk_billing_profiles');
    }
};
