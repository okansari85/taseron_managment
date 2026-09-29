<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toplu kontrol formu (yangın tüpleri): tek raporda çok sayıda tüp. Rapor, genel sonucu, bölümleri (raporda yazdığı gibi;
 * sonuç ve bulgularıyla) ve genel bulguları burada; her tüpe bu raporla açılan kontrol kaydı pk_bulk_report_inspections'ta.
 * Rapor silinince o kontrol kayıtları da silinir, tüpler kalır. Analiz geçmişinde kaydedildiği form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_bulk_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            // Yeni tüplerin ait olduğu yer (boşsa lokasyon geneli).
            $table->foreignId('location_business_entity_id')->nullable()->constrained('location_business_entities')->nullOnDelete();
            $table->foreignId('equipment_type_id')->constrained('periodic_equipment_types');
            $table->date('control_date');
            $table->date('next_control_date')->nullable();
            // Raporun genel sonucu (sonuç ve kanaat); yazmıyorsa boş.
            $table->enum('status', ['uygun', 'uygun_degil'])->nullable();
            $table->enum('source', ['manual', 'ai'])->default('manual');
            $table->string('report_file');
            $table->string('report_file_name')->nullable();
            $table->string('report_no', 100)->nullable();
            $table->string('report_hash', 64)->nullable();
            $table->string('inspection_body')->nullable();
            $table->text('conclusion')->nullable();
            // Bölümler: [{name, status, findings[], equipment_count}] (raporda yazdığı gibi).
            $table->json('systems')->nullable();
            // Hiçbir bölüme bağlı olmayan genel bulgular (ikaz ve öneriler).
            $table->json('findings')->nullable();
            $table->json('analysis')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'location_id', 'control_date']);
            $table->index(['tenant_id', 'report_no']);
        });

        Schema::create('pk_bulk_report_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk_bulk_report_id')->constrained('pk_bulk_reports', 'id', 'pbri_report_fk')->cascadeOnDelete();
            $table->foreignId('pk_inspection_id')->constrained('pk_inspections', 'id', 'pbri_inspection_fk')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['pk_bulk_report_id', 'pk_inspection_id'], 'pbri_report_inspection_unique');
        });

        Schema::table('pk_report_analyses', function (Blueprint $table) {
            $table->foreignId('pk_bulk_report_id')->nullable()->after('pk_installation_report_id')
                ->constrained('pk_bulk_reports', 'id', 'pra_bulk_report_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pk_report_analyses', function (Blueprint $table) {
            $table->dropForeign('pra_bulk_report_fk');
            $table->dropColumn('pk_bulk_report_id');
        });
        Schema::dropIfExists('pk_bulk_report_inspections');
        Schema::dropIfExists('pk_bulk_reports');
    }
};
