<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog talepleri tablosu tesisat sistemleri için de kullanılır: tesisat raporunda geçip katalogda olmayan sistem
 * (kind=system). equipment_type_id tesisat türü, name rapordaki sistem adı; payload rapordaki durum ve bulgular
 * (onayda o raporun sonucu olarak tesisata yazılır). Mevcut özellik talepleri kind=spec olarak aynen kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periodic_equipment_spec_requests', function (Blueprint $table) {
            $table->string('kind', 20)->default('spec')->after('tenant_id')->index();
            $table->foreignId('pk_installation_report_id')->nullable()->after('pk_inspection_id')
                ->constrained('pk_installation_reports', 'id', 'pesr_installation_report_fk')->nullOnDelete();
            // Onayda eklenen ya da eşlenen katalog sistemi.
            $table->foreignId('installation_system_id')->nullable()->after('spec_id')
                ->constrained('periodic_installation_systems', 'id', 'pesr_installation_system_fk')->nullOnDelete();
            $table->json('payload')->nullable()->after('raw_value');
        });
    }

    public function down(): void
    {
        Schema::table('periodic_equipment_spec_requests', function (Blueprint $table) {
            $table->dropForeign('pesr_installation_report_fk');
            $table->dropForeign('pesr_installation_system_fk');
            $table->dropColumn(['kind', 'pk_installation_report_id', 'installation_system_id', 'payload']);
        });
    }
};
