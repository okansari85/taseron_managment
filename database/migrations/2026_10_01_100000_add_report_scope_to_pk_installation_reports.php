<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tesisat kontrolünün türü: boş = tesisat raporu (yangın tesisatı raporu, elektrik iç tesisat raporu; tesisatın genel
 * durumu ve tarihleri bundan), 'system' = sistem raporu (dolap, pano, termal…; yalnızca kendi sistemlerini günceller).
 * Mevcut kontrollerin hepsi boş kalır, yani tesisat raporu sayılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_installation_reports', function (Blueprint $table) {
            $table->string('report_scope', 20)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('pk_installation_reports', function (Blueprint $table) {
            $table->dropColumn('report_scope');
        });
    }
};
