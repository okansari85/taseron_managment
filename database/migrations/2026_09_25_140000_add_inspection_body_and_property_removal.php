<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip: kontrol kaydında periyodik kontrolü yapan kuruluş; teknik özellik silme (kayıt olarak, geçmiş korunur).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_inspections', function (Blueprint $table) {
            $table->string('inspection_body')->nullable()->after('note');
        });
        Schema::table('pk_equipment_property_values', function (Blueprint $table) {
            $table->boolean('is_removed')->default(false)->after('accepted');
        });
    }

    public function down(): void
    {
        Schema::table('pk_equipment_property_values', function (Blueprint $table) {
            $table->dropColumn('is_removed');
        });
        Schema::table('pk_inspections', function (Blueprint $table) {
            $table->dropColumn('inspection_body');
        });
    }
};
