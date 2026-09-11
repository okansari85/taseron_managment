<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Sistem Bileşeni" modeli: bir bileşen ya TEK TEK sayılan bir birimdir
// (Yangın Dolabı, Hidrant — her biri kendi kodu/matrisiyle) ya da BÜTÜN
// olarak değerlendirilen bir sistemdir (Pompa Dairesi, Su Deposu, Sabit Boru
// Tesisatı — kendi içinde ayrı ayrı sayılmaz, oda/sistem genelinde tek bir
// checklist ile değerlendirilir). Mevcut şema hiçbir zaman bunu ayırt
// etmiyordu. Ek olarak whole_unit bileşenler için müşterinin kendi verdiği
// bir görünen ad (display_name) gerekiyor — "code" alanı per_unit bileşenler
// için (YD-14 gibi) kullanılmaya devam ediyor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->string('unit_scope')->default('per_unit')->after('category');
            $table->string('display_name')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['unit_scope', 'display_name']);
        });
    }
};
