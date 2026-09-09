<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kategori bazlı standart kontrol maddesi referans listesi — tenant'a
        // bağlı değil (tüm tenant'lar aynı standart checklist'ten başlar),
        // her rapor oluşturulurken bu şablon kopyalanıp
        // fire_suppression_report_control_items'a yazılır (rapor sonradan
        // düzenlense de şablon değişmemiş olur).
        Schema::create('fire_suppression_control_item_templates', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('code', 20)->nullable();
            $table->string('section')->nullable();
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_suppression_control_item_templates');
    }
};
