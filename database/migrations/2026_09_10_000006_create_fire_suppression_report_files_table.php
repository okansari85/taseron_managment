<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ana rapor PDF'i fire_suppression_reports.file_path'te kalmaya
        // devam eder (geriye dönük uyumluluk) — bu tablo SADECE ek dosyalar
        // (fotoğraf, ek belge vb.) için.
        Schema::create('fire_suppression_report_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')
                ->constrained('fire_suppression_reports', 'id', 'fsrfl_report_foreign')
                ->cascadeOnDelete();
            // fotograf | ek_belge | diger
            $table->string('file_type')->default('diger');
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('description')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_suppression_report_files');
    }
};
