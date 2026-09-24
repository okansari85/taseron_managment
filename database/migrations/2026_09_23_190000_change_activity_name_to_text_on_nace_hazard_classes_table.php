<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tebliğ Ek-1 listesindeki bazı faaliyet tanımları 255 karakteri aşıyor.
    public function up(): void
    {
        Schema::table('nace_hazard_classes', function (Blueprint $table) {
            $table->text('activity_name')->change();
        });
    }

    public function down(): void
    {
        Schema::table('nace_hazard_classes', function (Blueprint $table) {
            $table->string('activity_name')->change();
        });
    }
};
