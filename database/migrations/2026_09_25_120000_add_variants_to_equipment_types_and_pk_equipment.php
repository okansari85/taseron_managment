<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ekipman etiketi: bazı türler alt çeşitlere ayrılır (şimdilik yalnızca transpalet: Elektrikli / Manuel).
// Katalog türü seçenekleri tutar, ekipman seçilen etiketi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periodic_equipment_types', function (Blueprint $table) {
            $table->json('variants')->nullable()->after('regulation_note');
        });
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->string('variant', 50)->nullable()->after('equipment_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->dropColumn('variant');
        });
        Schema::table('periodic_equipment_types', function (Blueprint $table) {
            $table->dropColumn('variants');
        });
    }
};
