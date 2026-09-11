<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// unit_scope kolonu 2026_09_11_000001'de eklendi ve varsayılanı 'per_unit'.
// O migration'dan ÖNCE oluşturulmuş kodsuz (code IS NULL) kayıtlar — Su
// Deposu, Sabit Boru gibi whole_unit bileşenler — bu yüzden yanlışlıkla
// 'per_unit' değeriyle kaldı. Bu durum, frontend'in kodsuz kayıtları
// unit_scope='whole_unit' şartıyla filtrelediği ekranlarda (Bileşenler
// sekmesi — bkz. systems/[category].vue) o kayıtların hiç görünmemesine ve
// dolayısıyla silinememesine yol açtı. Burada geriye dönük düzeltiyoruz.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('fire_suppression_inventory_items')
            ->whereNull('code')
            ->where('unit_scope', '!=', 'whole_unit')
            ->update(['unit_scope' => 'whole_unit']);
    }

    public function down(): void
    {
        // Geriye dönük bir veri düzeltmesi — geri alınacak bir şema
        // değişikliği yok, kasıtlı olarak no-op.
    }
};
