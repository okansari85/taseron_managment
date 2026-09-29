<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ekipman etiketinin türe göre anlamı (örn. Forklift: "Yakıt türü", Transpalet: "Tahrik türü").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periodic_equipment_types', function (Blueprint $table) {
            $table->string('variant_label', 100)->nullable()->after('variants');
        });
    }

    public function down(): void
    {
        Schema::table('periodic_equipment_types', function (Blueprint $table) {
            $table->dropColumn('variant_label');
        });
    }
};
