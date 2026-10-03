<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapordaki ekipmanlar (elektrik iç tesisatı: panolar, kaçak akım röleleri, ölçüm noktaları): kontrolün sistem sonucuna
 * bağlı liste. Ekipmanlar'a kayıt açılmaz; yalnızca raporda okunduğu gibi sistemin içinde görünür. Sistem sonucu (ya da
 * kontrol) silinince birlikte silinir. Mevcut tablolara dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_installation_report_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pk_installation_report_system_id');
            // [{code, place, equipment_name, status, findings[], properties{}}]
            $table->json('components');
            $table->timestamps();

            $table->unique('pk_installation_report_system_id', 'pk_inst_report_comp_system_unique');
            $table->foreign('pk_installation_report_system_id', 'pk_inst_report_comp_system_fk')
                ->references('id')->on('pk_installation_report_systems')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_installation_report_components');
    }
};
