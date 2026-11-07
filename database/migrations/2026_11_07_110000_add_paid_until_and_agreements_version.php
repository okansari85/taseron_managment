<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pktakip (2026-11-07): PayTR ile ödenen paket dönem sonunda durur (kullanıcı: "ödeme yapmazsa paket dursun").
 * pk_subscriptions.paid_until: ödenen dönemin sonu; doluysa bu günden sonraki aylar için kredi yüklenmez. Boşsa (süper admin
 * ataması, eski hesaplar) bugünkü gibi süresiz. pk_payment_orders.agreements_version: onaylanan sözleşme metninin sürümü.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_subscriptions', function (Blueprint $table) {
            $table->date('paid_until')->nullable()->after('resets_at');
        });
        Schema::table('pk_payment_orders', function (Blueprint $table) {
            $table->string('agreements_version', 20)->nullable()->after('agreements_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('pk_payment_orders', function (Blueprint $table) {
            $table->dropColumn('agreements_version');
        });
        Schema::table('pk_subscriptions', function (Blueprint $table) {
            $table->dropColumn('paid_until');
        });
    }
};
