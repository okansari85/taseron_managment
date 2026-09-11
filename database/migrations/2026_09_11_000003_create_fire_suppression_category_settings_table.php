<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sistem kategorilerinin (yangin_dolabi, su_deposu, ...) TAKSONOMİSİ sabittir
// (eşleştirme/AI/rapor mantığı bu kodlara dayanır — bkz.
// FireSuppressionInventoryItem::CATEGORIES) ama her müşteride GÖRÜNEN ADI
// değişebilir ("genellikle her müşteride aynıdır ama ismi değişir" —
// section notu). Bu tablo, tenant başına kategori başına opsiyonel bir
// override (custom_label) ve o kategoriyi bu tenant için "Sistem Ekle"
// listesinde gösterip göstermeme (is_enabled) tercihini tutar. Kayıt yoksa
// varsayılan (FIRE_SUPPRESSION_CATEGORY_LABELS) kullanılır — bu yüzden her
// kategori için satır ZORUNLU değildir, sadece özelleştirilenler için satır
// açılır.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_suppression_category_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('custom_label')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_suppression_category_settings');
    }
};
