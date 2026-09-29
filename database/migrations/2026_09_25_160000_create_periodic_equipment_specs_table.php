<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ekipman teknik özellik kataloğu (tüm kullanıcılarda ortak): tür başına sabit özellik adları ve birimleri.
// equipment_type_id boş = her türde ortak (marka, model, seri no...). aliases: raporlarda geçen diğer adlar.
// Rapordan okunan değerlerin rapordaki orijinal başlığı / değeri de özellik kayıtlarında saklanır.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodic_equipment_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_type_id')->nullable()->constrained('periodic_equipment_types')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('name');
            $table->string('unit', 30)->nullable();
            $table->json('aliases')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['equipment_type_id', 'key']);
        });

        Schema::table('pk_equipment_property_values', function (Blueprint $table) {
            $table->string('raw_field')->nullable()->after('value');
            $table->text('raw_value')->nullable()->after('raw_field');
        });
    }

    public function down(): void
    {
        Schema::table('pk_equipment_property_values', function (Blueprint $table) {
            $table->dropColumn(['raw_field', 'raw_value']);
        });
        Schema::dropIfExists('periodic_equipment_specs');
    }
};
