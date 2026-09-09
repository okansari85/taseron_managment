<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_suppression_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_business_entity_id')
                ->constrained('location_business_entities', 'id', 'fsr_lbe_foreign')
                ->cascadeOnDelete();
            $table->date('report_date');
            $table->date('next_control_date')->nullable();
            // Bu raporda hangi kategoriler kontrol edildi — section 15/27 "Sistemler".
            // Sabit, küçük bir enum seti olduğu için ayrı pivot tablo yerine JSON.
            $table->json('covered_categories')->nullable();
            // uygun | uygun_degil | null (raporun kendi genel sonucu — section 10:
            // sadece BU raporun tarihsel sonucudur, bugünkü envanter özetini
            // otomatik değiştirmez).
            $table->string('overall_result')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_suppression_reports');
    }
};
