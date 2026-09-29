<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tesisat kontrolü "rapor bekleniyor": firma kontrolü yaptı, rapor henüz gelmedi (dosyasız kayıt). Tesisata bağlı
 * ekipmanlarda (dolap, pompa…) ekipman bazında rapor bekleniyor yapılmaz; tesisat düzeyinde tek kayıt.
 * Mevcut değerler aynen kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE pk_installation_reports MODIFY status ENUM('uygun', 'uygun_degil', 'rapor_bekleniyor') NOT NULL");
        DB::statement('ALTER TABLE pk_installation_reports MODIFY report_file VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::table('pk_installation_reports')->where('status', 'rapor_bekleniyor')->delete();
        DB::statement("ALTER TABLE pk_installation_reports MODIFY status ENUM('uygun', 'uygun_degil') NOT NULL");
        DB::statement('ALTER TABLE pk_installation_reports MODIFY report_file VARCHAR(255) NOT NULL');
    }
};
