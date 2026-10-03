<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ekipmanın ve tesisatın alanı (isteğe bağlı): alanı olmayan lokasyonda boş kalır, her şey bugünkü gibi çalışır. Alan silinince
 * boşalır. Yalnızca pktakip'in kendi iki tablosuna boş bırakılabilir birer sütun eklenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->unsignedBigInteger('pk_area_id')->nullable()->after('location_business_entity_id');
            $table->foreign('pk_area_id', 'pke_area_fk')->references('id')->on('pk_areas')->nullOnDelete();
        });
        Schema::table('pk_installations', function (Blueprint $table) {
            $table->unsignedBigInteger('pk_area_id')->nullable()->after('location_business_entity_id');
            $table->foreign('pk_area_id', 'pki_area_fk')->references('id')->on('pk_areas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pk_installations', function (Blueprint $table) {
            $table->dropForeign('pki_area_fk');
            $table->dropColumn('pk_area_id');
        });
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->dropForeign('pke_area_fk');
            $table->dropColumn('pk_area_id');
        });
    }
};
