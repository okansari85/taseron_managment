<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Yangın tüpleri lokasyonun tamamına kayıtlıdır (firma bazında değil): yeni tüp varsayılan olarak lokasyon geneli.
 * Tüp kontrol formundan gelen tüpler her zaman lokasyon geneli.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('periodic_equipment_types')->where('slug', 'yangin-sondurme-cihazi')->update(['default_scope' => 'location']);
    }

    public function down(): void
    {
        DB::table('periodic_equipment_types')->where('slug', 'yangin-sondurme-cihazi')->update(['default_scope' => 'workplace']);
    }
};
