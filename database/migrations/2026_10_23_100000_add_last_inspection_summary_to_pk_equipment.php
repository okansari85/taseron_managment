<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ekipmanın son kontrol özeti ekipman satırında (PkInspectionObserver güncel tutar): genel bakış ve ekipman listesi kontrol
 * tablosuna alt sorgu atmadan okur; listede bulgu sayısı ve bulgular gösterilir. Mevcut kayıtlar burada doldurulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->foreignId('last_inspection_id')->nullable()->after('equipment_type_id')->constrained('pk_inspections')->nullOnDelete();
            $table->date('last_control_date')->nullable()->after('last_inspection_id');
            $table->date('next_control_date')->nullable()->after('last_control_date');
            $table->string('last_status', 32)->nullable()->after('next_control_date');
            $table->unsignedSmallInteger('last_findings_count')->default(0)->after('last_status');
            $table->json('last_findings')->nullable()->after('last_findings_count');
            $table->index(['tenant_id', 'next_control_date']);
        });

        // Mevcut ekipman: son kontrol = kontrol tarihi en yeni (aynı günse en son girilen), PkEquipment::latestInspection ile aynı.
        DB::table('pk_inspections')->orderBy('pk_equipment_id')->orderByDesc('control_date')->orderByDesc('id')
            ->select(['id', 'pk_equipment_id', 'control_date', 'next_control_date', 'status', 'findings'])
            ->chunk(1000, function ($rows) {
                static $done = [];
                foreach ($rows as $row) {
                    if (isset($done[$row->pk_equipment_id])) {
                        continue;
                    }
                    $done[$row->pk_equipment_id] = true;
                    $findings = array_values(array_filter(array_map(fn ($f) => trim(is_array($f) ? (string) ($f['description'] ?? '') : (string) $f), json_decode($row->findings ?? '[]', true) ?: [])));
                    DB::table('pk_equipment')->where('id', $row->pk_equipment_id)->update([
                        'last_inspection_id' => $row->id,
                        'last_control_date' => $row->control_date,
                        'next_control_date' => $row->next_control_date,
                        'last_status' => $row->status,
                        'last_findings_count' => count($findings),
                        'last_findings' => json_encode($findings, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'next_control_date']);
            $table->dropConstrainedForeignId('last_inspection_id');
            $table->dropColumn(['last_control_date', 'next_control_date', 'last_status', 'last_findings_count', 'last_findings']);
        });
    }
};
