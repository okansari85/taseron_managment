<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 4 Yıllık Dolum sürecinin ikinci ayağı — last_fill_date zaten vardı,
    // "Sonraki Dolum" bilgisi eksikti (section 14). Yeni bir belge/rapor
    // zorunluluğu getirmiyoruz, sadece tarih çifti tamamlanıyor.
    public function up(): void
    {
        Schema::table('location_emergency_equipment', function (Blueprint $table) {
            $table->date('next_fill_date')->nullable()->after('last_fill_date');
        });
    }

    public function down(): void
    {
        Schema::table('location_emergency_equipment', function (Blueprint $table) {
            $table->dropColumn('next_fill_date');
        });
    }
};
