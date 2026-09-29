<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrol kaydı durumu "belirtilmemiş": ekipman raporda var (kontrol edildi, rapor tarihi / geçerliliği var) ama
 * uygunluğu yazmıyor (ör. tesisat raporunda kriteri ve bulgusu olmayan yangın dolabı). "Rapor yok"tan farklı.
 * Mevcut değerler aynen kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE pk_inspections MODIFY status ENUM('uygun', 'uygun_degil', 'rapor_bekleniyor', 'belirtilmemis') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pk_inspections MODIFY status ENUM('uygun', 'uygun_degil', 'rapor_bekleniyor') NOT NULL");
    }
};
