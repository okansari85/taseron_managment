<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nace_hazard_classes', function (Blueprint $table) {
            $table->id();
            $table->string('nace_code')->unique();
            $table->string('activity_name');
            $table->string('hazard_class'); // az_tehlikeli | tehlikeli | cok_tehlikeli
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nace_hazard_classes');
    }
};
