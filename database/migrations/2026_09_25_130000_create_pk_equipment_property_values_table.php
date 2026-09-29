<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ekipmanın teknik özellikleri: her raporun okuduğu değer (ve elle düzeltmeler) ayrı kayıt; hiçbiri silinmez/ezilmez.
// Güncel değer bu kayıtlardan hesaplanır (kabul edilmiş, boş olmayan, en yeni).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_equipment_property_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('pk_equipment_id')->constrained('pk_equipment')->cascadeOnDelete();
            $table->foreignId('pk_inspection_id')->nullable()->constrained('pk_inspections')->nullOnDelete();
            $table->string('field');
            $table->string('field_key', 191);
            $table->text('value');
            $table->boolean('is_empty')->default(false);
            $table->boolean('accepted')->default(true);
            $table->enum('source', ['report', 'manual']);
            $table->string('report_no')->nullable();
            $table->dateTime('effective_at');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['pk_equipment_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_equipment_property_values');
    }
};
