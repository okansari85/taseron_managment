<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip kontrol kaydı: yüklenen periyodik kontrol raporunun orijinal dosya adı.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_inspections', function (Blueprint $table) {
            $table->string('report_file_name')->nullable()->after('report_file');
        });
    }

    public function down(): void
    {
        Schema::table('pk_inspections', function (Blueprint $table) {
            $table->dropColumn('report_file_name');
        });
    }
};
