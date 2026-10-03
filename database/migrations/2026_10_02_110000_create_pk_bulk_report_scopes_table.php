<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tüp kontrol formunun türü: full (tüm envanter: lokasyondaki bütün tüplerin listesi; "Eşleştirme bekliyor" ekranı son tüm
 * envanter formuna göre çalışır) ya da partial (ek form: yalnızca bazı / yeni tüpler; envanterden çıkarılacaklar listesine bir
 * şey eklemez). Kaydı olmayan form tüm envanter sayılır. Form silinince birlikte silinir. Mevcut tablolara dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_bulk_report_scopes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pk_bulk_report_id');
            $table->enum('scope', ['full', 'partial'])->default('full');
            $table->timestamps();

            $table->unique('pk_bulk_report_id', 'pbrs_report_unique');
            $table->foreign('pk_bulk_report_id', 'pbrs_report_fk')->references('id')->on('pk_bulk_reports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_bulk_report_scopes');
    }
};
