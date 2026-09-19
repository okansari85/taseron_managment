<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // YSC yıllık kontrol raporunda TEK BİR EKİPMANA (tüpe) ait kriterler
        // (örn. AKTAŞ formunun her tüp için tekrarlayan 7 sütunu - mühür,
        // basınç göstergesi, fiziki durum vb.). emergency_equipment_annual_
        // control_general_items'ın (rapor GENELİNE ait maddeler) birebir
        // eşi, sadece ekipmana bağlı - mevcut equipment pivot'unun (result/
        // note) tek bir aggregate değeri, artık ekipman başına BİRDEN FAZLA
        // gerçek kriter olduğu için yetersiz kalıyordu.
        Schema::create('emergency_equipment_annual_control_equipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')
                ->constrained('emergency_equipment_annual_control_reports', 'id', 'eeaceqi_report_foreign')
                ->cascadeOnDelete();
            $table->foreignId('location_emergency_equipment_id')
                ->constrained('location_emergency_equipment', 'id', 'eeaceqi_equipment_foreign')
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
        Schema::dropIfExists('emergency_equipment_annual_control_equipment_items');
    }
};
