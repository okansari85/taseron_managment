<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pktakip analiz geçmişi: yapay zeka ile yapılan her rapor okuması bir satır (ekipmana rapor, rapordan ekipman tanımlama,
 * tesisat raporu). Kaydedilmese de, okunamasa da kalır; ileride kontür bu satırlardan düşülür. Test verisiyle
 * (fikstür) yapılan okumalar ve elle giriş yazılmaz (yapay zeka çağrılmaz).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_report_analyses', function (Blueprint $table) {
            $table->id();
            // Okumanın kimliği (önbellekteki analiz ve kayıt isteğindeki analysis_id ile aynı).
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            // equipment: ekipmana rapor yükleme | equipment_new: rapordan ekipman tanımlama | installation: tesisat raporu.
            $table->string('kind', 20);
            // read: okundu, kaydedilmedi | saved: kayda dönüştü | failed: okunamadı.
            $table->string('status', 10)->default('read');
            // Kaydedildiği yer (kayıt silinirse boşalır, satır kalır).
            $table->foreignId('pk_equipment_id')->nullable()->constrained('pk_equipment')->nullOnDelete();
            $table->foreignId('pk_inspection_id')->nullable()->constrained('pk_inspections')->nullOnDelete();
            $table->foreignId('pk_installation_id')->nullable()->constrained('pk_installations')->nullOnDelete();
            $table->foreignId('pk_installation_report_id')->nullable()->constrained('pk_installation_reports')->nullOnDelete();
            $table->string('file_name')->nullable();
            // Okunan PDF (private disk); kaydedildiyse kayıttaki dosyanın bağlantısı.
            $table->string('file_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            // text | ocr | image
            $table->string('input', 10)->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('model', 100)->nullable();
            $table->decimal('duration_s', 7, 1)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            // Tespit edilen ekipman / tesisat türü (ör. Forklift, Yangın Tesisatı).
            $table->string('detected_type')->nullable();
            $table->string('report_no', 100)->nullable();
            $table->date('control_date')->nullable();
            $table->string('overall_status', 20)->nullable();
            // Liste için: sistem ve bulgu sayısı, ekipman özeti ("2/3 Yangın Pompası uygun").
            $table->json('summary')->nullable();
            // Detay için: yapay zeka analizi (kriterler hariç) ve tablo adımı.
            $table->json('semantic')->nullable();
            $table->json('tables')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('saved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_report_analyses');
    }
};
