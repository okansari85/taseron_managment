<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Periyodik ekipman kataloğu: elektrikli ve manuel transpalet tek "Transpalet" türünde toplanır
// (tahrik türü raporda ayrı bir özellik). "Manuel Transpalet"e bağlı ekipman varsa önce Transpalet'e taşınır.
return new class extends Migration
{
    public function up(): void
    {
        $manual = DB::table('periodic_equipment_types')->where('slug', 'manuel-transpalet')->first();
        $transpalet = DB::table('periodic_equipment_types')->where('slug', 'transpalet')->first();
        if (!$manual || !$transpalet) {
            return;
        }

        DB::transaction(function () use ($manual, $transpalet) {
            DB::table('pk_equipment')->where('equipment_type_id', $manual->id)->update(['equipment_type_id' => $transpalet->id, 'updated_at' => now()]);
            DB::table('periodic_equipment_types')->where('id', $manual->id)->delete();
        });
    }

    public function down(): void
    {
        $transpalet = DB::table('periodic_equipment_types')->where('slug', 'transpalet')->first();
        if (!$transpalet || DB::table('periodic_equipment_types')->where('slug', 'manuel-transpalet')->exists()) {
            return;
        }

        DB::table('periodic_equipment_types')->insert([
            'category_id' => $transpalet->category_id,
            'slug' => 'manuel-transpalet',
            'name' => 'Manuel Transpalet',
            'default_period_months' => 12,
            'default_scope' => 'workplace',
            'regulation_note' => 'Ek-III Tablo-2',
            'sort_order' => $transpalet->sort_order + 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
