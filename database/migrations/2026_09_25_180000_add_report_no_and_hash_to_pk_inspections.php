<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// pktakip kontrol kaydı: rapor numarası (benzersiz; aynı rapor ikinci kez yüklenemez) ve rapor dosyasının özeti (aynı dosya).
// Mevcut kayıtlar: rapor no analizden, özet dosyadan doldurulur. Benzersizlik uygulamada kontrol edilir (eski çift kayıtlar
// silinebilsin diye veritabanında unique index yok).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_inspections', function (Blueprint $table) {
            $table->string('report_no', 100)->nullable()->after('report_file_name')->index();
            $table->string('report_hash', 64)->nullable()->after('report_no')->index();
        });

        foreach (DB::table('pk_inspections')->select('id', 'analysis', 'report_file')->get() as $row) {
            $analysis = json_decode((string) $row->analysis, true);
            $reportNo = trim((string) ($analysis['summary']['report_no'] ?? ''));
            $hash = $row->report_file && Storage::disk('local')->exists($row->report_file)
                ? hash_file('sha256', Storage::disk('local')->path($row->report_file))
                : null;
            DB::table('pk_inspections')->where('id', $row->id)->update(['report_no' => $reportNo !== '' ? mb_substr($reportNo, 0, 100) : null, 'report_hash' => $hash]);
        }
    }

    public function down(): void
    {
        Schema::table('pk_inspections', function (Blueprint $table) {
            $table->dropColumn(['report_no', 'report_hash']);
        });
    }
};
