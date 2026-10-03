<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tüp kontrol formunda Ekipmanlar'daki bir tüple eşleşmeyen satırlar ("eşleştirme bekliyor"): form kaydedilirken tüp
 * eklenmez, satır formla saklanır; kullanıcı sonradan mevcut bir tüple eşleştirir, yeni tüp olarak ekler ya da yok sayar
 * (satır silinir). Form silinince birlikte silinir. Mevcut tablolara dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_bulk_report_pending_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pk_bulk_report_id');
            // {place, code, variant, type_text, capacity, fill_date, brand, model, serial_no, status, findings[], properties{}}
            $table->json('row');
            $table->timestamps();

            $table->index('pk_bulk_report_id', 'pbrpr_report_idx');
            $table->foreign('pk_bulk_report_id', 'pbrpr_report_fk')->references('id')->on('pk_bulk_reports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_bulk_report_pending_rows');
    }
};
