<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip (2026-10-18): müşteri türü (corporate | individual | public). OSGB ve bireysel uzman müşteri eklerken seçer;
// "Lokasyona Firma Ata" sihirbazı soruyu türe göre sorar (kamu ve şahısta firma sorulmaz). Boş bırakılabilir:
// Taşeron ve kurumsal hesap bu sütunu kullanmaz, eski müşterilerde sihirbaz bir kez sorar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_type', 20)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('customer_type');
        });
    }
};
