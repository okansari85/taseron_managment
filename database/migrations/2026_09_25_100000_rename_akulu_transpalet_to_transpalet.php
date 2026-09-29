<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Periyodik ekipman kataloğu: raporların konusu "Transpalet"; elektrikli / manuel ayrımı raporda ayrı bir özellik (Tahrik Türü).
// Aynı kayıt yeniden adlandırılır (id değişmez, bağlı ekipmanlar etkilenmez); seeder da yeni slug ile bu satırı günceller.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('periodic_equipment_types')->where('slug', 'transpalet')->exists()) {
            return;
        }
        DB::table('periodic_equipment_types')->where('slug', 'akulu-transpalet')
            ->update(['slug' => 'transpalet', 'name' => 'Transpalet', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('periodic_equipment_types')->where('slug', 'transpalet')
            ->update(['slug' => 'akulu-transpalet', 'name' => 'Elektrikli Transpalet', 'updated_at' => now()]);
    }
};
