<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Yangın Söndürme Sistemleri eşleştirme profillerinin (Yangın Pompası,
    // Hidrant, Gazlı Söndürme vb.) ihtiyaç duyduğu tanımlayıcı alanlar —
    // kategoriye göre hangisinin kullanılacağı FireSuppressionMatchingProfile
    // içinde tanımlı, hepsi nullable (mevcut kayıtları etkilemez).
    public function up(): void
    {
        Schema::table('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('location_note');
            $table->string('model')->nullable()->after('brand');
            $table->string('serial_no')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['brand', 'model', 'serial_no']);
        });
    }
};
