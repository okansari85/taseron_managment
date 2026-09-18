<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // YSC yıllık kontrol raporundaki, tek bir cihaza değil RAPORUN
        // GENELİNE ait kriterler (örn. AKTAŞ formunun "Genel Tespit Ve
        // Değerlendirme Soruları" bölümü - 10 madde). Kod/kriter metni
        // rapordan rapora değişebilir (farklı firma, farklı yıl, farklı
        // wording) - bu yüzden sabit bir kolon seti yerine serbest
        // code/criterion çifti olarak saklanır, ama YİNE DE sorgulanabilir
        // bir tabloda (rapor metnine gömülü bir not değil).
        Schema::create('emergency_equipment_annual_control_general_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')
                ->constrained('emergency_equipment_annual_control_reports', 'id', 'eeacgi_report_foreign')
                ->cascadeOnDelete();
            $table->string('code');
            $table->string('criterion');
            // uygun | uygun_degil | uygulanamiyor | null
            $table->string('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_equipment_annual_control_general_items');
    }
};
