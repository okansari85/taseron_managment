<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tesisat raporundan ekipmanlara yazılan kontrol kayıtları (Ekipmanlar'daki dolap, pompa…): tesisat raporu silinince
 * ondan yazılan kontrol kayıtları da silinir. Ekipman tablolarına sütun eklenmez; bağlantı burada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_installation_report_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk_installation_report_id')->constrained('pk_installation_reports', 'id', 'piri_report_fk')->cascadeOnDelete();
            $table->foreignId('pk_inspection_id')->constrained('pk_inspections', 'id', 'piri_inspection_fk')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['pk_installation_report_id', 'pk_inspection_id'], 'piri_report_inspection_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_installation_report_inspections');
    }
};
