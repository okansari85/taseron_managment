<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ekipman profil sayfasındaki fotoğraf (public disk).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
