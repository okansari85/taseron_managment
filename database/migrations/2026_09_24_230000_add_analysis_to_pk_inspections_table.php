<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip kontrol kaydı: yapay zeka rapor analizinin tamamı (kriterler hariç) + ekipman eşleşme sonucu.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_inspections', function (Blueprint $table) {
            $table->json('analysis')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('pk_inspections', function (Blueprint $table) {
            $table->dropColumn('analysis');
        });
    }
};
