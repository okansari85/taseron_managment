<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Serbest formattaki ekipman özellikleri (rapordaki tablo başlıklarından
    // geldiği haliyle, örn. "Ölçülen Basınç", "Hortum Uzunluğu", "Dolaplar
    // Arası Mesafe") — kategoriye göre hangi özelliklerin geçerli olduğu
    // sabit değil, raporun kendi tablosuna göre değişir, bu yüzden ayrı
    // kolonlar yerine tek bir JSON alan. Nullable, mevcut kayıtları etkilemez.
    public function up(): void
    {
        Schema::table('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->json('properties')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->dropColumn('properties');
        });
    }
};
